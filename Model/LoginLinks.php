<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Security\AuthenticationManager;
use Kanboard\Core\Security\Role;
use Kanboard\Event\AuthSuccessEvent;
use Kanboard\Model\RememberMeSessionModel;
use PDO;
use Throwable;

/**
 * Container key `stewardLoginLinks`: one-time sign-in links (Steward 1.1). Only the token's sha256 is stored;
 * the raw token exists in the URL that mint() returns and nowhere else.
 */
class LoginLinks extends Base
{
    public const TABLE = 'steward_login_link';
    /** Seconds a link lives. */
    public const LIFETIME = 900;
    /** Links one admin may mint per rolling RATE_WINDOW seconds, remint-killed rows included. */
    public const RATE_LIMIT = 10;
    public const RATE_WINDOW = 3600;
    /** Auth type core records for a link sign-in (last_logins, AuthSuccessEvent). */
    public const AUTH_TYPE = 'StewardLoginLink';
    private const TOKEN = '/^[0-9a-f]{64}$/D';
    /** Rows that expired longer ago than this are deleted on each mint. */
    private const KEEP_EXPIRED = 86400;

    /**
     * Null when the signed-in user may mint a link for $target now, else the reason. Authorisation is decided
     * before the target is looked at, so a non-admin never learns whether a user exists. Never audits.
     */
    public function refusalFor(?array $target): ?string
    {
        $actor = $this->actor();
        return match (true) {
            ! $this->stewardFeatures->isEnabled('login_links') => 'disabled',
            $this->stewardAcl->isBroken() => 'acl_conflict',
            ($actor['role'] ?? null) !== Role::APP_ADMIN => 'not_admin',
            $this->stewardSwitching->isSwitched() => 'switched',
            ! empty($actor['twofactor_activated']) && ! $this->userSession->isPostAuthenticationValidated() => '2fa_pending',
            // The authorisation boundary: mint() calls once with a null target and looks the user up only when
            // this is the answer; keep every actor reason above it.
            $target === null => 'not_found',
            empty($target['is_active']) => 'inactive',
            (int) ($target['id'] ?? 0) === (int) ($actor['id'] ?? 0) => 'self',
            default => $this->targetRefusal($target),
        };
    }

    /**
     * The user a live link for $token would sign in, for the confirm page (`id`, `username`, `name` only); null
     * when redeem() would fail now (whatever the browser's own state). Read-only: never claims the link, never audits.
     */
    public function peek(string $token): ?array
    {
        if (! preg_match(self::TOKEN, $token)) {
            return null;
        }
        $row = $this->findByToken($token);
        if ($row === null || $row['used_at'] !== null || (int) $row['expires_at'] <= time()) {
            return null;
        }
        $user = $this->userModel->getById((int) $row['user_id']) ?: null;
        if ($this->targetRefusal($user) !== null) {
            return null;
        }
        // Only what the confirm page shows: the full row holds the password hash.
        return ['id' => (int) $user['id'], 'username' => (string) $user['username'], 'name' => (string) ($user['name'] ?? '')];
    }

    /**
     * Signs the browser in as the link's user: null on success, else the reason (`signed_in`, `malformed`,
     * `unknown`, `used`, `expired`, `target_invalid`, `audit_failed`). The link is spent by the atomic claim, so a
     * target that changed since the mint, or a failed audit write, still uses it up. Every failure but
     * `signed_in` (which touches no link) writes `login_link.failed`.
     */
    public function redeem(string $token): ?string
    {
        if ($this->signedIn()) {
            return 'signed_in';
        }
        if (! preg_match(self::TOKEN, $token)) {
            return $this->fail('malformed', null);
        }
        $row = $this->findByToken($token);
        if ($row === null) {
            return $this->fail('unknown', null);
        }
        $id = (int) $row['id'];
        $userId = (int) $row['user_id'];

        // PicoDb's update() returns a bool, not the rows changed; only a raw statement tells two racing redeems
        // apart. Strict `>`: a remint sets expires_at = now, and that row must be dead in the same second.
        $now = time();
        $claim = $this->db->execute(
            'UPDATE '.self::TABLE.' SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?',
            [$now, $id, $now]
        );
        if ($claim === false || $claim->rowCount() !== 1) {
            $usedAt = $this->db->table(self::TABLE)->eq('id', $id)->findOneColumn('used_at');
            return $this->fail($usedAt !== null && $usedAt !== false ? 'used' : 'expired', $userId);
        }

        $user = $this->userModel->getById($userId) ?: null;
        if ($this->targetRefusal($user) !== null) {
            return $this->fail('target_invalid', $userId);
        }

        $createdBy = $row['created_by'] === null ? null : (int) $row['created_by'];
        if (! $this->stewardAudit->logInternal('login_link.used', $createdBy, $userId, ['link_id' => $id])) {
            return $this->fail('audit_failed', $userId);
        }

        // As core's login does; hasRememberMe may be left over from a failed password attempt in this session,
        // and core would turn it into a long-lived remember-me login for the link's user.
        $this->userSession->initialize($user);
        session_remove('hasRememberMe');
        $this->dispatcher->dispatch(new AuthSuccessEvent(self::AUTH_TYPE), AuthenticationManager::EVENT_SUCCESS);
        return null;
    }

    /** @return array{ok:true,url:string,expires_at:int}|array{ok:false,reason:string} */
    public function mint(int $targetId): array
    {
        $actorId = (int) $this->userSession->getId();

        // Authorisation first, without the target: only an authorised actor gets as far as the lookup.
        $reason = $this->refusalFor(null);
        if ($reason === 'not_found') {
            $target = $targetId > 0 ? ($this->userModel->getById($targetId) ?: null) : null;
            $reason = $this->refusalFor($target);
        }
        if ($reason === null && $this->appUrl() === '') {
            $reason = 'no_app_url';
        }
        if ($reason !== null) {
            return $this->refuse($reason, $actorId, $targetId);
        }

        // Decision 18: one transaction, locked on the actor and the target first, so two concurrent mints for a
        // user can't both cancel the old links before either inserts, and one admin's mints can't race the rate
        // limit. A refusal rolls back and is audited after the rollback (PicoDb rolls back on any SQL error too).
        $this->db->startTransaction();
        try {
            $this->lockUsers($actorId, $targetId);
            $now = time();
            if ($this->mintedSince($actorId, $now - self::RATE_WINDOW) >= self::RATE_LIMIT) {
                $this->db->cancelTransaction();
                return $this->refuse('rate_limited', $actorId, $targetId);
            }

            // Kill this user's live links rather than delete them: the rate limit still counts them.
            $this->db->table(self::TABLE)
                ->eq('user_id', $targetId)->isNull('used_at')->gt('expires_at', $now)
                ->update(['expires_at' => $now]);

            $token = bin2hex(random_bytes(32));
            $expiresAt = $now + self::LIFETIME;
            $id = (int) $this->db->table(self::TABLE)->persist([
                'user_id' => $targetId,
                'token_hash' => hash('sha256', $token),
                'created_by' => $actorId,
                'created_at' => $now,
                'expires_at' => $expiresAt,
            ]);
            if ($id <= 0) {
                $this->db->cancelTransaction();
                return $this->refuse('store_failed', $actorId, $targetId);
            }

            if (! $this->stewardAudit->logInternal('login_link.created', $actorId, $targetId, ['link_id' => $id, 'expires_at' => $expiresAt])) {
                $this->db->cancelTransaction();
                return $this->refuse('audit_failed', $actorId, $targetId);
            }
            $this->db->closeTransaction();
        } catch (Throwable $e) {
            $this->db->cancelTransaction();
            throw $e;
        }
        // Housekeeping needs no lock: kept out of the transaction so it never holds up another user's mint.
        $this->db->table(self::TABLE)->lt('expires_at', $now - self::KEEP_EXPIRED)->remove();

        return ['ok' => true, 'url' => $this->url($token), 'expires_at' => $expiresAt];
    }

    /**
     * The link for $token under Kanboard's configured Application URL, never the request's Host header (decision 17).
     * The path is the one core's URL helper builds: `login-link/<token>` with URL rewriting on, the query-string form
     * with it off. The helper puts the request's base directory in front; the Application URL already holds it.
     */
    public function url(string $token): string
    {
        $helper = $this->helper->url;
        $path = $helper->to('StewardLoginLinkController', 'confirm', ['plugin' => 'Steward', 'token' => $token]);
        $dir = (string) $helper->dir();
        if ($dir !== '' && str_starts_with($path, $dir)) {
            $path = substr($path, strlen($dir));
        }
        return $this->appUrl().'/'.ltrim($path, '/');
    }

    /**
     * application_url as url() and mint()'s no_app_url check both read it: trimmed, no trailing slash. Core's
     * ConfigModel appends '/' to a value that doesn't end in one, so "https://kb.example/ " is stored as
     * "https://kb.example/ /": strip slashes and whitespace from the end together.
     */
    private function appUrl(): string
    {
        return rtrim(trim((string) $this->configModel->get('application_url', '')), "/ \t\n\r\0\x0B");
    }

    /** Why $user can't be signed in by a link (decisions 13, 14 and 19), or null. */
    private function targetRefusal(?array $user): ?string
    {
        return match (true) {
            $user === null => 'not_found',
            empty($user['is_active']) => 'inactive',
            ($user['role'] ?? null) === Role::APP_ADMIN => 'target_admin',
            ! empty($user['is_ldap_user']) => 'external_auth',
            // Core's DatabaseAuth won't sign such a user in with a password either (decision 19).
            ! empty($user['disable_login_form']) => 'login_form_disabled',
            $this->userLockingModel->isLocked((string) ($user['username'] ?? '')) => 'locked',
            default => null,
        };
    }

    /**
     * Locks the actor's and the target's user rows until the transaction ends (decision 18), lower id first so two
     * mints never wait on each other in a cycle. SQLite has no row locks but one writer at a time: a write as the
     * transaction's first statement takes that lock, and a second mint's write waits for the commit (busy timeout).
     */
    private function lockUsers(int $actorId, int $targetId): void
    {
        $ids = array_values(array_unique([$actorId, $targetId]));
        sort($ids);
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $sql = match ($this->db->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => 'UPDATE '.self::TABLE.' SET user_id = user_id WHERE user_id IN ('.$in.')',
            'mysql', 'pgsql' => 'SELECT id FROM users WHERE id IN ('.$in.') ORDER BY id FOR UPDATE',
            // SQL Server (sqlsrv, dblib, odbc): an update lock held to the end of the transaction.
            default => 'SELECT id FROM users WITH (UPDLOCK, ROWLOCK) WHERE id IN ('.$in.')',
        };
        $this->db->execute($sql, $ids);
    }

    private function findByToken(string $token): ?array
    {
        return $this->db->table(self::TABLE)->eq('token_hash', hash('sha256', $token))->findOne() ?: null;
    }

    /**
     * A session user, or a remember-me cookie that still matches a live remember-me session (decision 15: public
     * routes skip core's remember-me sign-in). A cookie matching none, or one core can't decode, is stale: removed.
     * redeem() checks it first; the confirm page asks too, so it can say "sign out first" before the POST.
     */
    public function signedIn(): bool
    {
        if ($this->userSession->isLogged()) {
            return true;
        }
        $raw = $this->request->getCookie(RememberMeCookie::COOKIE_NAME);
        if (empty($raw)) {
            return false;
        }
        // Core's decode() warns on a value without `token|sequence`: never hand it one.
        $credentials = is_string($raw) && str_contains($raw, '|') ? $this->rememberMeCookie->read() : false;
        if ($credentials !== false && $this->db->table(RememberMeSessionModel::TABLE)
                ->eq('token', (string) $credentials['token'])->gt('expiration', time())->exists()) {
            return true;
        }
        $this->rememberMeCookie->remove();
        return false;
    }

    private function fail(string $reason, ?int $userId): string
    {
        if ($reason === 'audit_failed'
            || ! $this->stewardAudit->logInternal('login_link.failed', null, $userId, ['reason' => $reason])) {
            $this->logger->error(sprintf(
                'Steward: sign-in link failed (%s) for user %s; the audit log could not record it.',
                $reason, $userId ?? '-'
            ));
        }
        return $reason;
    }

    private function actor(): array
    {
        $actorId = (int) $this->userSession->getId();
        return $actorId > 0 ? ($this->userModel->getById($actorId) ?: []) : [];
    }

    private function mintedSince(int $actorId, int $since): int
    {
        return (int) $this->db->table(self::TABLE)->eq('created_by', $actorId)->gt('created_at', $since)->count();
    }

    /** @return array{ok:false,reason:string} */
    private function refuse(string $reason, int $actorId, int $targetId): array
    {
        $actor = $actorId > 0 ? $actorId : null;
        $subject = $targetId > 0 ? $targetId : null;
        if ($reason === 'audit_failed'
            || ! $this->stewardAudit->logInternal('login_link.refused', $actor, $subject, ['reason' => $reason])) {
            $this->logger->error(sprintf(
                'Steward: sign-in link refused (%s) for actor %s, target %s; the audit log could not record it.',
                $reason, $actor ?? '-', $subject ?? '-'
            ));
        }
        return ['ok' => false, 'reason' => $reason];
    }
}
