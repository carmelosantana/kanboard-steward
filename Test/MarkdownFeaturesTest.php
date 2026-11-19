<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Steward\Core\StewardMarkdown;

/** Spec §4 Markdown, additions: task lists, heading ids with anchors, Mermaid blocks. */
class MarkdownFeaturesTest extends Base
{
    private function parser(bool $public = false, ?string $token = null, bool $plain = false): StewardMarkdown
    {
        return new StewardMarkdown($this->container, $public, $token, $plain);
    }

    // --- task lists ----------------------------------------------------------------------------------------

    public function testUncheckedTaskItem(): void
    {
        $this->assertSame(
            "<ul>\n<li class=\"task-list-item\"><input type=\"checkbox\" disabled> todo</li>\n</ul>",
            $this->parser()->text('- [ ] todo')
        );
    }

    public function testCheckedTaskItemLowerAndCapitalX(): void
    {
        $expected = "<ul>\n<li class=\"task-list-item\"><input type=\"checkbox\" disabled checked> done</li>\n</ul>";
        $this->assertSame($expected, $this->parser()->text('- [x] done'));
        $this->assertSame($expected, $this->parser()->text('- [X] done'));
    }

    public function testEveryListMarkerTakesTaskItems(): void
    {
        foreach (['* [x] a', '+ [x] a'] as $source) {
            $this->assertStringContainsString('<li class="task-list-item"><input type="checkbox" disabled checked> a</li>', $this->parser()->text($source));
        }

        $this->assertSame(
            "<ol>\n<li class=\"task-list-item\"><input type=\"checkbox\" disabled> one</li>\n<li class=\"task-list-item\"><input type=\"checkbox\" disabled checked> two</li>\n</ol>",
            $this->parser()->text("1. [ ] one\n2. [x] two")
        );
    }

    public function testNestedTaskItems(): void
    {
        $html = $this->parser()->text("- [ ] parent\n    - [x] child\n    - plain");

        $this->assertStringContainsString('<li class="task-list-item"><input type="checkbox" disabled> parent', $html);
        $this->assertStringContainsString('<li class="task-list-item"><input type="checkbox" disabled checked> child</li>', $html);
        $this->assertStringContainsString('<li>plain</li>', $html);
    }

    public function testNonMatchingMarkersStayText(): void
    {
        $this->assertSame("<ul>\n<li>[y] maybe</li>\n</ul>", $this->parser()->text('- [y] maybe'));
        $this->assertSame("<ul>\n<li>[x]done</li>\n</ul>", $this->parser()->text('- [x]done'));
        $this->assertSame("<ul>\n<li>plain [x] later</li>\n</ul>", $this->parser()->text('- plain [x] later'));
        $this->assertSame('<p>[x] not a list</p>', $this->parser()->text('[x] not a list'));
    }

    public function testTaskItemTextIsStillEscaped(): void
    {
        $html = $this->parser()->text('- [ ] <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** A blank line between items makes the list loose: each item's text sits in a <p>, the checkbox with it. */
    public function testLooseTaskListPutsTheCheckboxInsideTheParagraph(): void
    {
        $this->assertSame(
            "<ul>\n<li class=\"task-list-item\">\n<p><input type=\"checkbox\" disabled> one</p>\n</li>\n"
            ."<li class=\"task-list-item\">\n<p><input type=\"checkbox\" disabled checked> two</p>\n</li>\n</ul>",
            $this->parser()->text("- [ ] one\n\n- [x] two")
        );
    }

    public function testUnsafeUrlInTaskItemIsNeutralised(): void
    {
        $html = $this->parser()->text("- [ ] [click](javascript:alert(1)) ![i](data:text/html,x)");

        $this->assertStringStartsWith('<ul>'."\n".'<li class="task-list-item"><input type="checkbox" disabled> ', $html);
        $this->assertNoActiveUrl($html);
        $this->assertStringContainsString('click', $html);
    }

    public function testUnsafeUrlInHeadingIsNeutralised(): void
    {
        $html = $this->parser()->text('## [click](javascript:alert(1)) ![i](data:text/html,x)');

        $this->assertStringStartsWith('<h2 id="md-click-i">', $html);
        $this->assertNoActiveUrl($html);
        $this->assertStringContainsString('<a class="md-anchor" href="#md-click-i">#</a>', $html);
    }

    // --- heading ids and anchors -----------------------------------------------------------------------------

    public function testHeadingGetsSlugIdAndAnchor(): void
    {
        $this->assertSame(
            '<h2 id="md-hello-world">Hello, World! <a class="md-anchor" href="#md-hello-world">#</a></h2>',
            $this->parser()->text('## Hello, World!')
        );
    }

    public function testSetextHeadingGetsAnId(): void
    {
        $this->assertStringStartsWith('<h1 id="md-title">Title ', $this->parser()->text("Title\n====="));
    }

    public function testSlugUsesRenderedTextOnly(): void
    {
        $html = $this->parser()->text('## **Bold** `code` & [link](https://example.com)');

        $this->assertStringStartsWith('<h2 id="md-bold-code-link">', $html);
    }

    public function testUnicodeIsStrippedFromSlugs(): void
    {
        $this->assertStringStartsWith('<h2 id="md-caf-d-j-vu">', $this->parser()->text('## Café déjà vu'));
        $this->assertStringStartsWith('<h2 id="md-section">', $this->parser()->text('## 日本語'));
    }

    public function testDuplicateSlugsAreNumberedPerRender(): void
    {
        $parser = $this->parser();
        $html = $parser->text("# Notes\n\n## Notes\n\n### notes\n\n## Notes 2");

        $this->assertSame(['md-notes', 'md-notes-2', 'md-notes-3', 'md-notes-2-2'], $this->ids($html));
        $this->assertSame(['md-notes'], $this->ids($parser->text('# Notes')), 'the counter resets for every text() call');
    }

    public function testHeadingWithTaskReferenceGetsIdFromItsText(): void
    {
        $taskId = $this->container['taskCreationModel']->create([
            'project_id' => $this->container['projectModel']->create(['name' => 'A']),
            'title' => 'Task',
        ]);

        $html = $this->parser()->text('## #'.$taskId.' notes');

        $this->assertMatchesRegularExpression(
            '!^<h2 id="md-'.$taskId.'-notes"><a href="[^"]*task_id='.$taskId.'[^"]*">#'.$taskId.'</a> notes <a class="md-anchor" href="#md-'.$taskId.'-notes">#</a></h2>$!',
            $html
        );
    }

    public function testHeadingMarkupStaysEscaped(): void
    {
        $html = $this->parser()->text('## "><script>x</script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringStartsWith('<h2 id="md-script-x-script">', $html);
    }

    // --- code and Mermaid ------------------------------------------------------------------------------------

    public function testFencedCodeKeepsLanguageClass(): void
    {
        $this->assertSame(
            "<pre><code class=\"language-php\">&lt;?php echo 1;</code></pre>",
            $this->parser()->text("```php\n<?php echo 1;\n```")
        );
    }

    public function testMermaidBlockIsEscapedOnce(): void
    {
        $html = $this->parser()->text("```mermaid\ngraph TD\n  A-->B[\"<script>x</script> & y\"]\n```");

        $this->assertSame(
            "<pre class=\"mermaid\">graph TD\n  A--&gt;B[\"&lt;script&gt;x&lt;/script&gt; &amp; y\"]</pre>",
            $html
        );
    }

    public function testMermaidInfoStringIsCaseInsensitiveAndTrimmed(): void
    {
        foreach (["``` Mermaid \nA\n```", "~~~MERMAID\nA\n~~~"] as $source) {
            $this->assertSame('<pre class="mermaid">A</pre>', $this->parser()->text($source));
        }
    }

    public function testOnlyAnExactMermaidInfoStringCounts(): void
    {
        $this->assertSame('<pre><code class="language-mermaid">A</code></pre>', $this->parser()->text("```mermaid extra\nA\n```"));
        $this->assertSame('<pre><code class="language-mermaidx">A</code></pre>', $this->parser()->text("```mermaidx\nA\n```"));
    }

    /** @return list<string> */
    /** No href/src outside the heading anchor's own fragment link. */
    private function assertNoActiveUrl(string $html): void
    {
        preg_match_all('/\b(?:href|src)="([^"]*)"/', $html, $m);
        $this->assertSame([], array_values(array_filter($m[1], fn ($u) => ! str_starts_with($u, '#md-'))), $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringNotContainsString('data:', $html);
    }

    private function ids(string $html): array
    {
        preg_match_all('/<h[1-6] id="([^"]*)"/', $html, $m);
        return $m[1];
    }
}
