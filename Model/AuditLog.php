<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Throwable;

/**
 * Container key `stewardAudit`: the steward_audit table.
 *
 * log() is the extension point for other plugins (events `plugin.<name>.<event>` only, so nothing can pass as
 * Steward's own); logInternal() is for Steward itself. Writes return false on failure and never throw.
 * After each successful write every `steward:audit` hook listener is called with the stored row.
 */
class AuditLog extends Base
{
    public const TABLE = 'steward_audit';
    public const HOOK = 'steward:audit';
    /** Config key, not in SettingsSchema, so the settings form can never set it. */
    public const LAST_PRUNE = 'steward_audit_last_prune';
    /** Events anyone can cause from a public page; each keeps only its newest FAILURE_CAP rows, on its own. */
    public const FAILURE_EVENTS = ['login.failure', 'login_link.failed'];
    /** @deprecated 1.1: use FAILURE_EVENTS. Kept for plugins that read it in 1.0. */
    public const FAILURE_EVENT = 'login.failure';
    public const FAILURE_CAP = 10000;
    public const DETAILS_MAX = 4096;
    public const USER_AGENT_MAX = 255;
    public const IP_MAX = 45;
    public const EVENT_MAX = 100;

    private const PLUGIN_EVENT = '/^plugin\.[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/D';
    private const INTERNAL_EVENT = '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/D';
    private const DAY = 86400;

    /** True while hook listeners run: a listener's own write is stored but does not fire the hook again. */
    private bool $dispatching = false;

    public function log(string $event, ?int $actorId, ?int $subjectId, array $details = [], ?string $switchId = null): bool
    {
        if (strlen($event) > self::EVENT_MAX || ! preg_match(self::PLUGIN_EVENT, $event)) {
            return false;
        }
        return $this->write($event, $actorId, $subjectId, $details, $switchId);
    }

    public function logInternal(string $event, ?int $actorId, ?int $subjectId, array $details = [], ?string $switchId = null): bool
    {
        if (strlen($event) > self::EVENT_MAX || ! preg_match(self::INTERNAL_EVENT, $event)) {
            return false;
        }
        return $this->write($event, $actorId, $subjectId, $details, $switchId);
    }

    /** @param array{event?:string,user_id?:int|string,switch_id?:string,from?:int|string,to?:int|string} $filters */
    public function find(array $filters, int $limit, int $offset): array
    {
        try {
            return $this->query($filters)
                ->desc('created_at')->desc('id')
                ->limit(max(0, $limit))->offset(max(0, $offset))
                ->findAll();
        } catch (Throwable $e) {
            $this->logger->error('Steward audit: read failed: '.$e->getMessage());
            return [];
        }
    }

    public function count(array $filters): int
    {
        try {
            return (int) $this->query($filters)->count();
        } catch (Throwable $e) {
            $this->logger->error('Steward audit: count failed: '.$e->getMessage());
            return 0;
        }
    }

    /** @return list<string> distinct events present in the table, sorted; for the audit page's filter. */
    public function events(): array
    {
        try {
            $events = $this->db->table(self::TABLE)->distinct('event')->asc('event')->findAllByColumn('event');
            return array_values(array_map('strval', $events ?: []));
        } catch (Throwable $e) {
            $this->logger->error('Steward audit: read failed: '.$e->getMessage());
            return [];
        }
    }

    /** Drops rows past retention and caps failure rows; does the work at most once a day. */
    public function prune(): void
    {
        try {
            $last = (int) $this->configModel->getOption(self::LAST_PRUNE, '0');
            if ($last > time() - self::DAY) {
                return;
            }
            $this->markPruned();

            $defaults = SettingsSchema::defaults();
            $days = (int) $this->configModel->get('steward_audit_retention_days', $defaults['steward_audit_retention_days']);
            if ($days < 1) {
                $days = (int) $defaults['steward_audit_retention_days'];
            }
            $this->db->table(self::TABLE)->lt('created_at', time() - $days * self::DAY)->remove();
            foreach (self::FAILURE_EVENTS as $event) {
                $this->capFailures($event);
            }
        } catch (Throwable $e) {
            $this->logger->error('Steward audit: prune failed: '.$e->getMessage());
        }
    }

    public function newSwitchId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function write(string $event, ?int $actorId, ?int $subjectId, array $details, ?string $switchId): bool
    {
        try {
            $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $row = [
                'created_at' => time(),
                'event' => $event,
                'actor_user_id' => $actorId,
                'subject_user_id' => $subjectId,
                'switch_id' => $switchId,
                'ip' => mb_strcut(mb_scrub((string) $this->request->getIpAddress(), 'UTF-8'), 0, self::IP_MAX),
                'user_agent' => mb_strcut(mb_scrub((string) $this->request->getUserAgent(), 'UTF-8'), 0, self::USER_AGENT_MAX),
                'details' => mb_strcut($json === false ? '{}' : $json, 0, self::DETAILS_MAX),
            ];
            if (! $this->db->table(self::TABLE)->insert($row)) {
                $this->logger->error('Steward audit: write of '.$event.' failed');
                return false;
            }
            $row = ['id' => (int) $this->db->getLastId()] + $row;
        } catch (Throwable $e) {
            $this->logger->error('Steward audit: write of '.$event.' failed: '.$e->getMessage());
            return false;
        }

        // Housekeeping waits for the next write outside a transaction: on any SQL error PicoDb cancels the
        // open transaction, which would roll back this row (and the caller's work) after we report success.
        if (! $this->db->getConnection()->inTransaction()) {
            if (in_array($event, self::FAILURE_EVENTS, true)) {
                $this->tryCapFailures($event);
            }
            $this->prune();
        }
        $this->dispatch($row);
        return true;
    }

    private function dispatch(array $row): void
    {
        if ($this->dispatching) {
            return;
        }
        $this->dispatching = true;
        try {
            foreach ($this->hook->getListeners(self::HOOK) as $listener) {
                try {
                    $listener($row);
                } catch (Throwable $e) {
                    $this->logger->error('Steward audit: '.self::HOOK.' listener failed: '.$e->getMessage());
                }
            }
        } finally {
            $this->dispatching = false;
        }
    }

    /**
     * Records the prune time without configModel->save(): its startTransaction/closeTransaction would commit a
     * transaction the caller of log() may have open.
     */
    private function markPruned(): void
    {
        $values = ['value' => (string) time(), 'changed_on' => time(), 'changed_by' => (int) $this->userSession->getId()];
        if ($this->configModel->exists(self::LAST_PRUNE)) {
            $this->db->table('settings')->eq('option', self::LAST_PRUNE)->update($values);
        } else {
            $this->db->table('settings')->insert(['option' => self::LAST_PRUNE] + $values);
        }
    }

    private function tryCapFailures(string $event): void
    {
        try {
            $this->capFailures($event);
        } catch (Throwable $e) {
            $this->logger->error('Steward audit: failure cap failed: '.$e->getMessage());
        }
    }

    /** Keeps the newest FAILURE_CAP $event rows: deletes every $event row at or below the id of the first past the cap. */
    private function capFailures(string $event): void
    {
        $cutoff = $this->db->table(self::TABLE)
            ->eq('event', $event)
            ->desc('id')
            ->limit(1)->offset(self::FAILURE_CAP)
            ->findOneColumn('id');
        if ($cutoff !== null && $cutoff !== false) {
            $this->db->table(self::TABLE)->eq('event', $event)->lte('id', (int) $cutoff)->remove();
        }
    }

    private function query(array $filters): \PicoDb\Table
    {
        $query = $this->db->table(self::TABLE);
        if (isset($filters['event']) && $filters['event'] !== '') {
            $query->eq('event', (string) $filters['event']);
        }
        if (isset($filters['user_id']) && $filters['user_id'] !== '') {
            $userId = (int) $filters['user_id'];
            $query->beginOr()->eq('actor_user_id', $userId)->eq('subject_user_id', $userId)->closeOr();
        }
        if (isset($filters['switch_id']) && $filters['switch_id'] !== '') {
            $query->eq('switch_id', (string) $filters['switch_id']);
        }
        if (isset($filters['from']) && $filters['from'] !== '') {
            $query->gte('created_at', (int) $filters['from']);
        }
        if (isset($filters['to']) && $filters['to'] !== '') {
            $query->lte('created_at', (int) $filters['to']);
        }
        return $query;
    }
}
