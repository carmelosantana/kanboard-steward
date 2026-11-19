<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\Banner;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SettingsSchema;

/** Settings → Steward → Announcement banner (admin), and any signed-in user's Dismiss (spec §7). */
class StewardBannerController extends BaseController
{
    public function show(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $this->assertAdmin();

        $this->response->html($this->helper->layout->config('Steward:config/banner', [
            'title' => t('Settings').' &gt; Steward &gt; '.t('Announcement banner'),
            'values' => $this->banner()->settings(),
            'levels' => ['info' => t('Information'), 'warning' => t('Warning')],
            'max_length' => SettingsSchema::BANNER_MESSAGE_MAX,
        ]));
    }

    public function save(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $this->assertAdmin();
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        // getValues() validates and consumes the single-use CSRF token: call it once.
        $values = $this->request->getValues();
        if ($values === []) {
            throw new AccessForbiddenException();
        }

        $result = SettingsSchema::filterBanner($values, fn (string $value) => (int) $this->dateParser->getTimestamp($value));
        if ($result['error'] !== null) {
            $this->flash->failure($result['error']);
            $this->redirectToShow();
            return;
        }

        $clean = $result['values'];
        $before = $this->banner()->settings();
        $changed = array_keys(array_diff_assoc($clean, $before));

        if (! $this->configModel->save($clean)) {
            $this->flash->failure(t('Unable to save your settings.'));
            $this->redirectToShow();
            return;
        }
        // Key names and the message length, never the text. Written after save() has closed its transaction.
        if ($changed !== []) {
            $this->container['stewardAudit']->logInternal('banner.changed', (int) $this->userSession->getId(), null, [
                'keys' => $changed,
                'message_length' => mb_strlen($clean['steward_banner_message'], 'UTF-8'),
            ]);
        }
        $this->flash->success(t('Settings saved successfully.'));
        $this->redirectToShow();
    }

    /** POST + CSRF. Refused (403, nothing written) unless `version` is the dismissible banner showing now. */
    public function dismiss(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $version = $this->request->getRawValue('version');
        if (! is_string($version) || ! $this->banner()->dismiss((int) $this->userSession->getId(), $version)) {
            throw new AccessForbiddenException();
        }

        $redirect = $this->request->getRawValue('redirect');
        $this->response->redirect(is_string($redirect) && self::isLocalPath($redirect)
            ? $redirect
            : $this->helper->url->to('DashboardController', 'show'));
    }

    /** A path on this site: one leading slash, no scheme-relative `//` or `/\`, no whitespace or controls. */
    private static function isLocalPath(string $path): bool
    {
        return preg_match('#^/(?![/\\\\])[^\s\x00-\x1f\x7f\\\\]*$#D', $path) === 1;
    }

    /** FeatureGate's check again (spec §1, defence in depth), before ACL-dependent work. */
    private function featureOn(): bool
    {
        if ($this->container['stewardFeatures']->isEnabled('banner')) {
            return true;
        }
        $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
        return false;
    }

    private function banner(): Banner
    {
        return $this->container['stewardBanner'];
    }

    private function redirectToShow(): void
    {
        $this->response->redirect($this->helper->url->to('StewardBannerController', 'show', ['plugin' => 'Steward']));
    }

    private function assertAdmin(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }
}
