<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Model\RememberMeSessionModel;
use Throwable;

/**
 * Container key `stewardSwitching`: starts a user switch and tells whether this session is one (spec §2).
 * The admin's identity lives only in `$_SESSION['steward_switch']`; nothing else carries it.
 */
class SwitchService extends Base
{
    public const SESSION_KEY = 'steward_switch';

    public function isSwitched(): bool
    {
        return session_exists(self::SESSION_KEY);
    }

    /** @return array{switch_id:string,admin_id:int,admin_username:string,started_at:int,last_seen:int}|null */
    public function current(): ?array
    {
        $switch = session_get(self::SESSION_KEY);
        return is_array($switch) ? $switch : null;
    }

    /**
     * Turns the signed-in admin's session into a session for $targetId (spec §2 step 3).
     * Returns null on success, else the reason code; every refusal is audited as `switch.refused` (or logged
     * when the audit store itself failed) and leaves the session untouched.
     */
    public function start(int $targetId): ?string
    {
        $actorId = (int) $this->userSession->getId();
        $actor = $actorId > 0 ? ($this->userModel->getById($actorId) ?: []) : [];
        $policy = new SwitchPolicy($this->container);
        $alreadySwitched = $this->isSwitched();
        $validated = $this->userSession->isPostAuthenticationValidated();
        $target = null;

        // Authorisation first, without the target: only an authorised actor gets as far as the lookup.
        $reason = $policy->canStart($actor, null, $alreadySwitched, $validated);
        if ($reason === 'not_found') {
            $target = $targetId > 0 ? ($this->userModel->getById($targetId) ?: null) : null;
            $reason = $policy->canStart($actor, $target, $alreadySwitched, $validated);
        }
        if ($reason !== null) {
            return $this->refuse($reason, $actorId, $targetId);
        }

        $switchId = $this->stewardAudit->newSwitchId();
        $details = ['admin_username' => (string) $actor['username'], 'target_username' => (string) $target['username']];
        if (! $this->stewardAudit->logInternal('switch.start', $actorId, $targetId, $details, $switchId)) {
            return $this->refuse('audit_failed', $actorId, $targetId);
        }

        if (! $this->revokeRememberMe()) {
            return $this->refuse('rememberme_failed', $actorId, $targetId, $switchId);
        }

        // Rotate the id once, deleting the old one from the store (the db handler removes its row); refuse if
        // that fails. Without an active PHP session (CLI) there is no id to rotate.
        if (session_status() === PHP_SESSION_ACTIVE && ! session_regenerate_id(true)) {
            return $this->refuse('session_failed', $actorId, $targetId, $switchId);
        }
        session_flush();
        $this->writeUser($target);
        $this->userSession->setPostAuthenticationAsValidated();
        $now = time();
        session_set(self::SESSION_KEY, [
            'switch_id' => $switchId,
            'admin_id' => $actorId,
            'admin_username' => (string) $actor['username'],
            'started_at' => $now,
            'last_seen' => $now,
        ]);
        return null;
    }

    /**
     * Writes $user into the session exactly as core's UserSession::initialize() does, minus its own
     * session_regenerate_id(false): start() has already rotated the id once, deleting the old one.
     * Keep in step with initialize(); SwitchStartTest compares the two.
     */
    public function writeUser(array $user): void
    {
        foreach (['password', 'is_admin', 'is_project_admin', 'twofactor_secret'] as $column) {
            if (isset($user[$column])) {
                unset($user[$column]);
            }
        }

        $user['id'] = (int) $user['id'];
        $user['is_ldap_user'] = isset($user['is_ldap_user']) ? (bool) $user['is_ldap_user'] : false;
        $user['twofactor_activated'] = isset($user['twofactor_activated']) ? (bool) $user['twofactor_activated'] : false;

        session_set('user', $user);
        session_set('postAuthenticationValidated', false);
    }

    /**
     * Deletes this browser's remember-me login (Decision 17) and confirms it is gone before clearing the cookie.
     * Every row carrying the cookie's token goes, whatever its sequence or expiry. No cookie: nothing to revoke.
     */
    private function revokeRememberMe(): bool
    {
        try {
            // Core's decode() warns on a value without `token|sequence`; such a cookie can't be revoked: fail closed.
            $raw = $this->request->getCookie(RememberMeCookie::COOKIE_NAME);
            if (! empty($raw) && ! (is_string($raw) && str_contains($raw, '|'))) {
                $this->logger->error('Steward: malformed remember-me cookie; switch refused.');
                return false;
            }
            $credentials = $this->rememberMeCookie->read();
            if ($credentials === false) {
                return true;
            }
            $token = (string) $credentials['token'];
            $rows = fn () => $this->db->table(RememberMeSessionModel::TABLE)->eq('token', $token);
            foreach ($rows()->findAllByColumn('id') ?: [] as $id) {
                $this->rememberMeSessionModel->remove((int) $id);
            }
            if ($rows()->count() !== 0) {
                $this->logger->error('Steward: remember-me login could not be removed; switch refused.');
                return false;
            }
        } catch (Throwable $e) {
            $this->logger->error('Steward: remember-me revocation failed: '.$e->getMessage());
            return false;
        }

        $this->rememberMeCookie->remove();
        return true;
    }

    private function refuse(string $reason, int $actorId, int $targetId, ?string $switchId = null): string
    {
        $actor = $actorId > 0 ? $actorId : null;
        $subject = $targetId > 0 ? $targetId : null;
        if ($reason === 'audit_failed'
            || ! $this->stewardAudit->logInternal('switch.refused', $actor, $subject, ['reason' => $reason], $switchId)) {
            $this->logger->error(sprintf(
                'Steward: switch refused (%s) for actor %s, target %s; the audit log could not record it.',
                $reason, $actor ?? '-', $subject ?? '-'
            ));
        }
        return $reason;
    }
}
