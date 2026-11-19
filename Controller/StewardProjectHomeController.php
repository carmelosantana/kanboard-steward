<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\PanelData;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;

/**
 * Project Home (spec §5.2): the panels a project's managers chose, in their order, for anyone who can view the
 * project. `project_id` comes from the query; the action repeats core's project ACL check itself.
 */
class StewardProjectHomeController extends BaseController
{
    public function show(): void
    {
        // Repeats FeatureGate's check (spec §1, defence in depth).
        if (! $this->container['stewardFeatures']->isEnabled('project_pages')) {
            $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
            return;
        }
        $project = $this->getProject();
        $projectId = (int) $project['id'];
        if (! $this->helper->user->hasProjectAccess('StewardProjectHomeController', 'show', $projectId)) {
            throw new AccessForbiddenException();
        }

        $settings = (new ProjectPageSettings($this->container))->get($projectId);
        $data = new PanelData($this->container);
        $panels = [];
        foreach ($settings['panels'] as $name) {
            $panels[$name] = match ($name) {
                'progress' => $data->progress($projectId),
                'board' => $data->board($projectId, false),
                'activity' => $data->activity($projectId),
                'completed' => $data->completed($projectId),
                'files' => $data->files($projectId),
                default => null,
            };
        }

        $this->response->html($this->helper->layout->app('Steward:project_home/show', [
            'title' => $project['name'],
            'project' => $project,
            'settings' => $settings,
            'panels' => $panels,
        ]));
    }
}
