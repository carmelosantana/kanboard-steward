<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Model\SwimlaneModel;
use Kanboard\Model\TaskModel;

/**
 * Data for the project page panels (spec §5.2), shared by Home and the public page (§5.3). Every query filters by
 * the given project id and nothing depends on who is viewing: callers check access first, and the public page
 * asks for its narrower shape through the parameters.
 */
class PanelData extends Base
{
    public const COMPLETED_DAYS = 14;
    public const COMPLETED_MAX = 50;
    public const ACTIVITY_MAX = 20;

    /** @return array{columns:list<array{title:string,open:int}>, open:int, closed:int, percent:int} */
    public function progress(int $projectId): array
    {
        $columns = [];
        foreach ($this->columnModel->getAllWithOpenedTaskCount($projectId) as $column) {
            $columns[] = ['title' => (string) $column['title'], 'open' => (int) $column['nb_open_tasks']];
        }
        $open = array_sum(array_column($columns, 'open'));
        $closed = (int) $this->taskFinderModel->countByProjectId($projectId, [TaskModel::STATUS_CLOSED]);
        $total = $open + $closed;

        return [
            'columns' => $columns,
            'open' => $open,
            'closed' => $closed,
            'percent' => $total > 0 ? (int) round($closed * 100 / $total) : 0,
        ];
    }

    /**
     * Open tasks by column, swimlanes flattened (swimlane order, then task order). $public leaves out task ids, so
     * nothing can link to a task.
     *
     * @return list<array{title:string, tasks:list<array{id?:int,title:string}>}>
     */
    public function board(int $projectId, bool $public): array
    {
        $columns = [];
        foreach ($this->columnModel->getAll($projectId) as $column) {
            $columns[(int) $column['id']] = ['title' => (string) $column['title'], 'tasks' => []];
        }

        $tasks = $this->db->table(TaskModel::TABLE)
            ->columns(TaskModel::TABLE.'.id', TaskModel::TABLE.'.title', TaskModel::TABLE.'.column_id')
            ->join(SwimlaneModel::TABLE, 'id', 'swimlane_id')
            ->eq(TaskModel::TABLE.'.project_id', $projectId)
            ->eq(TaskModel::TABLE.'.is_active', TaskModel::STATUS_OPEN)
            ->asc(SwimlaneModel::TABLE.'.position')
            ->asc(TaskModel::TABLE.'.position')
            ->asc(TaskModel::TABLE.'.id')
            ->findAll();

        foreach ($tasks as $task) {
            $columnId = (int) $task['column_id'];
            if (! isset($columns[$columnId])) {
                continue;
            }
            $columns[$columnId]['tasks'][] = $public
                ? ['title' => (string) $task['title']]
                : ['id' => (int) $task['id'], 'title' => (string) $task['title']];
        }
        return array_values($columns);
    }

    /** @return list<array{id:int,title:string,date_completed:int}> tasks closed in the last $days days, newest first */
    public function completed(int $projectId, int $days = self::COMPLETED_DAYS): array
    {
        $rows = $this->db->table(TaskModel::TABLE)
            ->columns('id', 'title', 'date_completed')
            ->eq('project_id', $projectId)
            ->eq('is_active', TaskModel::STATUS_CLOSED)
            ->gte('date_completed', time() - $days * 86400)
            ->desc('date_completed')
            ->desc('id')
            ->limit(self::COMPLETED_MAX)
            ->findAll();

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'date_completed' => (int) $row['date_completed'],
        ], $rows);
    }

    /** Core's project activity stream, newest first, with each event rendered by core's event templates. */
    public function activity(int $projectId, int $limit = self::ACTIVITY_MAX): array
    {
        return $this->helper->projectActivity->getProjectEvents($projectId, $limit);
    }

    /** Project attachments (core's projectFileModel rows, with etag). */
    public function files(int $projectId): array
    {
        return $this->projectFileModel->getAll($projectId);
    }
}
