<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\Steward\Helper\StewardTextHelper;

/**
 * Renders Steward's anonymous pages (spec §5.3–5.5) for StewardPublicController and StewardExploreController,
 * and decides which projects are listed. Every page goes through finish(): the project tokens it names are
 * replaced with HIDDEN wherever they appear (a member may paste a core public link into the description or
 * tagline), and an indexable page drops core's robots meta. Callers check the features and send the headers.
 */
class PublicPage extends Base
{
    /** What a pasted project token shows as. */
    public const HIDDEN = '[hidden]';

    /** Core's layout.php line; if core changes it, nothing is removed and the page stays noindex. */
    public const ROBOTS_META = '<meta name="robots" content="noindex,nofollow">';

    /** Full HTML of $project's public page, with its own token hidden. */
    public function project(array $project, array $settings, bool $indexable): string
    {
        $projectId = (int) $project['id'];
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
        return $this->layout('Steward:public/project', [
            'title' => $project['name'],
            'project' => ['name' => (string) $project['name']],
            'settings' => $settings,
            'panels' => $panels,
        ], [(string) ($project['token'] ?? '')], $indexable);
    }

    /** Full HTML of the directory: name, tagline and link of each listed project, every listed token hidden. */
    public function directory(): string
    {
        $entries = [];
        $tokens = [];
        foreach ($this->listed() as ['project' => $project, 'settings' => $settings]) {
            $entries[] = [
                'name' => (string) $project['name'],
                'tagline' => $settings['tagline'],
                'url' => $this->helper->url->to('StewardExploreController', 'show',
                    ['plugin' => 'Steward', 'identifier' => trim((string) $project['identifier'])]),
            ];
            $tokens[] = (string) $project['token'];
        }
        return $this->layout('Steward:public/explore', [
            'title' => t('Explore'),
            'entries' => $entries,
        ], $tokens, true);
    }

    /**
     * Projects that pass every check: the public pages feature, Steward public and listed, core public access with
     * a token, an identifier (ProjectPageSettings::get() checks them again on read). Sorted by name, case-insensitively.
     *
     * @return list<array{project:array,settings:array}>
     */
    public function listed(): array
    {
        // Each listed page needs this feature; without it every link would 404.
        if (! $this->container['stewardFeatures']->isEnabled('public_pages')) {
            return [];
        }
        $ids = $this->db->table(ProjectPageSettings::TABLE)->eq('is_public', 1)->eq('is_listed', 1)->findAllByColumn('project_id');
        $pages = new ProjectPageSettings($this->container);
        $listed = [];
        foreach ($ids as $id) {
            $project = $this->projectModel->getById((int) $id);
            if (empty($project) || empty($project['is_public']) || (string) ($project['token'] ?? '') === '') {
                continue;
            }
            $settings = $pages->get((int) $id);
            if ($settings['public'] && $settings['listed']) {
                $listed[] = ['project' => $project, 'settings' => $settings];
            }
        }
        usort($listed, fn ($a, $b) => [mb_strtolower((string) $a['project']['name'], 'UTF-8'), (int) $a['project']['id']]
            <=> [mb_strtolower((string) $b['project']['name'], 'UTF-8'), (int) $b['project']['id']]);
        return $listed;
    }

    /** The listed project whose identifier matches, ignoring case; null for none (or, after a database edit, several). */
    public function findListed(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        $matches = array_values(array_filter($this->listed(),
            fn ($entry) => strcasecmp(trim((string) $entry['project']['identifier']), $identifier) === 0));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** Hides each token (any case) and, on an indexable page, drops core's robots meta. */
    public function finish(string $html, array $tokens, bool $indexable): string
    {
        $tokens = array_values(array_filter($tokens, fn ($token) => $token !== ''));
        if ($tokens !== []) {
            $html = str_ireplace($tokens, self::HIDDEN, $html);
        }
        if ($indexable) {
            $html = str_replace(self::ROBOTS_META, '', $html);
        }
        return $html;
    }

    /** Core's layout with no app header or scripts, plus the public stylesheet (only these pages load it). */
    private function layout(string $template, array $params, array $tokens, bool $indexable): string
    {
        $this->hook->on('template:layout:css', ['template' => 'plugins/Steward/Assets/css/public.css']);
        $html = $this->helper->layout->app($template, $params + ['no_layout' => true, 'not_editable' => true]);
        return $this->finish($html, $tokens, $indexable);
    }
}
