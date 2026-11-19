<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Steward\Model\ImageProcessor;

class ImageProcessorTest extends Base
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const TARGETS = [
        'logo' => [512, 128, 'fit'],
        'favicon32' => [32, 32, 'cover'],
        'favicon180' => [180, 180, 'cover'],
    ];

    private string $tmpDir;
    private ImageProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/steward-img-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir);
        $this->processor = new ImageProcessor();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    /** Writes a GD-generated image with a transparent top-left pixel where the format allows it. */
    private function generate(string $format, int $width = 40, int $height = 20): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 20, 120, 220, 0));
        imagesetpixel($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $path = $this->tmpDir . '/in.' . $format;

        match ($format) {
            'png' => imagepng($im, $path),
            'jpg' => imagejpeg($im, $path, 80),
            'webp' => imagewebp($im, $path, 80),
            'gif' => (function () use ($im, $path) {
                $pal = imagecreate(imagesx($im), imagesy($im));
                $bg = imagecolorallocate($pal, 20, 120, 220);
                $clear = imagecolorallocate($pal, 255, 0, 255);
                imagefill($pal, 0, 0, $bg);
                imagesetpixel($pal, 0, 0, $clear);
                imagecolortransparent($pal, $clear);
                imagegif($pal, $path);
            })(),
            'bmp' => imagebmp($im, $path),
        };

        return $path;
    }

    private function processFile(string $path, array $targets = self::TARGETS, ?int $size = null): array
    {
        return $this->processor->process($path, $size ?? (int) filesize($path), $targets);
    }

    private function assertRejected(string $error, array $result): void
    {
        $this->assertFalse($result['ok']);
        $this->assertSame($error, $result['error']);
        $this->assertSame([], $result['blobs']);
        $this->assertSame('', $result['hash']);
    }

    /** @return GdImage the decoded output, after checking it is a PNG of the given size */
    private function assertPng(string $blob, int $width, int $height): \GdImage
    {
        $this->assertStringStartsWith(self::PNG_SIGNATURE, $blob);
        $im = imagecreatefromstring($blob);
        $this->assertInstanceOf(\GdImage::class, $im);
        $this->assertSame([$width, $height], [imagesx($im), imagesy($im)]);
        return $im;
    }

    public static function acceptedFormats(): array
    {
        return [['png'], ['jpg'], ['webp'], ['gif']];
    }

    /** @dataProvider acceptedFormats */
    public function testRasterFormatsAreReEncodedToPngAtEveryTarget(string $format): void
    {
        $result = $this->processFile($this->generate($format, 40, 20));

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertSame(['logo', 'favicon32', 'favicon180'], array_keys($result['blobs']));
        $this->assertPng($result['blobs']['logo'], 40, 20);       // fit never upscales
        $this->assertPng($result['blobs']['favicon32'], 32, 32);  // cover fills the box exactly
        $this->assertPng($result['blobs']['favicon180'], 180, 180);
    }

    public function testHashIsSha256OfTheBlobsInTargetOrder(): void
    {
        $result = $this->processFile($this->generate('png'));

        $this->assertSame(hash('sha256', implode('', $result['blobs'])), $result['hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['hash']);
    }

    public function testFitScalesDownKeepingAspectRatio(): void
    {
        $result = $this->processFile($this->generate('png', 1024, 512), ['logo' => [512, 128, 'fit']]);

        $this->assertPng($result['blobs']['logo'], 256, 128);
    }

    public function testCoverCropsWideImageToTheBoxCentre(): void
    {
        $path = $this->tmpDir . '/wide.png';
        $im = imagecreatetruecolor(300, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 0, 0));             // red sides
        imagefilledrectangle($im, 100, 0, 199, 99, imagecolorallocate($im, 0, 255, 0)); // green centre
        imagepng($im, $path);

        $out = $this->assertPng($this->processFile($path, ['f' => [32, 32, 'cover']])['blobs']['f'], 32, 32);

        $this->assertSame([0, 255, 0], array_values(array_slice(imagecolorsforindex($out, imagecolorat($out, 0, 0)), 0, 3)));
        $this->assertSame([0, 255, 0], array_values(array_slice(imagecolorsforindex($out, imagecolorat($out, 31, 31)), 0, 3)));
    }

    public static function alphaFormats(): array
    {
        return [['png'], ['webp'], ['gif']];
    }

    /** @dataProvider alphaFormats */
    public function testTransparencyIsPreserved(string $format): void
    {
        $result = $this->processFile($this->generate($format, 40, 20), ['logo' => [512, 128, 'fit']]);
        $out = $this->assertPng($result['blobs']['logo'], 40, 20);

        $this->assertSame(127, imagecolorsforindex($out, imagecolorat($out, 0, 0))['alpha']);
        $this->assertSame(0, imagecolorsforindex($out, imagecolorat($out, 20, 10))['alpha']);
    }

    public function testLargeJpegWithinLimitsIsAccepted(): void
    {
        $result = $this->processFile($this->generate('jpg', 4000, 3000));

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertPng($result['blobs']['logo'], 171, 128);
    }

    public function testPolyglotIsReEncodedWithoutTheScript(): void
    {
        $this->assertStringContainsString('<script', file_get_contents($this->fixture('polyglot.png')));

        $result = $this->processFile($this->fixture('polyglot.png'));

        $this->assertTrue($result['ok']);
        foreach ($result['blobs'] as $blob) {
            $this->assertStringStartsWith(self::PNG_SIGNATURE, $blob);
            $this->assertStringNotContainsStringIgnoringCase('<script', $blob);
        }
    }

    public function testSvgIsRejected(): void
    {
        $this->assertRejected('Unsupported image type', $this->processFile($this->fixture('logo.svg')));
    }

    public function testIcoIsRejected(): void
    {
        $this->assertRejected('Unsupported image type', $this->processFile($this->fixture('favicon.ico')));
    }

    public function testOtherDecodableFormatsAreRejected(): void
    {
        $this->assertRejected('Unsupported image type', $this->processFile($this->generate('bmp')));
    }

    public function testNonImageIsRejected(): void
    {
        $path = $this->tmpDir . '/notes.png';
        file_put_contents($path, "just text\n");

        $this->assertRejected('Unsupported image type', $this->processFile($path));
    }

    public function testFormatTheInstalledGdCannotReadIsRejected(): void
    {
        $processor = new class extends ImageProcessor {
            protected function canDecode(int $imageType): bool
            {
                return $imageType !== IMAGETYPE_WEBP;
            }
        };
        $path = $this->generate('webp');

        $this->assertRejected('Unsupported image type', $processor->process($path, (int) filesize($path), self::TARGETS));
    }

    public function testDecompressionBombHeaderIsRejectedBeforeDecode(): void
    {
        $this->assertRejected(
            'Image too large (max 4096 px a side, 16 megapixels)',
            $this->processFile($this->fixture('bomb-header.png'))
        );
    }

    public static function oversizedDimensions(): array
    {
        return [
            'too wide' => [4097, 1],
            'too tall' => [1, 4097],
            'too many pixels' => [4096, 3907], // 16,003,072 px
        ];
    }

    /** @dataProvider oversizedDimensions */
    public function testDimensionLimits(int $width, int $height): void
    {
        $this->assertRejected(
            'Image too large (max 4096 px a side, 16 megapixels)',
            $this->processFile($this->pngHeaderClaiming($width, $height))
        );
    }

    public function testMaximumDimensionsAreAccepted(): void
    {
        $result = $this->processFile($this->generate('png', 4096, 1), ['logo' => [512, 128, 'fit']]);

        $this->assertTrue($result['ok']);
        $this->assertPng($result['blobs']['logo'], 512, 1);
    }

    public function testZeroDimensionHeaderIsRejected(): void
    {
        $this->assertRejected('Could not read image', $this->processFile($this->pngHeaderClaiming(0, 10)));
    }

    public function testFileOverTwoMegabytesIsRejected(): void
    {
        $path = $this->generate('png');

        $this->assertRejected('Image too large (max 2 MB)', $this->processFile($path, self::TARGETS, 2 * 1024 * 1024 + 1));
    }

    public function testActualFileSizeIsCheckedNotJustTheReportedOne(): void
    {
        $path = $this->generate('png');
        file_put_contents($path, str_repeat("\0", 2 * 1024 * 1024), FILE_APPEND);

        $this->assertRejected('Image too large (max 2 MB)', $this->processFile($path, self::TARGETS, 100));
    }

    public function testTwoMegabytesExactlyIsAllowed(): void
    {
        $this->assertTrue($this->processFile($this->generate('png'), self::TARGETS, 2 * 1024 * 1024)['ok']);
    }

    public function testCorruptImageDataIsRejected(): void
    {
        $path = $this->tmpDir . '/corrupt.png';
        $png = file_get_contents($this->generate('png'));
        file_put_contents($path, substr($png, 0, 40)); // header intact, pixel data cut off

        $this->assertRejected('Could not read image', $this->processFile($path));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->assertRejected('Could not read image', $this->processor->process($this->tmpDir . '/nope.png', 10, self::TARGETS));
    }

    /** A real 1x1 PNG whose IHDR claims the given size. */
    private function pngHeaderClaiming(int $width, int $height): string
    {
        $png = file_get_contents($this->fixture('bomb-header.png'));
        $ihdr = pack('NN', $width, $height) . substr($png, 24, 5);
        $chunk = pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $path = $this->tmpDir . "/claims-{$width}x{$height}.png";
        file_put_contents($path, substr($png, 0, 8) . $chunk . substr($png, 33));
        return $path;
    }
}
