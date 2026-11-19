<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\SettingsSchema;

/** Settings → Steward. Admin only; saves SettingsSchema keys and nothing else. */
class StewardSettingsController extends BaseController
{
    public function show(): void
    {
        $this->assertAdmin();

        $values = [];
        foreach (SettingsSchema::defaults() as $key => $default) {
            $values[$key] = $this->configModel->get($key, $default);
        }

        $this->response->html($this->helper->layout->config('Steward:config/settings', [
            'title' => t('Settings').' &gt; Steward',
            'values' => $values,
            'features' => SettingsSchema::FEATURES,
            'switching_forced_off' => $this->container['stewardFeatures']->switchingForcedOff(),
        ]));
    }

    public function save(): void
    {
        $this->assertAdmin();

        // getValues() validates and consumes the single-use CSRF token: call it once.
        $values = $this->request->getValues();
        if ($values === []) {
            throw new AccessForbiddenException();
        }

        $clean = SettingsSchema::filter($values);
        $defaults = SettingsSchema::defaults();
        $changed = [];
        foreach ($clean as $key => $value) {
            if ((string) $this->configModel->get($key, $defaults[$key]) !== $value) {
                $changed[] = $key;
            }
        }

        if ($this->configModel->save($clean)) {
            // Key names only, never values. Written after save() has closed its transaction.
            if ($changed !== []) {
                $this->container['stewardAudit']->logInternal('settings.changed', (int) $this->userSession->getId(), null, ['keys' => $changed]);
            }
            $this->flash->success(t('Settings saved successfully.'));
        } else {
            $this->flash->failure(t('Unable to save your settings.'));
        }

        $this->response->redirect($this->helper->url->to('StewardSettingsController', 'show', ['plugin' => 'Steward']));
    }

    private function assertAdmin(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }
}
