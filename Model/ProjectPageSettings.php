<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;

/**
 * Per-project page settings (spec §5.1–5.4), one `steward_project_page` row per project; lists are JSON. Not
 * project metadata: core's API lets any project member write that. save() checks everything first and writes
 * nothing on any error. get() checks the stored row again and fails closed: anything invalid is dropped, and
 * public/listed hold only while core public access and the identifier still allow them. Values are stored as
 * typed; templates escape them on output.
 */
class ProjectPageSettings extends Base
{
    /** Every Home panel, in the order the settings form lists them. */
    public const HOME_PANELS = ['header', 'description', 'progress', 'board', 'activity', 'completed', 'files'];
    /** Panels safe on the public page: never files. */
    public const PUBLIC_PANELS = ['header', 'description', 'progress', 'board', 'completed', 'activity'];
    /** Spec §5.2 defaults. */
    public const DEFAULT_PANELS = ['header', 'description', 'progress', 'activity'];
    public const DEFAULT_PUBLIC_PANELS = ['header', 'description', 'progress'];

    public const TAGLINE_MAX = 160;
    public const LINKS_MAX = 5;
    public const LABEL_MAX = 40;
    public const URL_MAX = 2048;

    /** Changes to these are audited as `public.changed`. */
    private const AUDITED = ['public', 'listed', 'show_names'];
    private const BOOLEANS = ['public', 'listed', 'show_names'];

    public const TABLE = 'steward_project_page';

    /** Setting => column. */
    private const COLUMNS = [
        'tagline' => 'tagline',
        'links' => 'links',
        'panels' => 'panels',
        'public_panels' => 'public_panels',
        'public' => 'is_public',
        'listed' => 'is_listed',
        'show_names' => 'show_names',
    ];

    /** @return array{tagline:string, links:list<array{label:string,url:string}>, panels:list<string>, public_panels:list<string>, public:bool, listed:bool, show_names:bool} */
    public function get(int $projectId): array
    {
        $stored = $this->db->table(self::TABLE)->eq('project_id', $projectId)->findOne() ?: [];
        $raw = fn (string $name) => isset($stored[self::COLUMNS[$name]]) ? (string) $stored[self::COLUMNS[$name]] : null;
        $project = $this->projectModel->getById($projectId) ?: [];

        $tagline = $raw('tagline') ?? '';
        $tagline = self::isPlainLine($tagline) ? mb_substr($tagline, 0, self::TAGLINE_MAX, 'UTF-8') : '';

        $links = [];
        foreach ($this->decodeList($raw('links')) ?? [] as $link) {
            if (is_array($link) && is_string($link['label'] ?? null) && is_string($link['url'] ?? null)
                && self::isLabel($link['label']) && self::isWebUrl($link['url'])) {
                $links[] = ['label' => $link['label'], 'url' => $link['url']];
            }
        }

        $public = $raw('public') === '1' && ! empty($project['is_public']);
        return [
            'tagline' => $tagline,
            'links' => array_slice($links, 0, self::LINKS_MAX),
            'panels' => self::known($this->decodeList($raw('panels')), self::HOME_PANELS, self::DEFAULT_PANELS),
            'public_panels' => self::known($this->decodeList($raw('public_panels')), self::PUBLIC_PANELS, self::DEFAULT_PUBLIC_PANELS),
            'public' => $public,
            'listed' => $public && $raw('listed') === '1' && trim((string) ($project['identifier'] ?? '')) !== '',
            'show_names' => $raw('show_names') === '1',
        ];
    }

    /**
     * Keys left out of $values keep their stored value. Strings are trimmed; booleans must be real booleans.
     *
     * @return array<string,string> field => message; empty when saved
     */
    public function save(int $projectId, array $values): array
    {
        $project = $this->projectModel->getById($projectId);
        if (empty($project)) {
            return ['form' => t('Unable to save your settings.')];
        }
        $current = $this->get($projectId);
        [$new, $errors] = $this->validate($values + $current, $project);
        if ($errors !== []) {
            return $errors;
        }

        $row = ['updated_at' => time()];
        foreach (self::COLUMNS as $name => $column) {
            $value = $new[$name];
            $row[$column] = match (true) {
                is_bool($value) => $value ? 1 : 0,
                is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                default => $value,
            };
        }
        // One row, one statement: a save is all or nothing.
        $table = $this->db->table(self::TABLE);
        $saved = $this->db->table(self::TABLE)->eq('project_id', $projectId)->exists()
            ? $table->eq('project_id', $projectId)->update($row)
            : $table->insert(['project_id' => $projectId] + $row);
        if (! $saved) {
            return ['form' => t('Unable to save your settings.')];
        }

        $changes = [];
        foreach (self::AUDITED as $name) {
            if ($current[$name] !== $new[$name]) {
                $changes[$name] = [$current[$name], $new[$name]];
            }
        }
        if ($changes !== []) {
            $this->container['stewardAudit']->logInternal('public.changed', (int) $this->userSession->getId(), null,
                ['project_id' => $projectId, 'changes' => $changes]);
        }
        return [];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} cleaned values, errors */
    private function validate(array $values, array $project): array
    {
        $errors = [];

        $tagline = is_string($values['tagline']) ? trim($values['tagline']) : null;
        if ($tagline === null || ! self::isPlainLine($tagline)) {
            $errors['tagline'] = t('The tagline must be plain text on one line');
        } elseif (mb_strlen($tagline, 'UTF-8') > self::TAGLINE_MAX) {
            $errors['tagline'] = t('The tagline is too long (max %d characters)', self::TAGLINE_MAX);
        }

        [$links, $linkError] = $this->cleanLinks($values['links']);
        if ($linkError !== null) {
            $errors['links'] = $linkError;
        }

        if (! self::isSubset($values['panels'], self::HOME_PANELS)) {
            $errors['panels'] = t('Choose panels from the list, each once');
        }
        if (! self::isSubset($values['public_panels'], self::PUBLIC_PANELS)) {
            $errors['public_panels'] = t('Choose public panels from the list, each once');
        }

        foreach (self::BOOLEANS as $name) {
            if (! is_bool($values[$name])) {
                $errors[$name] = t('Invalid value');
            }
        }
        if (! isset($errors['public']) && $values['public'] === true && empty($project['is_public'])) {
            $errors['public'] = t('Turn on public access for this project first');
        }
        if (! isset($errors['listed']) && $values['listed'] === true) {
            if ($values['public'] !== true) {
                $errors['listed'] = t('Turn on the public page first');
            } elseif (trim((string) ($project['identifier'] ?? '')) === '') {
                $errors['listed'] = t('Set a project identifier first');
            }
        }

        if ($errors !== []) {
            return [[], $errors];
        }
        return [[
            'tagline' => $tagline,
            'links' => $links,
            'panels' => array_values($values['panels']),
            'public_panels' => array_values($values['public_panels']),
            'public' => $values['public'],
            'listed' => $values['listed'],
            'show_names' => $values['show_names'],
        ], []];
    }

    /** @return array{0:list<array{label:string,url:string}>,1:?string} */
    private function cleanLinks(mixed $links): array
    {
        if (! is_array($links) || ! array_is_list($links)) {
            return [[], t('Invalid links')];
        }
        if (count($links) > self::LINKS_MAX) {
            return [[], t('Up to %d links', self::LINKS_MAX)];
        }
        $clean = [];
        foreach ($links as $link) {
            if (! is_array($link) || ! is_string($link['label'] ?? null) || ! is_string($link['url'] ?? null)) {
                return [[], t('Invalid links')];
            }
            $label = trim($link['label']);
            $url = trim($link['url']);
            if (! self::isPlainLine($label)) {
                return [[], t('Link labels must be plain text on one line')];
            }
            if ($label === '') {
                return [[], t('Each link needs a label')];
            }
            if (mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
                return [[], t('Link labels are at most %d characters', self::LABEL_MAX)];
            }
            if (! self::isWebUrl($url)) {
                return [[], t('Links must start with http:// or https://')];
            }
            $clean[] = ['label' => $label, 'url' => $url];
        }
        return [$clean, null];
    }

    /** A link label as save() accepts it, trimmed already. */
    private static function isLabel(string $label): bool
    {
        return $label !== '' && self::isPlainLine($label) && mb_strlen($label, 'UTF-8') <= self::LABEL_MAX;
    }

    /** http or https (any case), a host, no whitespace or control characters anywhere. */
    private static function isWebUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::URL_MAX || preg_match('/[\s\x00-\x1F\x7F]/u', $url) !== 0) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    /** Valid UTF-8 with no control characters (so no line breaks). */
    private static function isPlainLine(string $value): bool
    {
        return preg_match('/^[^\x00-\x1F\x7F]*$/uD', $value) === 1;
    }

    private static function isSubset(mixed $list, array $allowed): bool
    {
        if (! is_array($list) || ! array_is_list($list)) {
            return false;
        }
        foreach ($list as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return false;
            }
        }
        return count(array_unique($list)) === count($list);
    }

    /** @return list<string> the known names in $list, each once, in its order; $default when nothing was stored */
    private static function known(?array $list, array $allowed, array $default): array
    {
        if ($list === null) {
            return $default;
        }
        return array_values(array_unique(array_filter($list, fn ($p) => is_string($p) && in_array($p, $allowed, true))));
    }

    /** Null when nothing usable was stored. */
    private function decodeList(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $list = json_decode($json, true);
        return is_array($list) && array_is_list($list) ? $list : null;
    }
}
