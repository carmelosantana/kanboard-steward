<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\SwitchPolicy;

/** Starts a user switch (spec §2). App admins only (AclCheck); behind the `switching` feature (FeatureGate). */
class StewardSwitchController extends BaseController
{
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

        // One message for every reason; the real one is in the audit log only.
        $this->flash->failure(t(SwitchPolicy::UNIFORM_MESSAGE));
        if ($targetId > 0 && $this->userModel->getById($targetId)) {
            $this->response->redirect($this->helper->url->to('UserViewController', 'show', ['user_id' => $targetId]));
        } else {
            $this->response->redirect($this->helper->url->to('UserListController', 'show'));
        }
    }
}
