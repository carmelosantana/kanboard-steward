<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\SwitchPolicy;

/**
 * Starts and ends a user switch (spec §2). `start`: app admins only (AclCheck), behind the `switching` feature
 * (FeatureGate). `back`: any signed-in user, always available, refused unless this session is switched.
 */
class StewardSwitchController extends BaseController
{
    /** Refusals reached only after the actor passed authorisation and the target was found. */
    private const TARGET_PHASE_REASONS = ['inactive', 'self', 'target_admin', 'audit_failed', 'rememberme_failed', 'session_failed'];

    /** POST with CSRF; `user_id` comes from the form. */
    public function start(): void
    {
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $raw = (string) ($this->request->getRawValue('user_id') ?? '');
        $targetId = ctype_digit($raw) ? (int) $raw : 0;

        $reason = $this->container['stewardSwitching']->start($targetId);
        if ($reason === null) {
            // The session id changed: the old CSRF tokens died with it.
            $this->response->redirect($this->helper->url->to('DashboardController', 'show'));
            return;
        }

        // One message for every reason; the real one is in the audit log only. Back to the profile only when the
        // refusal came after authorisation, so the redirect never tells an unauthorised actor whether a user exists.
        $this->flash->failure(t(SwitchPolicy::UNIFORM_MESSAGE));
        if (in_array($reason, self::TARGET_PHASE_REASONS, true)) {
            $this->response->redirect($this->helper->url->to('UserViewController', 'show', ['user_id' => $targetId]));
        } else {
            $this->response->redirect($this->helper->url->to('UserListController', 'show'));
        }
    }

    /**
     * Switch back = log out and sign in again (Decision 21): ends the switch and sends the browser to the login
     * page; after signing in the admin lands on the target's profile. POST with CSRF.
     */
    public function back(): void
    {
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();
        $switching = $this->container['stewardSwitching'];
        if (! $switching->isSwitched()) {
            throw new AccessForbiddenException();
        }

        $switching->end('end');
        $this->response->redirect($this->helper->url->to('AuthController', 'login'));
    }
}
