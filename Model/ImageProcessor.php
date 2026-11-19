<?php
namespace Kanboard\Plugin\Steward\Model;

use GdImage;
use InvalidArgumentException;

/**
 * Branding upload pipeline (spec §3, Decision 13): PNG/JPEG/WebP/GIF in, PNG out via GD.
 *
 * Every check that can be made from the header runs before GD decodes a single pixel, so a file that
 * claims huge dimensions is refused without allocating memory for it. The decoder is picked from the
 * type getimagesize reported, never sniffed again, and the output is a fresh PNG encode, so nothing but
 * pixels survives (no metadata chunks, no trailing bytes). Core's Thumbnail isn't used: it decodes before
 * checking, always upscales and frees its source after one output.
 *
 * Pure: reads $tmpPath, returns bytes, never touches storage.
 */
class ImageProcessor
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_SIDE = 4096;
    public const MAX_PIXELS = 16_000_000;

    public const ERROR_FILE_TOO_LARGE = 'Image too large (max 2 MB)';
    public const ERROR_UNSUPPORTED = 'Unsupported image type';
    public const ERROR_DIMENSIONS = 'Image too large (max 4096 px a side, 16 megapixels)';
    public const ERROR_UNREADABLE = 'Could not read image';
    public const ERROR_ENCODE = 'Could not process image';

    private const DECODERS = [
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        IMAGETYPE_GIF => 'imagecreatefromgif',
    ];

    private const GD_FLAGS = [
        IMAGETYPE_PNG => IMG_PNG,
        IMAGETYPE_JPEG => IMG_JPG,
        IMAGETYPE_WEBP => IMG_WEBP,
        IMAGETYPE_GIF => IMG_GIF,
    ];

    /**
     * @param array<string, array{0:int, 1:int, 2:'fit'|'cover'}> $targets name => [width, height, mode];
     *        'fit' scales down to fit inside the box keeping the aspect ratio (never up), 'cover' scales and
     *        centre-crops to exactly the box.
     * @return array{ok:bool, error:?string, blobs:array<string,string>, hash:string} blobs are PNG bytes
     *         keyed like $targets; hash is sha256 of the blobs concatenated in target order ('' on error).
     */
    public function process(string $tmpPath, int $sizeBytes, array $targets): array
    {
        foreach ($targets as $name => $target) {
            $this->assertTarget($name, $target);
        }

        if (! is_file($tmpPath) || ! is_readable($tmpPath)) {
            return $this->fail(self::ERROR_UNREADABLE);
        }

        if ($sizeBytes > self::MAX_BYTES || (int) filesize($tmpPath) > self::MAX_BYTES) {
            return $this->fail(self::ERROR_FILE_TOO_LARGE);
        }

        $info = @getimagesize($tmpPath);
        $type = is_array($info) ? (int) $info[2] : 0;

        if (! isset(self::DECODERS[$type]) || ! $this->canDecode($type)) {
            return $this->fail(self::ERROR_UNSUPPORTED);
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width <= 0 || $height <= 0) {
            return $this->fail(self::ERROR_UNREADABLE);
        }

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            return $this->fail(self::ERROR_DIMENSIONS);
        }

        $source = $this->decode($type, $tmpPath, $width, $height);

        if ($source === null) {
            return $this->fail(self::ERROR_UNREADABLE);
        }

        $blobs = [];

        foreach ($targets as $name => [$boxWidth, $boxHeight, $mode]) {
            $blob = $this->encode($this->resize($source, $boxWidth, $boxHeight, $mode));

            if ($blob === null) {
                return $this->fail(self::ERROR_ENCODE);
            }

            $blobs[$name] = $blob;
        }

        return ['ok' => true, 'error' => null, 'blobs' => $blobs, 'hash' => hash('sha256', implode('', $blobs))];
    }

    /** Whether the installed GD can read this type; a seam for the "GD built without WebP" case. */
    protected function canDecode(int $imageType): bool
    {
        return function_exists(self::DECODERS[$imageType]) && (imagetypes() & self::GD_FLAGS[$imageType]) !== 0;
    }

    private function decode(int $type, string $path, int $width, int $height): ?GdImage
    {
        $image = @(self::DECODERS[$type])($path);

        if (! $image instanceof GdImage || imagesx($image) !== $width || imagesy($image) !== $height) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    private function resize(GdImage $source, int $boxWidth, int $boxHeight, string $mode): GdImage
    {
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);
        [$srcX, $srcY, $cropWidth, $cropHeight] = [0, 0, $srcWidth, $srcHeight];

        if ($mode === 'fit') {
            $scale = min(1, $boxWidth / $srcWidth, $boxHeight / $srcHeight);
            $dstWidth = max(1, (int) round($srcWidth * $scale));
            $dstHeight = max(1, (int) round($srcHeight * $scale));
        } else {
            $scale = max($boxWidth / $srcWidth, $boxHeight / $srcHeight);
            $cropWidth = max(1, min($srcWidth, (int) round($boxWidth / $scale)));
            $cropHeight = max(1, min($srcHeight, (int) round($boxHeight / $scale)));
            $srcX = intdiv($srcWidth - $cropWidth, 2);
            $srcY = intdiv($srcHeight - $cropHeight, 2);
            [$dstWidth, $dstHeight] = [$boxWidth, $boxHeight];
        }

        $target = imagecreatetruecolor($dstWidth, $dstHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $dstWidth, $dstHeight, $cropWidth, $cropHeight);

        return $target;
    }

    private function encode(GdImage $image): ?string
    {
        ob_start();
        $ok = imagepng($image);
        $blob = (string) ob_get_clean();

        return $ok && str_starts_with($blob, "\x89PNG\r\n\x1a\n") ? $blob : null;
    }

    private function assertTarget(int|string $name, mixed $target): void
    {
        if (! is_string($name) || ! is_array($target) || count($target) !== 3
            || ! is_int($target[0] ?? null) || ! is_int($target[1] ?? null)
            || $target[0] < 1 || $target[1] < 1 || ! in_array($target[2] ?? null, ['fit', 'cover'], true)) {
            throw new InvalidArgumentException('Invalid image target: ' . $name);
        }
    }

    /** @return array{ok:false, error:string, blobs:array{}, hash:''} */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'blobs' => [], 'hash' => ''];
    }
}
