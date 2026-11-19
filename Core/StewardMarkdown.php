<?php
namespace Kanboard\Plugin\Steward\Core;

use Kanboard\Core\Markdown;
use Pimple\Container;

/**
 * Spec §4 Markdown. Core's parser with HTML escaped, safe mode on, a URL scheme allow-list for links and images,
 * public-mode #N links limited to the project being served, and headings that keep a #N in their text.
 */
class StewardMarkdown extends Markdown
{
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    private Container $c;
    private bool $public;
    private ?string $requestToken;
    private bool $plainRefs;

    /**
     * @param bool        $isPublicLink #N links use the request project's token, @user stays text (core rule)
     * @param string|null $requestToken token of the page being served; null in public mode means no #N links
     * @param bool        $plainRefs    #N and @user always stay plain text (Steward's own public pages)
     */
    public function __construct(Container $c, bool $isPublicLink, ?string $requestToken = null, bool $plainRefs = false)
    {
        parent::__construct($c, $isPublicLink);
        $this->c = $c;
        $this->public = $isPublicLink;
        $this->requestToken = $requestToken === '' ? null : $requestToken;
        $this->plainRefs = $plainRefs;
        $this->setMarkupEscaped(true);
        $this->setSafeMode(true);
        $this->setBreaksEnabled(true);
    }

    /**
     * True when $url may be emitted as an href/src: http, https, mailto, or relative (no scheme, not //host).
     * Decided on what a browser would see: entities decoded, controls and whitespace removed, lower-cased.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $decoded = $url;
        do {
            $previous = $decoded;
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } while ($decoded !== $previous);

        $normalised = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $decoded));
        $normalised = str_replace('\\', '/', $normalised);

        if (str_starts_with($normalised, '//')) {
            return false;
        }
        if (preg_match('/^([^\/?#]*):/', $normalised, $m)) {
            return in_array($m[1], self::ALLOWED_SCHEMES, true);
        }
        return true;
    }

    /**
     * Runs for every element whether or not safe mode is on: a link with a disallowed URL becomes its text, an
     * image its alt text, both inside a bare <span>.
     */
    protected function element(array $Element)
    {
        return parent::element($this->neutraliseUnsafeUrl($Element));
    }

    /** Parsedown's own list would keep data:image and other schemes; Steward's allow-list replaces it. */
    protected function filterUnsafeUrlInAttribute(array $Element, $attribute)
    {
        return $this->neutraliseUnsafeUrl($Element);
    }

    private function neutraliseUnsafeUrl(array $Element): array
    {
        $attribute = ['a' => 'href', 'img' => 'src'][$Element['name'] ?? ''] ?? null;
        if ($attribute === null || ! isset($Element['attributes'][$attribute])) {
            return $Element;
        }
        if (self::isAllowedUrl((string) $Element['attributes'][$attribute])) {
            return $Element;
        }

        if ($Element['name'] === 'img') {
            return ['name' => 'span', 'text' => (string) ($Element['attributes']['alt'] ?? '')];
        }

        unset($Element['attributes']);
        $Element['name'] = 'span';
        return $Element;
    }

    /**
     * Core replaces a whole heading with the first #N link it finds. A line that starts with #N (no heading
     * marker run) is a paragraph; anything else is a normal heading whose #N is linked inline.
     */
    protected function blockCustomHeader($Line)
    {
        if (preg_match('/^#\d/', $Line['text'])) {
            return null;
        }
        return $this->blockHeader($Line);
    }

    protected function inlineTaskLink(array $Excerpt)
    {
        if ($this->plainRefs || ! preg_match('!^#(\d+)!', $Excerpt['text'], $matches)) {
            return null;
        }

        $link = $this->taskUrl((int) $matches[1]);
        if ($link === '') {
            return null;
        }

        return [
            'extent' => strlen($matches[0]),
            'element' => [
                'name' => 'a',
                'text' => $matches[0],
                'attributes' => ['href' => $link],
            ],
        ];
    }

    protected function inlineUserLink(array $Excerpt)
    {
        return $this->plainRefs ? null : parent::inlineUserLink($Excerpt);
    }

    private function taskUrl(int $taskId): string
    {
        if (! $this->public) {
            return $this->c['helper']->url->to('TaskViewController', 'show', ['task_id' => $taskId]);
        }
        if ($this->requestToken === null) {
            return '';
        }

        $token = $this->c['memoryCache']->proxy($this->c['taskFinderModel'], 'getProjectToken', $taskId);
        if (! is_string($token) || ! hash_equals($this->requestToken, $token)) {
            return '';
        }

        return $this->c['helper']->url->to(
            'TaskViewController',
            'readonly',
            ['token' => $token, 'task_id' => $taskId],
            '',
            true
        );
    }
}
