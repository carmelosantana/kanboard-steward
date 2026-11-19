<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;

/**
 * Project settings → Project page (spec §5.1–5.4): tagline, links, Home and public panels, and the Public page,
 * Listed and Show names toggles. Project managers and admins. `project_id` comes from the query only; the
 * action re-checks the manager role itself on top of core's project ACL middleware.
 */
class StewardProjectSettingsController extends BaseController
{
    public function show(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $project = $this->getProject();
        $this->assertManager($project, 'show');
        $this->render($project, $this->settings()->get((int) $project['id']), []);
    }

    public function save(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $project = $this->getProject();
        $this->assertManager($project, 'save');
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        // getValues() validates and consumes the single-use CSRF token: call it once.
        $form = $this->request->getValues();
        if ($form === []) {
            throw new AccessForbiddenException();
        }

        // A form from another project's page: say so and write nothing.
        if (isset($form['project_id']) && (string) $form['project_id'] !== (string) $project['id']) {
            $this->flash->failure(t('This form belongs to another project; nothing was saved.'));
            $this->redirectToShow($project);
            return;
        }

        $values = $this->fromForm($form);
        $errors = $this->settings()->save((int) $project['id'], $values);
        if ($errors !== []) {
            $this->render($project, $values, $errors);
            return;
        }
        $this->flash->success(t('Settings saved successfully.'));
        $this->redirectToShow($project);
    }

    /** The form's fields in ProjectPageSettings' shape. Shapes it can't use go through for save() to refuse. */
    private function fromForm(array $form): array
    {
        $labels = is_array($form['link_label'] ?? null) ? $form['link_label'] : [];
        $urls = is_array($form['link_url'] ?? null) ? $form['link_url'] : [];
        $rows = array_unique(array_merge(array_keys($labels), array_keys($urls)));
        sort($rows);
        $links = [];
        foreach ($rows as $i) {
            $label = $labels[$i] ?? '';
            $url = $urls[$i] ?? '';
            if (is_string($label) && is_string($url) && trim($label) === '' && trim($url) === '') {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
        }

        $order = is_array($form['order'] ?? null) ? $form['order'] : [];
        return [
            'tagline' => $form['tagline'] ?? '',
            'links' => $links,
            'panels' => $this->checked($form['panels'] ?? [], $order),
            'public_panels' => $this->checked($form['public_panels'] ?? [], $order),
            'public' => ! empty($form['public']),
            'listed' => ! empty($form['listed']),
            'show_names' => ! empty($form['show_names']),
        ];
    }

    /** @return list<string> ticked panel names, by the position column, then by the default order */
    private function checked(mixed $boxes, array $order): array
    {
        if (! is_array($boxes)) {
            return [];
        }
        $names = array_map('strval', array_keys(array_filter($boxes)));
        $rank = function (string $name) use ($order): array {
            $default = array_search($name, ProjectPageSettings::HOME_PANELS, true);
            return [
                is_scalar($order[$name] ?? null) ? (int) $order[$name] : PHP_INT_MAX,
                $default === false ? PHP_INT_MAX : $default,
            ];
        };
        usort($names, fn ($a, $b) => $rank($a) <=> $rank($b));
        return $names;
    }

    private function render(array $project, array $values, array $errors): void
    {
        $this->response->html($this->helper->layout->project('Steward:project/settings', [
            'title' => t('Project page'),
            'project' => $project,
            'values' => $values,
            'errors' => $errors,
            'home_panels' => ProjectPageSettings::HOME_PANELS,
            'public_panels' => ProjectPageSettings::PUBLIC_PANELS,
            'links_max' => ProjectPageSettings::LINKS_MAX,
            'tagline_max' => ProjectPageSettings::TAGLINE_MAX,
            'label_max' => ProjectPageSettings::LABEL_MAX,
        ]));
    }

    /** Spec §1 Project ACL: the same check core's middleware made, repeated inside the action. */
    private function assertManager(array $project, string $action): void
    {
        if (! $this->helper->user->hasProjectAccess('StewardProjectSettingsController', $action, (int) $project['id'])) {
            throw new AccessForbiddenException();
        }
    }

    /** Repeats FeatureGate's check (spec §1, defence in depth). */
    private function featureOn(): bool
    {
        if ($this->container['stewardFeatures']->isEnabled('project_pages')) {
            return true;
        }
        $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
        return false;
    }

    private function settings(): ProjectPageSettings
    {
        return new ProjectPageSettings($this->container);
    }

    private function redirectToShow(array $project): void
    {
        $this->response->redirect($this->helper->url->to('StewardProjectSettingsController', 'show',
            ['plugin' => 'Steward', 'project_id' => $project['id']]));
    }
}
