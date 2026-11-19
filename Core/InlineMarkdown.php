<?php
namespace Kanboard\Plugin\Steward\Core;

/**
 * Spec §7: the announcement banner's one-line renderer. Everything is escaped first; then only *em* or _em_,
 * **strong** or __strong__, `code` (nothing formatted inside) and [text](url) with an http(s) URL that has a host
 * become markup. Anything else (HTML, images, headings, lists, code blocks, Mermaid, other link schemes) stays
 * literal text. Newlines become spaces.
 */
final class InlineMarkdown
{
    /** Marks a finished fragment while the rest is converted. render() turns every control character in the input into a space first, so the text cannot forge one. */
    private const MARK = "\x01";

    public static function render(string $text): string
    {
        // Single line; every other control character becomes a space too, so MARK cannot be forged.
        $text = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', str_replace("\r\n", "\n", $text));
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $fragments = [];
        $keep = function (string $fragment) use (&$fragments): string {
            $fragments[] = $fragment;
            return self::MARK.(count($fragments) - 1).self::MARK;
        };

        // `code`, but not a run of two or more backticks (a fenced block stays text).
        $html = (string) preg_replace_callback('/(?<!`)`([^`]+)`(?!`)/u',
            fn ($m) => $keep('<code>'.$m[1].'</code>'), $html);

        // [text](url), not ![image](url). The URL has no spaces or parentheses.
        $html = (string) preg_replace_callback('/(?<!!)\[([^\[\]]+)\]\(([^()\s]+)\)/u', function ($m) use ($keep) {
            $url = htmlspecialchars_decode($m[2], ENT_QUOTES);
            if (! self::isWebUrl($url)) {
                return $m[0];
            }
            return $keep('<a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="noopener noreferrer">'
                .self::emphasis($m[1], $keep).'</a>');
        }, $html);

        $html = self::emphasis($html, $keep);

        // A fragment can hold others (`code` inside link text), so restore until none is left. Each pass removes
        // a nesting level and fragments only point at earlier ones, so count($fragments) passes always suffice.
        for ($pass = 0; $pass <= count($fragments) && str_contains($html, self::MARK); $pass++) {
            $html = (string) preg_replace_callback('/'.self::MARK.'(\d+)'.self::MARK.'/',
                fn ($m) => $fragments[(int) $m[1]] ?? '', $html);
        }
        // Belt and braces: no control byte ever reaches the page.
        return str_replace(self::MARK, '', $html);
    }

    /** True for an http(s) URL with a host, decided on what a browser would see (Task 16's normalisation). */
    public static function isWebUrl(string $url): bool
    {
        $decoded = $url;
        do {
            $previous = $decoded;
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } while ($decoded !== $previous);
        $normalised = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $decoded));
        $normalised = str_replace('\\', '/', $normalised);

        if ($normalised !== strtolower(trim($url)) || ! preg_match('#^https?://[^/?\#]#', $normalised)) {
            return false;
        }
        $host = parse_url($normalised, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }

    /**
     * Strong before em. Each match becomes a finished fragment (its inside converted first), so a later, overlapping
     * marker can only wrap whole fragments or stay literal: tags always nest (`***a***` gives `<strong>*a</strong>*`).
     *
     * @param callable(string):string $keep
     */
    private static function emphasis(string $html, callable $keep): string
    {
        $patterns = [
            '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u' => 'strong',
            '/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/u' => 'strong',
            '/\*(?=[^\s*])(.+?)(?<=[^\s*])\*/u' => 'em',
            '/(?<![\p{L}\p{N}_])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\p{L}\p{N}_])/u' => 'em',
        ];
        foreach ($patterns as $pattern => $tag) {
            $html = (string) preg_replace_callback($pattern,
                fn ($m) => $keep('<'.$tag.'>'.self::emphasis($m[1], $keep).'</'.$tag.'>'), $html);
        }
        return $html;
    }
}
