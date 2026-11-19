<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Steward\Helper\StewardTextHelper;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\PanelData;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;
use Kanboard\Plugin\Steward\Model\PublicActivity;

/**
 * Public project page (spec §5.3), anonymous, at /public/project/:token. Shown only while the feature, core's
 * public access and the project's Public page are all on; anything else gets FeatureGate's 404, so a visitor
 * can't tell which one is missing. The token is the only key and never appears in the page.
 */
class StewardPublicController extends BaseController
{
    public function show(): void
    {
        // Every header on every answer: the URL holds the token.
        $this->response->withHeader('Referrer-Policy', 'no-referrer');

        $project = $this->publicProject($this->request->getStringParam('token'));
        if ($project === null) {
            $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
            return;
        }
        $projectId = (int) $project['id'];
        $settings = (new ProjectPageSettings($this->container))->get($projectId);
        if (! $settings['public']) {
            $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
            return;
        }
        if (! $settings['listed']) {
            $this->response->withHeader('X-Robots-Tag', 'noindex');
        }

        $data = new PanelData($this->container);
        $panels = [];
        foreach ($settings['public_panels'] as $name) {
            if (! in_array($name, ProjectPageSettings::PUBLIC_PANELS, true)) {
                continue;
            }
            $panels[$name] = match ($name) {
                // Steward's parser in public mode, whether or not the markdown feature swapped the text helper.
                'description' => (new StewardTextHelper($this->container))->markdownPublic($project['description'] ?? null),
                'progress' => $data->progress($projectId),
                'board' => $data->board($projectId, true),
                'completed' => $data->publicCompleted($projectId),
                'activity' => (new PublicActivity($this->container))->recent($projectId, $settings['show_names']),
                default => null,
            };
        }

        // Only this page loads the public stylesheet.
        $this->hook->on('template:layout:css', ['template' => 'plugins/Steward/Assets/css/public.css']);
        $this->response->html($this->helper->layout->app('Steward:public/project', [
            'title' => $project['name'],
            'no_layout' => true,
            'not_editable' => true,
            'project' => ['name' => (string) $project['name']],
            'settings' => $settings,
            'panels' => $panels,
        ]));
    }

    /** The project for $token while the feature and core public access are on (repeats FeatureGate's check). */
    private function publicProject(string $token): ?array
    {
        if (! $this->container['stewardFeatures']->isEnabled('public_pages') || $token === '') {
            return null;
        }
        $project = $this->projectModel->getByToken($token);
        if (empty($project) || empty($project['is_public']) || ! hash_equals((string) $project['token'], $token)) {
            return null;
        }
        return $project;
    }
}
