<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

/**
 * Who may switch to whom (spec §2 step 2). A pure decision: callers pass the actor and target rows and the
 * session facts; the only state read here is the feature flag and the ACL-conflict flag.
 * Authorisation is checked before the target, so a non-admin never learns whether a user exists.
 */
class SwitchPolicy extends Base
{
    /** Shown for every refusal; the real reason goes to the audit log only. */
    public const UNIFORM_MESSAGE = "You can't switch to this user";

    /** @return string|null null when allowed, else the reason code */
    public function canStart(array $actor, ?array $target, bool $alreadySwitched, bool $actorPostAuthValidated): ?string
    {
        return match (true) {
            ! $this->stewardFeatures->isEnabled('switching') => 'disabled',
            $this->stewardAcl->isBroken() => 'acl_conflict',
            ($actor['role'] ?? null) !== Role::APP_ADMIN => 'not_admin',
            $alreadySwitched => 'nested',
            ! empty($actor['twofactor_activated']) && ! $actorPostAuthValidated => '2fa_pending',
            // The authorisation boundary: every reason above is decided without the target. SwitchService::start()
            // calls once with a null target and looks the user up only when this is the answer; keep it here.
            $target === null => 'not_found',
            empty($target['is_active']) => 'inactive',
            (int) ($target['id'] ?? 0) === (int) ($actor['id'] ?? 0) => 'self',
            ($target['role'] ?? null) === Role::APP_ADMIN => 'target_admin',
            default => null,
        };
    }
}
