<?php
namespace Kanboard\Plugin\Steward\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Model\RememberMeSessionModel;
use Throwable;

/**
 * Container key `stewardSwitching`: starts and ends a user switch and tells whether this session is one (spec §2).
 * The admin's identity lives only in `$_SESSION['steward_switch']`; nothing else carries it.
 */
class SwitchService extends Base
{
    public const SESSION_KEY = 'steward_switch';

    /** end() reason => audit event. */
    public const END_EVENTS = ['end' => 'switch.end', 'expired' => 'switch.expired', 'revoked' => 'switch.revoked'];

    /** @var array<string,array<string,string>> plugin => ['Controller::action' => 'read'|'write'] */
    private array $allowed = [];

    /**
     * Lets a plugin's actions run while switched (spec §2): `['Controller::action' => 'read'|'write']`. Keyed by
     * plugin, so one plugin's opt-in never covers another's same-named controller. Steward's own rows are fixed.
     */
    public function allow(string $plugin, array $actions): void
    {
        if (! preg_match('/^\w+$/', $plugin) || strcasecmp($plugin, 'Steward') === 0) {
            throw new InvalidArgumentException('Invalid plugin name for switch registration: '.$plugin);
        }
        foreach ($actions as $pair => $level) {
            if (! is_string($pair) || ! preg_match('/^\w+::\w+$/', $pair)
                || ! in_array($level, [SwitchActionTable::READ, SwitchActionTable::WRITE], true)) {
                throw new InvalidArgumentException('Invalid switch registration for '.$plugin.': '.$pair);
            }
            // Plugin names are case-insensitive (the router and SwitchActionTable lower-case them).
            $this->allowed[strtolower($plugin)][$pair] = $level;
        }
    }

    /** @return array<string,array<string,string>> lower-case plugin => the suite's view pages (when installed), then allow() calls. */
    public function registrations(): array
    {
        $all = array_change_key_case(SwitchActionTable::suite());
        foreach ($this->allowed as $plugin => $actions) {
            $all[$plugin] = array_change_key_case($actions) + array_change_key_case($all[$plugin] ?? []);
        }
        return $all;
    }

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
     * Null when the signed-in user could switch to $target right now, else the SwitchPolicy reason. For the
     * profile button and the confirmation only: nothing is audited here; start() decides again and records it.
     */
    public function refusalFor(?array $target): ?string
    {
        $actorId = (int) $this->userSession->getId();
        $actor = $actorId > 0 ? ($this->userModel->getById($actorId) ?: []) : [];
        return (new SwitchPolicy($this->container))
            ->canStart($actor, $target, $this->isSwitched(), $this->userSession->isPostAuthenticationValidated());
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
     * Ends the switch by logging the browser out (Decisions 15 and 21); nothing here restores the admin.
     * `end` (Switch back) leaves a fresh session holding only `redirectAfterLogin` = the target's profile, so the
     * admin returns there after signing in again; `expired` and `revoked` leave an empty one. Not switched: no-op.
     * The session ends even when the audit write fails: logging out is always the safe direction.
     * $detail, when given, is recorded as the reason instead (the guard's `disabled`, `not_admin`, `target_changed`).
     */
    public function end(string $reason, ?string $detail = null): void
    {
        if (! isset(self::END_EVENTS[$reason])) {
            throw new InvalidArgumentException('Unknown switch end reason: '.$reason);
        }
        $switch = $this->current();
        if ($switch === null) {
            return;
        }
        $targetId = (int) $this->userSession->getId();

        // Clear the marker first: close() fires core's logout event, whose listener must not write a second row.
        session_remove(self::SESSION_KEY);
        $this->recordEnd(self::END_EVENTS[$reason], $switch, $targetId > 0 ? $targetId : null, $detail ?? $reason);

        $this->sessionManager->close();
        // Reopen through core (KB_SID name, database handler) under a fresh id. The database handler accepts any
        // id it is offered, so without this the request's cookie would revive the destroyed id, and PHP would send
        // no new cookie after close() expired it: the browser would lose the redirect below. session.use_strict_mode
        // would not help: PHP enforces it through the handler's validateId(), which core's SessionHandler lacks.
        unset($_COOKIE[session_name()]);
        $this->sessionManager->open();

        if ($reason === 'end' && $targetId > 0) {
            // A local path: core's redirectAfterLogin() ignores full URLs.
            session_set('redirectAfterLogin', $this->helper->url->to('UserViewController', 'show', ['user_id' => $targetId]));
        }
    }

    /**
     * Closes a switch that core ended: `logout` (session.destroy while switched) or `login` (a real sign-in over
     * a session still carrying the marker). One `switch.end` row; end() clears the marker first, so its own
     * close() writes nothing here.
     */
    public function endedByCore(string $reason): void
    {
        if ($reason !== 'logout' && $reason !== 'login') {
            throw new InvalidArgumentException('Unknown core end reason: '.$reason);
        }
        $switch = $this->current();
        if ($switch === null) {
            return;
        }
        session_remove(self::SESSION_KEY);
        // On login the session user is already the one signing in, not the target.
        $targetId = $reason === 'logout' ? (int) $this->userSession->getId() : 0;
        $this->recordEnd('switch.end', $switch, $targetId > 0 ? $targetId : null, $reason);
    }

    private function recordEnd(string $event, array $switch, ?int $targetId, string $reason): void
    {
        $adminId = (int) ($switch['admin_id'] ?? 0);
        $switchId = isset($switch['switch_id']) ? (string) $switch['switch_id'] : null;
        if (! $this->stewardAudit->logInternal($event, $adminId > 0 ? $adminId : null, $targetId, ['reason' => $reason], $switchId)) {
            $this->logger->error(sprintf(
                'Steward: %s (%s) for switch %s could not be written to the audit log.',
                $event, $reason, $switchId ?? '-'
            ));
        }
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
            // Core 1.2.57's remove() also takes the owner's id; older cores ignore the extra argument.
            foreach ($rows()->columns('id', 'user_id')->findAll() ?: [] as $row) {
                $this->rememberMeSessionModel->remove((int) $row['id'], (int) $row['user_id']);
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
