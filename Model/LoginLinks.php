<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

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
            ($target['role'] ?? null) === Role::APP_ADMIN => 'target_admin',
            ! empty($target['is_ldap_user']) => 'external_auth',
            $this->userLockingModel->isLocked((string) ($target['username'] ?? '')) => 'locked',
            default => null,
        };
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
        if ($reason === null && trim((string) $this->configModel->get('application_url', '')) === '') {
            $reason = 'no_app_url';
        }
        $now = time();
        if ($reason === null && $this->mintedSince($actorId, $now - self::RATE_WINDOW) >= self::RATE_LIMIT) {
            $reason = 'rate_limited';
        }
        if ($reason !== null) {
            return $this->refuse($reason, $actorId, $targetId);
        }

        // Kill this user's live links rather than delete them: the rate limit still counts them.
        $this->db->table(self::TABLE)
            ->eq('user_id', $targetId)->isNull('used_at')->gt('expires_at', $now)
            ->update(['expires_at' => $now]);
        $this->db->table(self::TABLE)->lt('expires_at', $now - self::KEEP_EXPIRED)->remove();

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
            return $this->refuse('store_failed', $actorId, $targetId);
        }

        if (! $this->stewardAudit->logInternal('login_link.created', $actorId, $targetId, ['link_id' => $id, 'expires_at' => $expiresAt])) {
            $this->db->table(self::TABLE)->eq('id', $id)->remove();
            return $this->refuse('audit_failed', $actorId, $targetId);
        }

        return ['ok' => true, 'url' => $this->url($token), 'expires_at' => $expiresAt];
    }

    /** The link for $token under Kanboard's configured Application URL; never the request's Host header. */
    public function url(string $token): string
    {
        return rtrim((string) $this->configModel->get('application_url', ''), '/').'/login-link/'.$token;
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
