<?php
namespace Kanboard\Plugin\Steward\Core;

use Kanboard\Core\Markdown;
use Pimple\Container;

/**
 * Spec §4 Markdown. Core's parser with HTML escaped, safe mode on, a URL scheme allow-list for links and images,
 * public-mode #N links limited to the project being served, and headings that keep a #N in their text.
 * Additions: read-only task lists, heading ids with an anchor link, and ```mermaid blocks as <pre class="mermaid">.
 */
class StewardMarkdown extends Markdown
{
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    private Container $c;
    private bool $public;
    private ?string $requestToken;
    private bool $plainRefs;

    /** @var array<string, true> heading ids handed out in the current text() call */
    private array $headingIds = [];

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

    public function text($text)
    {
        $this->headingIds = [];
        return parent::text($text);
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
        $name = $Element['name'] ?? '';
        $handler = $Element['handler'] ?? null;

        if ($handler === 'line' && preg_match('/^h[1-6]$/', $name) && isset($Element['text'])) {
            return $this->heading($Element);
        }
        if ($handler === 'li' && $name === 'li' && is_array($Element['text'] ?? null)) {
            $Element = $this->taskListItem($Element);
        }

        return parent::element($this->neutraliseUnsafeUrl($Element));
    }

    /**
     * `<hN id="md-slug">text <a class="md-anchor" href="#md-slug">#</a></hN>`. The slug comes from the rendered
     * heading's plain text: ASCII letters and digits, other runs joined by '-', 'md-section' when nothing is left,
     * '-2', '-3'… for repeats within one text() call.
     */
    private function heading(array $Element): string
    {
        $inner = $this->line($Element['text'], $Element['nonNestables'] ?? []);

        $plain = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($plain)), '-');
        $base = 'md-'.($slug === '' ? 'section' : $slug);
        $id = $base;
        for ($n = 2; isset($this->headingIds[$id]); $n++) {
            $id = $base.'-'.$n;
        }
        $this->headingIds[$id] = true;

        $anchor = $this->element(['name' => 'a', 'text' => '#', 'attributes' => ['class' => 'md-anchor', 'href' => '#'.$id]]);

        return parent::element([
            'name' => $Element['name'],
            'attributes' => ['id' => $id],
            'rawHtml' => $inner.' '.$anchor,
            'allowRawHtmlInSafeMode' => true,
        ]);
    }

    /** `[ ]`, `[x]` or `[X]` then a space (or nothing) at the start of an item: a disabled checkbox, marker removed. */
    private function taskListItem(array $Element): array
    {
        if (! preg_match('/^\[([ xX])\](?:[ \t]+|$)/', (string) ($Element['text'][0] ?? ''), $m)) {
            return $Element;
        }

        $Element['text'][0] = substr($Element['text'][0], strlen($m[0]));
        if ($Element['text'][0] === '' && count($Element['text']) > 1) {
            array_shift($Element['text']);
        }
        $Element['attributes']['class'] = 'task-list-item';
        $Element['handler'] = $m[1] === ' ' ? 'uncheckedTaskItem' : 'checkedTaskItem';

        return $Element;
    }

    protected function uncheckedTaskItem($lines): string
    {
        return '<input type="checkbox" disabled> '.$this->li($lines);
    }

    protected function checkedTaskItem($lines): string
    {
        return '<input type="checkbox" disabled checked> '.$this->li($lines);
    }

    /** Remember whether the info string is exactly `mermaid` (Parsedown keeps only its first word as the class). */
    protected function blockFencedCode($Line)
    {
        $Block = parent::blockFencedCode($Line);
        if ($Block !== null) {
            $info = trim(ltrim($Line['text'], $Line['text'][0]));
            $Block['mermaid'] = strtolower($info) === 'mermaid';
        }
        return $Block;
    }

    /** A mermaid block becomes `<pre class="mermaid">` holding the source, escaped once by element(). */
    protected function blockFencedCodeComplete($Block)
    {
        $Block = parent::blockFencedCodeComplete($Block);
        if (! empty($Block['mermaid'])) {
            $Block['element'] = [
                'name' => 'pre',
                'attributes' => ['class' => 'mermaid'],
                'text' => (string) $Block['element']['text']['text'],
            ];
        }
        return $Block;
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
     * Core replaces a whole heading with the first #N link it finds, and Parsedown's blockHeader trims every
     * leading '#' from the text, so `## #42 notes` lost its '#'. A line that starts with #N is a paragraph;
     * otherwise the heading/non-heading decision is Parsedown 1.7's (marker run of 1-6 '#', no space needed)
     * and the text keeps any #N, which is then linked inline. In `##42 x` the last marker '#' is the #N's.
     */
    protected function blockCustomHeader($Line)
    {
        $text = $Line['text'];
        if (preg_match('/^#\d/', $text) || ! isset($text[1])) {
            return null;
        }

        $level = strspn($text, '#');
        if ($level > 6) {
            return null;
        }

        $rest = substr($text, $level);
        if (preg_match('/^\d/', $rest)) {
            $rest = '#'.$rest;
        }

        return [
            'element' => [
                'name' => 'h'.$level,
                'text' => rtrim(ltrim($rest, ' '), '# '),
                'handler' => 'line',
            ],
        ];
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
