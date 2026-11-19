<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

/**
 * Guards every request of a switched session (spec §2 "Guards on every request while switched"). An
 * `app.bootstrap` listener registered whatever the switches say, after the first AclCheck/FeatureGate pair and
 * before core's authentication and ACL middleware. `app.bootstrap` also fires for the API and queue workers: with
 * no PHP session or no `steward_switch` marker it does nothing.
 *
 * In order: switching off → end (revoked, `disabled`); admin no longer an active app admin → end (revoked,
 * `not_admin`); target's role or active flag changed → end (revoked, `target_changed`), before core's
 * checkCurrentSession would wipe the session without an end row; idle too long → end (expired). Then the action
 * table: blocked → 403; a write, or any non-GET request → a `switch.action` row first, 503 if it can't be written.
 * Ending a switch logs the browser out and lets core's middleware send it to the login page.
 */
class SwitchGuard extends Base
{
    public const SERVICE_UNAVAILABLE_BODY = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Service Unavailable</title></head>'
        .'<body><h1>Service Unavailable</h1></body></html>';

    /** Leaving must always work: these run even when their action row can't be written. */
    private const LEAVING = ['steward' => ['stewardswitchcontroller::back'], '' => ['authcontroller::logout']];

    /** `app.bootstrap` listener. */
    public function onBootstrap(): void
    {
        if (! $this->sessionActive()) {
            return;
        }
        $switching = $this->container['stewardSwitching'];
        $switch = $switching->current();
        if ($switch === null) {
            return;
        }

        if (! $this->container['stewardFeatures']->isEnabled('switching')) {
            $switching->end('revoked', 'disabled');
            return;
        }
        if (! $this->adminStillAdmin((int) ($switch['admin_id'] ?? 0))) {
            $switching->end('revoked', 'not_admin');
            return;
        }
        if (! $this->targetUnchanged()) {
            $switching->end('revoked', 'target_changed');
            return;
        }
        $idleMinutes = (int) $this->configModel->get('steward_switch_idle_minutes', SettingsSchema::defaults()['steward_switch_idle_minutes']);
        if (time() - (int) ($switch['last_seen'] ?? 0) > max(1, $idleMinutes) * 60) {
            $switching->end('expired');
            return;
        }

        $plugin = $this->router->getPlugin();
        $controller = $this->router->getController();
        $action = $this->router->getAction();
        $level = (new SwitchActionTable($switching->registrations()))->classify($plugin, $controller, $action);

        if ($level === SwitchActionTable::BLOCKED) {
            $target = $this->userSession->getAll();
            $this->refuse($this->template->render('Steward:switch/forbidden', [
                'target' => (string) (($target['name'] ?? '') ?: $this->userSession->getUsername()),
            ]), 403);
        }

        if ($level === SwitchActionTable::WRITE || $this->request->getMethod() !== 'GET') {
            $details = ['method' => $this->request->getMethod(), 'controller' => $controller, 'action' => $action];
            if ($plugin !== '') {
                $details['plugin'] = $plugin;
            }
            $details += [
                'task_id' => $this->request->getIntegerParam('task_id') ?: null,
                'project_id' => $this->request->getIntegerParam('project_id') ?: null,
            ];
            $written = $this->stewardAudit->logInternal('switch.action', (int) $switch['admin_id'],
                (int) $this->userSession->getId(), $details, (string) $switch['switch_id']);
            if (! $written && ! $this->isLeaving($plugin, $controller, $action)) {
                $this->logger->error('Steward: switch.action could not be written; request refused.');
                $this->refuse(self::SERVICE_UNAVAILABLE_BODY, 503);
            }
        }

        if (! $this->request->isAjax()) {
            $switch['last_seen'] = time();
            session_set(SwitchService::SESSION_KEY, $switch);
        }
    }

    private function adminStillAdmin(int $adminId): bool
    {
        $admin = $adminId > 0 ? $this->userModel->getById($adminId) : null;
        return ! empty($admin) && (int) $admin['is_active'] === 1 && $admin['role'] === Role::APP_ADMIN;
    }

    /** Core's own session check (DatabaseAuth::isValidSession): the target exists, is active, same role. */
    private function targetUnchanged(): bool
    {
        return $this->userModel->isValidSession((int) $this->userSession->getId(), (string) $this->userSession->getRole());
    }

    private function isLeaving(string $plugin, string $controller, string $action): bool
    {
        return in_array(strtolower($controller.'::'.$action), self::LEAVING[strtolower($plugin)] ?? [], true);
    }

    private function refuse(string $body, int $status): never
    {
        // BootstrapMiddleware::sendHeaders() runs after the event; this response never gets there.
        $this->response->withContentSecurityPolicy($this->container['cspRules']);
        $this->response->withSecurityHeaders();
        if (ENABLE_XFRAME) {
            $this->response->withXframe();
        }
        $this->response->html($body, $status);
        $this->halt();
    }

    /** API calls and queue workers never open a PHP session. */
    protected function sessionActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    protected function halt(): never
    {
        exit;
    }
}
