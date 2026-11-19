<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Model\ProjectActivityModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\UserModel;

/**
 * Activity for the public page (spec §5.3): only tasks created, moved to a column, closed and reopened, each as
 * one line built here from the task title and column title. Nothing else in the event row (usernames, emails,
 * assignees, descriptions, changes) is read out. The actor's display name is added only when asked, and never
 * the username or email.
 */
class PublicActivity extends Base
{
    /** Event name => line, %1$s the task title, %2$s the column title. */
    private const EVENTS = [
        TaskModel::EVENT_CREATE => '%1$s created',
        TaskModel::EVENT_MOVE_COLUMN => '%1$s moved to %2$s',
        TaskModel::EVENT_CLOSE => '%1$s closed',
        TaskModel::EVENT_OPEN => '%1$s reopened',
    ];

    /**
     * Newest first. `text` is HTML: the title and column escaped, each in <em>.
     *
     * @return list<array{text:string, at:int}>
     */
    public function recent(int $projectId, bool $showNames, int $limit = PanelData::ACTIVITY_MAX): array
    {
        $rows = $this->db->table(ProjectActivityModel::TABLE)
            ->columns(
                ProjectActivityModel::TABLE.'.event_name',
                ProjectActivityModel::TABLE.'.date_creation',
                ProjectActivityModel::TABLE.'.data',
                'u.name AS actor_name'
            )
            ->left(UserModel::TABLE, 'u', 'id', ProjectActivityModel::TABLE, 'creator_id')
            ->eq(ProjectActivityModel::TABLE.'.project_id', $projectId)
            ->in(ProjectActivityModel::TABLE.'.event_name', array_keys(self::EVENTS))
            ->desc(ProjectActivityModel::TABLE.'.id')
            ->limit(max(0, $limit))
            ->findAll();

        $lines = [];
        foreach ($rows as $row) {
            $text = $this->line((string) $row['event_name'], json_decode((string) $row['data'], true));
            if ($text === null) {
                continue;
            }
            $name = trim((string) ($row['actor_name'] ?? ''));
            if ($showNames && $name !== '') {
                $text = e('%s: %s', self::e($name), $text);
            }
            $lines[] = ['text' => $text, 'at' => (int) $row['date_creation']];
        }
        return $lines;
    }

    /** Null when the row has no usable title (or column, for a move). */
    private function line(string $event, mixed $data): ?string
    {
        $task = is_array($data) && is_array($data['task'] ?? null) ? $data['task'] : [];
        $title = is_scalar($task['title'] ?? null) ? (string) $task['title'] : '';
        $column = is_scalar($task['column_title'] ?? null) ? (string) $task['column_title'] : '';
        if ($title === '' || ($event === TaskModel::EVENT_MOVE_COLUMN && $column === '')) {
            return null;
        }
        // e(): core's unescaped translate, as its event templates use; every value is escaped here first.
        return e(self::EVENTS[$event], '<em>'.self::e($title).'</em>', '<em>'.self::e($column).'</em>');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);
    }
}
