<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

/**
 * Steward's ACL rules, registered from one list and checked against the live access maps on `app.bootstrap`.
 * Kanboard lower-cases and overwrites rules by controller/action name, so a plugin loaded later can replace
 * ours. On any difference Steward marks itself broken for the request: FeatureGate answers 403 for every
 * Steward action and switching is refused (`acl_conflict`).
 */
class AclCheck extends Base
{
    /** Container flag: true once a conflict has been seen in this request. */
    public const FLAG = 'stewardAclBroken';

    /** access map container key => list of [controller, actions, lowest role]. The only list of Steward's rules. */
    public const EXPECTED = [
        'applicationAccessMap' => [
            ['StewardSettingsController', ['show', 'save'], Role::APP_ADMIN],
            ['StewardAuditController', ['index'], Role::APP_ADMIN],
            ['StewardSwitchController', ['start'], Role::APP_ADMIN],
        ],
        'projectAccessMap' => [],
    ];

    /** Adds every expected rule to its access map. Called once from Plugin::initialize(). */
    public function register(): void
    {
        foreach ($this->expected() as $map => $rules) {
            foreach ($rules as [$controller, $actions, $role]) {
                $this->container[$map]->add($controller, $actions, $role);
            }
        }
    }

    /** `app.bootstrap` listener. */
    public function onBootstrap(): void
    {
        $this->verify();
    }

    /** True when the live maps match. A mismatch sets the flag (never cleared) and logs each conflicting rule. */
    public function verify(): bool
    {
        $ok = true;
        foreach ($this->expected() as $map => $rules) {
            $accessMap = $this->container[$map];
            foreach ($rules as [$controller, $actions, $role]) {
                foreach ((array) $actions as $action) {
                    $live = $accessMap->getRoles($controller, $action);
                    if ($live === $accessMap->getRoleHierarchy($role)) {
                        continue;
                    }
                    $ok = false;
                    $this->logger->error(sprintf(
                        'Steward: %s rule for %s::%s is %s, expected %s; another plugin replaced it. Steward refuses every action until it is fixed.',
                        $map, $controller, $action, $live[0] ?? '(none)', $role
                    ));
                }
            }
        }
        if (! $ok) {
            $this->container[self::FLAG] = true;
        }
        return $ok;
    }

    public function isBroken(): bool
    {
        return isset($this->container[self::FLAG]) && $this->container[self::FLAG] === true;
    }

    /** @return array<string,list<array{0:string,1:list<string>,2:string}>> */
    protected function expected(): array
    {
        return self::EXPECTED;
    }
}
