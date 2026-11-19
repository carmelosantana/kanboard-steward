<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

// Vendored assets are pinned in Assets/vendor/README.md; any drift between the file on disk and
// its recorded SHA-256 (or a file that ships unlisted) fails here.
class VendorTest extends Base
{
    private const DIR = __DIR__.'/../Assets/vendor';

    private const EXPECTED = ['highlight.min.js', 'highlight-github.css', 'mermaid.min.js'];

    private const LICENCES = ['LICENSE-highlightjs', 'LICENSE-mermaid'];

    private function readme(): string
    {
        $this->assertFileExists(self::DIR.'/README.md');

        return (string) file_get_contents(self::DIR.'/README.md');
    }

    /** README asset rows: | `file` | name | version | source | tarball path | sha256 | licence | */
    private function readmeHashes(): array
    {
        preg_match_all('/^\|\s*`([^`]+)`\s*\|(?:[^|]*\|){4}\s*`([0-9a-f]{64})`\s*\|/m', $this->readme(), $matches, PREG_SET_ORDER);

        return array_column($matches, 2, 1);
    }

    /** README licence rows: | `LICENSE-x` | `sha256` | */
    private function readmeLicenceHashes(): array
    {
        preg_match_all('/^\|\s*`(LICENSE-[^`]+)`\s*\|\s*`([0-9a-f]{64})`\s*\|\s*$/m', $this->readme(), $matches, PREG_SET_ORDER);

        return array_column($matches, 2, 1);
    }

    private function assertHashes(array $hashes): void
    {
        foreach ($hashes as $file => $sha256) {
            $this->assertFileExists(self::DIR.'/'.$file);
            $this->assertSame($sha256, hash_file('sha256', self::DIR.'/'.$file), $file);
        }
    }

    public function testVendorHashesMatchReadme(): void
    {
        $hashes = $this->readmeHashes();
        $this->assertEqualsCanonicalizing(self::EXPECTED, array_keys($hashes));
        $this->assertHashes($hashes);
    }

    public function testLicenceHashesMatchReadme(): void
    {
        $hashes = $this->readmeLicenceHashes();
        $this->assertEqualsCanonicalizing(self::LICENCES, array_keys($hashes));
        $this->assertHashes($hashes);
    }

    /** Every file in the directory except README.md and the licence texts must be a listed asset. */
    public function testEveryVendoredFileIsListed(): void
    {
        $shipped = array_diff(
            array_map('basename', glob(self::DIR.'/*')),
            ['README.md'],
            array_map('basename', glob(self::DIR.'/LICENSE-*'))
        );
        $this->assertEqualsCanonicalizing(array_keys($this->readmeHashes()), array_values($shipped));
    }
}
