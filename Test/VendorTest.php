<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

// Vendored assets are pinned in Assets/vendor/README.md; any drift between the file on disk and
// its recorded SHA-256 (or a file that ships unlisted) fails here.
class VendorTest extends Base
{
    private const DIR = __DIR__.'/../Assets/vendor';

    private const EXPECTED = ['highlight.min.js', 'highlight-github.css', 'mermaid.min.js'];

    /** README table rows: | `file` | name | version | source | tarball path | sha256 | licence | */
    private function readmeHashes(): array
    {
        $this->assertFileExists(self::DIR.'/README.md');
        preg_match_all(
            '/^\|\s*`([^`]+)`\s*\|(?:[^|]*\|){4}\s*`([0-9a-f]{64})`\s*\|/m',
            file_get_contents(self::DIR.'/README.md'),
            $matches,
            PREG_SET_ORDER
        );

        $hashes = [];
        foreach ($matches as $match) {
            $hashes[$match[1]] = $match[2];
        }

        return $hashes;
    }

    public function testVendorHashesMatchReadme(): void
    {
        $hashes = $this->readmeHashes();
        $this->assertSame(self::EXPECTED, array_keys($hashes));

        foreach ($hashes as $file => $sha256) {
            $this->assertFileExists(self::DIR.'/'.$file);
            $this->assertSame($sha256, hash_file('sha256', self::DIR.'/'.$file), $file);
        }
    }

    public function testEveryVendoredAssetIsListed(): void
    {
        $shipped = array_map('basename', glob(self::DIR.'/*.{js,css}', GLOB_BRACE));
        sort($shipped);
        $listed = array_keys($this->readmeHashes());
        sort($listed);
        $this->assertSame($listed, $shipped);
    }
}
