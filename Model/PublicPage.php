<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;
use Kanboard\Model\ProjectModel;
use Kanboard\Plugin\Steward\Helper\StewardTextHelper;

/**
 * Renders Steward's anonymous pages (spec §5.3–5.5) for StewardPublicController and StewardExploreController,
 * and decides which projects are listed. Every page goes through finish(): every project's core token, in any
 * form, is replaced with HIDDEN wherever it appears (a member may paste a core public link into the description,
 * tagline or a task title), and an indexable page drops core's robots meta. Callers check the features and send the headers.
 */
class PublicPage extends Base
{
    /** What a pasted project token shows as. */
    public const HIDDEN = '[hidden]';

    /** Core's layout.php line; if core changes it, nothing is removed and the page stays noindex. */
    public const ROBOTS_META = '<meta name="robots" content="noindex,nofollow">';

    /** Full HTML of $project's public page. */
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
        ], $indexable);
    }

    /** Full HTML of the directory: name, tagline and link of each listed project. */
    public function directory(): string
    {
        $entries = [];
        foreach ($this->listed() as ['project' => $project, 'settings' => $settings]) {
            $entries[] = [
                'name' => (string) $project['name'],
                'tagline' => $settings['tagline'],
                'url' => $this->helper->url->to('StewardExploreController', 'show',
                    ['plugin' => 'Steward', 'identifier' => trim((string) $project['identifier'])]),
            ];
        }
        return $this->layout('Steward:public/explore', [
            'title' => t('Explore'),
            'entries' => $entries,
        ], true);
    }

    /**
     * Projects that pass every check: the public pages feature, Steward public and listed, an active (not archived)
     * project, core public access with a token, an identifier (ProjectPageSettings::get() checks them again on read). Sorted by name, case-insensitively.
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
            // An archived project leaves the directory; its token page keeps core's behaviour.
            if (empty($project) || empty($project['is_active']) || empty($project['is_public'])
                || (string) ($project['token'] ?? '') === '') {
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

    /**
     * The listed project whose identifier matches, ignoring case but not trimmed (` ALPHA` is no match); null for
     * none (or, after a database edit, several).
     */
    public function findListed(string $identifier): ?array
    {
        if ($identifier === '') {
            return null;
        }
        $matches = array_values(array_filter($this->listed(),
            fn ($entry) => strcasecmp(trim((string) $entry['project']['identifier']), $identifier) === 0));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** Hides every project token (see hide()) and, on an indexable page, drops core's robots meta. */
    public function finish(string $html, bool $indexable): string
    {
        $html = self::hide($html, $this->db->table(ProjectModel::TABLE)->neq('token', '')->findAllByColumn('token'));
        if ($indexable) {
            $html = str_replace(self::ROBOTS_META, '', $html);
        }
        return $html;
    }

    /**
     * Replaces each of $tokens in $html with HIDDEN, in any case and in every form a browser shows or a server
     * accepts: literal, written with character references (&#97; &#x61; &#37;61), percent-encoded in whole or in
     * part, or split by markup in the visible text (abc<strong>d</strong>ef). Character references are decoded
     * first and %XX second, as a browser and then the server would. A match in the text drops the token's
     * characters and keeps the tags between them; the first span becomes HIDDEN.
     */
    public static function hide(string $html, array $tokens): string
    {
        $tokens = array_values(array_unique(array_filter(array_map(fn ($token) => strtolower((string) $token), $tokens),
            fn ($token) => $token !== '')));
        if ($tokens === []) {
            return $html;
        }

        // Units: each decoded byte, the offset its source starts at, and whether it sits inside a tag.
        $bytes = $inTag = '';
        $from = $referenceEnd = [];
        $tag = '0';
        for ($i = 0, $length = strlen($html); $i < $length;) {
            if ($html[$i] === '&' && preg_match('/\G&(#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);?/', $html, $m, 0, $i)) {
                $reference = '&'.$m[1].';';
                $decoded = html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($decoded === $reference && $m[1][0] === '#') {
                    // PHP leaves control characters such as &#13; undecoded; a browser reads them (CR it then drops
                    // from a URL). The C1 range maps to Windows-1252 there, which is never a token character either.
                    $code = $m[1][1] === 'x' || $m[1][1] === 'X' ? hexdec(substr($m[1], 2)) : (int) substr($m[1], 1);
                    $decoded = is_int($code) && $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF)
                        ? (string) mb_chr($code, 'UTF-8') : "\u{FFFD}";
                }
                if ($decoded !== $reference && $decoded !== '') {
                    $bytes .= $decoded;
                    $inTag .= str_repeat($tag, strlen($decoded));
                    array_push($from, ...array_fill(0, strlen($decoded), $i));
                    $i = $referenceEnd[$i] = $i + strlen($m[0]);
                    continue;
                }
            }
            if ($html[$i] === '<') {
                $tag = '1';
            }
            $bytes .= $html[$i];
            $inTag .= $tag;
            $from[] = $i;
            if ($html[$i] === '>') {
                $tag = '0';
            }
            $i++;
        }

        $hit = $first = [];
        // Two views: every unit but TAB, LF and CR inside a tag, which a browser drops from a URL (a token in an
        // attribute), and the text alone (a token split by markup). A raw `>` inside an attribute value would end the
        // tag early; core and Steward escape it as &gt;, and the all-units view reads those bytes anyway.
        foreach ([false, true] as $textOnly) {
            $units = [];
            for ($u = 0, $n = strlen($bytes); $u < $n; $u++) {
                if ($textOnly ? $inTag[$u] === '0' : ($inTag[$u] === '0' || ! in_array($bytes[$u], ["\t", "\n", "\r"], true))) {
                    $units[] = $u;
                }
            }
            // %XX decoded: view byte k comes from $units[$start[k]] up to, not including, $units[$start[k + 1]].
            $text = '';
            $start = [];
            for ($p = 0, $n = count($units); $p < $n; $p++) {
                $start[] = $p;
                $hex = $p + 2 < $n ? $bytes[$units[$p + 1]].$bytes[$units[$p + 2]] : '';
                if ($bytes[$units[$p]] === '%' && ctype_xdigit($hex)) {
                    $text .= chr((int) hexdec($hex));
                    $p += 2;
                } else {
                    $text .= $bytes[$units[$p]];
                }
            }
            $start[] = $n;
            $text = strtolower($text);
            foreach ($tokens as $token) {
                for ($at = strpos($text, $token); $at !== false; $at = strpos($text, $token, $at + 1)) {
                    $first[$from[$units[$start[$at]]]] = true;
                    $end = $start[$at + strlen($token)];
                    if ($textOnly) {
                        // Only the token's text goes; the tags between its characters stay.
                        for ($p = $start[$at]; $p < $end; $p++) {
                            $hit[$from[$units[$p]]] = true;
                        }
                    } else {
                        // Every unit from its first to its last, the dropped TAB/LF/CR included.
                        for ($u = $units[$start[$at]], $last = $units[$end - 1]; $u <= $last; $u++) {
                            $hit[$from[$u]] = true;
                        }
                    }
                }
            }
        }

        // Each hit source span (a byte, or a whole character reference) goes; the first of each match becomes HIDDEN.
        ksort($hit);
        $out = '';
        $cursor = 0;
        foreach (array_keys($hit) as $offset) {
            $out .= substr($html, $cursor, $offset - $cursor).(isset($first[$offset]) ? self::HIDDEN : '');
            $cursor = $referenceEnd[$offset] ?? $offset + 1;
        }
        return $out.substr($html, $cursor);
    }

    /** Core's layout with no app header or scripts, plus the public stylesheet (only these pages load it). */
    private function layout(string $template, array $params, bool $indexable): string
    {
        $this->hook->on('template:layout:css', ['template' => 'plugins/Steward/Assets/css/public.css']);
        $html = $this->helper->layout->app($template, $params + [
            'no_layout' => true,
            'not_editable' => true,
            // Spec §7, Decision 20: the announcement banner when set to show here; forViewer() never offers Dismiss here.
            'steward_announcement' => $this->container['stewardBanner']->forViewer(Banner::PUBLIC),
        ]);
        return $this->finish($html, $indexable);
    }
}
