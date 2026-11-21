<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Throwable;

/**
 * One-time sign-in links (Steward 1.1), all behind the `login_links` feature (FeatureGate, and each action checks it
 * again itself). `create`: app admins (AclCheck), POST with CSRF, shows the link once. `confirm` (GET) and `redeem`
 * (POST with CSRF): public; GET never consumes a link, so a chat preview or mail scanner can't use it up.
 */
class StewardLoginLinkController extends BaseController
{
    public const INVALID_MESSAGE = 'This sign-in link is invalid or has expired.';
    public const SIGNED_IN_MESSAGE = "You're signed in. Sign out first, then open the link again.";
    public const MINT_REFUSED_MESSAGE = "You can't create a sign-in link for this user";
    public const NO_APP_URL_MESSAGE = 'Set the Application URL in Settings before creating sign-in links';

    /** mint() refusals reached only after the actor passed authorisation and the target was found. */
    private const TARGET_PHASE_REASONS = [
        'inactive', 'self', 'target_admin', 'external_auth', 'login_form_disabled', 'locked',
        'no_app_url', 'rate_limited', 'store_failed', 'audit_failed',
    ];

    /** POST with CSRF; `user_id` comes from the form. Success renders the link, the only time it is shown. */
    public function create(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        // The route's user_id only makes the URL pretty; the form's user_id is the one used.
        $raw = $this->request->getRawValue('user_id');
        $targetId = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;

        try {
            $result = $this->container['stewardLoginLinks']->mint($targetId);
        } catch (Throwable $e) {
            $this->logger->error('Steward: creating a sign-in link failed: '.$e->getMessage());
            // Not known to be past authorisation: answer as for an unknown user.
            $result = ['ok' => false, 'reason' => 'error'];
        }

        if ($result['ok']) {
            try {
                $page = $this->helper->layout->app('Steward:login_link/shown', [
                    'title' => t('Sign-in link'),
                    'steward_link_url' => $result['url'],
                    'steward_link_expires_at' => $result['expires_at'],
                    'steward_link_user' => $this->userModel->getById($targetId) ?: [],
                ]);
                $this->response->withHeader('Cache-Control', 'no-store');
                $this->response->html($page);
                return;
            } catch (Throwable $e) {
                // The link exists but was never shown; minting again cancels it.
                $this->logger->error('Steward: showing a sign-in link failed: '.$e->getMessage());
                $result = ['ok' => false, 'reason' => 'error'];
            }
        }

        // One message for every reason but the fixable one; the real reason is in the audit log only. Back to the
        // profile only when the refusal came after authorisation, so the redirect never tells an unauthorised actor
        // whether a user exists.
        $reason = $result['reason'];
        $this->flash->failure($reason === 'no_app_url' ? t(self::NO_APP_URL_MESSAGE) : t(self::MINT_REFUSED_MESSAGE));
        if (in_array($reason, self::TARGET_PHASE_REASONS, true)) {
            $this->response->redirect($this->helper->url->to('UserViewController', 'show', ['user_id' => $targetId]));
        } else {
            $this->response->redirect($this->helper->url->to('UserListController', 'show'));
        }
    }

    /** GET: "Sign in to <site> as <username>" and a button that posts to redeem(). Changes nothing. */
    public function confirm(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $this->privateHeaders();
        $token = $this->token();
        $links = $this->container['stewardLoginLinks'];

        try {
            if ($links->signedIn()) {
                $this->signedInPage();
                return;
            }
            $user = $links->peek($token);
        } catch (Throwable $e) {
            $this->logger->error('Steward: checking a sign-in link failed: '.$e->getMessage());
            $user = null;
        }
        if ($user === null) {
            $this->invalidPage();
            return;
        }

        $this->response->html($this->helper->layout->app('Steward:login_link/confirm', [
            'title' => t('Sign in'),
            'no_layout' => true,
            'steward_site_name' => $this->siteName(),
            'steward_brand' => $this->brand(),
            'steward_link_user' => $user,
            'steward_link_token' => $token,
        ]));
    }

    /** POST with CSRF. Success goes to the dashboard (core's PostAuthentication asks a 2FA user for the code first). */
    public function redeem(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        // Before the checks, so core's 403 page (which keeps the token in its URL) carries them too.
        $this->privateHeaders();
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        try {
            $reason = $this->container['stewardLoginLinks']->redeem($this->token());
        } catch (Throwable $e) {
            $this->logger->error('Steward: redeeming a sign-in link failed: '.$e->getMessage());
            $reason = 'error';
        }

        if ($reason === null) {
            // The session id changed: the old CSRF tokens died with it.
            $this->response->redirect($this->helper->url->to('DashboardController', 'show'));
        } elseif ($reason === 'signed_in') {
            $this->signedInPage();
        } else {
            $this->invalidPage();
        }
    }

    /** The route's token as sent; redeem()/peek() reject anything that isn't exactly 64 lower-case hex. */
    private function token(): string
    {
        $token = $this->request->getStringParam('token');
        return is_string($token) ? $token : '';
    }

    /** The URL holds the token: never sent on as a referrer, never kept in a cache. */
    private function privateHeaders(): void
    {
        $this->response->withHeader('Referrer-Policy', 'no-referrer');
        $this->response->withHeader('Cache-Control', 'no-store');
    }

    /** The same page, byte for byte, whatever was wrong with the link. */
    private function invalidPage(): void
    {
        $this->messagePage(t(self::INVALID_MESSAGE), 404);
    }

    private function signedInPage(): void
    {
        $this->messagePage(t(self::SIGNED_IN_MESSAGE), 200);
    }

    private function messagePage(string $message, int $status): void
    {
        $this->response->html($this->helper->layout->app('Steward:login_link/invalid', [
            'title' => t('Sign in'),
            'no_layout' => true,
            'steward_site_name' => $this->siteName(),
            'steward_brand' => $this->brand(),
            'steward_message' => $message,
        ]), $status);
    }

    /**
     * Steward's login logo params (as Plugin::applyBranding() passes them) while branding is on, else null. Rendered
     * directly: the login-form hooks would bring in other plugins' login-page extras (OAuth buttons, taglines).
     */
    private function brand(): ?array
    {
        if (! $this->container['stewardFeatures']->isEnabled('branding')) {
            return null;
        }
        $branding = $this->container['stewardBranding'];
        return ['steward_logo_url' => $branding->imageUrl('logo'), 'steward_site_name' => $branding->siteName()];
    }

    /** Steward's branded site name while branding is on, else Kanboard's. */
    private function siteName(): string
    {
        $name = $this->container['stewardFeatures']->isEnabled('branding') ? $this->container['stewardBranding']->siteName() : '';
        return $name !== '' ? $name : 'Kanboard';
    }

    /** Defence in depth: FeatureGate's fixed 404 when the feature is off. */
    private function featureOn(): bool
    {
        if ($this->container['stewardFeatures']->isEnabled('login_links')) {
            return true;
        }
        $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
        return false;
    }
}
