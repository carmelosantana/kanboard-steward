<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\Redactor;
use Throwable;

/**
 * Settings → Email and Settings → Steward: "Send test email" (spec §6, Decisions 10 and 19). Sends synchronously
 * through the transport core's EmailJob would use, to the signed-in admin's own address only.
 */
class StewardTestEmailController extends BaseController
{
    public const COOLDOWN = 30;
    public const METADATA_KEY = 'steward_test_email_at';
    public const SUBJECT = 'Kanboard test email';

    public function send(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        // Only the token is read: any posted recipient field is never looked at (Decision 10).
        $this->checkCSRFForm();

        $userId = (int) $this->userSession->getId();
        $user = $this->userModel->getById($userId);
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            $this->flash->failure(t('Add an email address to your profile first'));
            $this->redirectBack();
            return;
        }

        $last = (int) $this->userMetadataModel->get($userId, self::METADATA_KEY, 0);
        if (time() - $last < self::COOLDOWN) {
            $this->flash->failure(t('Please wait a few seconds'));
            $this->redirectBack();
            return;
        }
        // Before sending, so a slow or failed send still counts.
        $this->userMetadataModel->save($userId, [self::METADATA_KEY => (string) time()]);

        $transport = (string) $this->helper->mail->getMailTransport();
        $ref = bin2hex(random_bytes(4));
        $ok = true;
        try {
            $this->emailClient->getTransport($transport)->sendEmail(
                $email,
                (string) ($user['name'] ?: $user['username']),
                self::SUBJECT,
                $this->body(),
                $this->emailClient->getAuthorName(),
                $this->emailClient->getAuthorEmail()
            );
        } catch (Throwable $e) {
            $ok = false;
            $this->logger->error(sprintf('Steward test email ref %s via %s failed: %s', $ref, $transport, self::redact($e)));
        }

        $this->container['stewardAudit']->logInternal('email.test', $userId, null, ['transport' => $transport, 'ok' => $ok, 'ref' => $ref]);

        if ($ok) {
            // Core's SMTP and mail transports log failures instead of throwing, so "handed to" is all we know.
            $this->flash->success(t('Handed to %s without error — check your inbox', $transport));
        } else {
            $this->flash->failure(t('The test email failed (ref %s)', $ref));
        }
        $this->redirectBack();
    }

    /** The exception chain's messages, credentials (and the configured SMTP login) redacted. */
    private static function redact(Throwable $e): string
    {
        $redactor = new Redactor([MAIL_SMTP_USERNAME, MAIL_SMTP_PASSWORD]);
        $parts = [];
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $parts[] = get_class($t).': '.$redactor->redact($t->getMessage());
        }
        return implode(' <- ', $parts);
    }

    private function body(): string
    {
        $branding = $this->container['stewardBranding'];
        $site = $this->container['stewardFeatures']->isEnabled('branding') && $branding->siteName() !== ''
            ? $branding->siteName()
            : 'Kanboard';
        $url = (string) $this->configModel->get('application_url', '');
        $text = $this->helper->text;

        return '<p>'.$text->e(t('This is a test email from %s.', $site)).'</p>'
            .($url !== '' ? '<p>'.$text->e($url).'</p>' : '')
            .'<p>'.$text->e(t('Sent at %s.', $this->helper->dt->datetime(time()))).'</p>';
    }

    /** Back to core's Settings → Email when the button there asked for it, else Settings → Steward. */
    private function redirectBack(): void
    {
        $url = $this->request->getStringParam('redirect') === 'email'
            ? $this->helper->url->to('ConfigController', 'email')
            : $this->helper->url->to('StewardSettingsController', 'show', ['plugin' => 'Steward']);
        $this->response->redirect($url);
    }

    /** Repeats FeatureGate's check (spec §1, defence in depth): with the feature off, the gate's fixed 404. */
    private function featureOn(): bool
    {
        if ($this->container['stewardFeatures']->isEnabled('test_email')) {
            return true;
        }
        $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
        return false;
    }
}
