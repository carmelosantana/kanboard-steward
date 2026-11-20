<?php
// Ways a project token can be written into a public page, and the check that none of them got through.

trait StewardTokenForms
{
    /** Each byte as %XX; odd positions escape the upper-case letter, so %41 and %61 both turn up. */
    private static function percent(string $text): string
    {
        $out = '';
        foreach (str_split($text) as $i => $char) {
            $out .= '%'.strtoupper(bin2hex($i % 2 ? strtoupper($char) : $char));
        }
        return $out;
    }

    /** No form of $token is left: not literally, not after the browser decodes references and the server decodes %XX. */
    private function assertTokenGone(string $token, string $html): void
    {
        $this->assertNotSame('', $token);
        $this->assertStringNotContainsStringIgnoringCase($token, $html);
        $decode = fn (string $html) => rawurldecode(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringNotContainsStringIgnoringCase($token, $decode($html));
        // The visible text, as copied from the page: markup in the middle of a token doesn't split it.
        $this->assertStringNotContainsStringIgnoringCase($token, $decode(strip_tags($html)));
    }
}
