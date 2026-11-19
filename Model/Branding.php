<?php
namespace Kanboard\Plugin\Steward\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use RuntimeException;
use Throwable;

/**
 * Container key `stewardBranding`: the site name and the logo/favicon images (spec §3).
 *
 * Images live in ObjectStorage under steward/branding/<image>-<hash>.png; config keeps each path and the slot's
 * content hash. Replacing writes the new objects first, then swaps config, then deletes the old objects, so a
 * failure part-way never leaves config pointing at something missing. Reads go to the settings table directly
 * (getOption) rather than through the per-request config cache, which is stale after a save.
 */
class Branding extends Base
{
    public const PREFIX = 'steward/branding/';
    public const SITE_NAME_MAX = 60;

    /** Upload slot => its hash key and the images made from one upload (name => ImageProcessor target). */
    public const SLOTS = [
        'logo' => [
            'hash' => 'steward_logo_hash',
            'targets' => ['logo' => [512, 128, 'fit']],
        ],
        'favicon' => [
            'hash' => 'steward_favicon_hash',
            'targets' => ['favicon32' => [32, 32, 'cover'], 'favicon180' => [180, 180, 'cover']],
        ],
    ];

    /** Served image => [path key, hash key]. The allow-list for the image action. */
    public const IMAGES = [
        'logo' => ['steward_logo_path', 'steward_logo_hash'],
        'favicon32' => ['steward_favicon32_path', 'steward_favicon_hash'],
        'favicon180' => ['steward_favicon180_path', 'steward_favicon_hash'],
    ];

    /** Trimmed site name, or null when it is longer than SITE_NAME_MAX characters. */
    public static function cleanSiteName(string $name): ?string
    {
        $name = trim($name);
        return mb_strlen($name, 'UTF-8') > self::SITE_NAME_MAX ? null : $name;
    }

    public function siteName(): string
    {
        return mb_substr(trim((string) $this->configModel->getOption('steward_site_name', '')), 0, self::SITE_NAME_MAX, 'UTF-8');
    }

    /**
     * Stores a processed upload for $slot and points config at it. Throws RuntimeException when storage or
     * config fails; config and the old objects are then unchanged and any new object written is removed.
     *
     * @param array<string,string> $blobs image name => PNG bytes, exactly the slot's targets
     */
    public function save(string $slot, array $blobs, string $hash): void
    {
        $targets = $this->targets($slot);
        if (array_keys($blobs) !== array_keys($targets) || ! preg_match('/^[0-9a-f]{64}$/D', $hash)) {
            throw new InvalidArgumentException('Invalid branding images for slot '.$slot);
        }

        $old = $this->paths($slot);
        $new = [];
        $values = [self::SLOTS[$slot]['hash'] => $hash];
        foreach ($blobs as $image => $blob) {
            $new[$image] = self::PREFIX.$image.'-'.$hash.'.png';
            $values[self::IMAGES[$image][0]] = $new[$image];
        }

        $written = [];
        try {
            foreach ($blobs as $image => $blob) {
                $this->objectStorage->put($new[$image], $blob);
                $written[] = $new[$image];
            }
            if (! $this->configModel->save($values)) {
                throw new RuntimeException('config save failed');
            }
        } catch (Throwable $e) {
            // Same hash means same bytes: an object old config still points at is left in place.
            $this->removeObjects(array_diff($written, $old));
            $this->logger->error('Steward branding: could not store '.$slot.': '.$e->getMessage());
            throw new RuntimeException('Could not store the image', 0, $e);
        }

        $this->removeObjects(array_diff($old, $new));
    }

    /** Clears $slot; returns false when it was already empty. */
    public function remove(string $slot): bool
    {
        $old = $this->paths($slot);
        $values = [self::SLOTS[$slot]['hash'] => ''];
        foreach (array_keys($this->targets($slot)) as $image) {
            $values[self::IMAGES[$image][0]] = '';
        }
        if ($old === [] && $this->configModel->getOption(self::SLOTS[$slot]['hash'], '') === '') {
            return false;
        }
        if (! $this->configModel->save($values)) {
            throw new RuntimeException('Could not remove the image');
        }
        $this->removeObjects($old);
        return true;
    }

    /** @return array{blob:string,hash:string}|null null for an unknown or unset image, or a missing object. */
    public function image(string $image): ?array
    {
        if (! isset(self::IMAGES[$image])) {
            return null;
        }
        [$pathKey, $hashKey] = self::IMAGES[$image];
        $path = (string) $this->configModel->getOption($pathKey, '');
        $hash = (string) $this->configModel->getOption($hashKey, '');
        // Only our own prefix: config is never trusted to name another object, these are served publicly.
        if ($hash === '' || ! str_starts_with($path, self::PREFIX) || str_contains($path, '..')) {
            return null;
        }
        try {
            $blob = $this->objectStorage->get($path);
        } catch (Throwable $e) {
            $this->logger->error('Steward branding: '.$image.' object '.$path.' unreadable: '.$e->getMessage());
            return null;
        }
        return is_string($blob) && $blob !== '' ? ['blob' => $blob, 'hash' => $hash] : null;
    }

    /** Public URL of an image with its hash (long-cacheable), or '' when the image is unset. */
    public function imageUrl(string $image): string
    {
        if (! isset(self::IMAGES[$image])) {
            return '';
        }
        [$pathKey, $hashKey] = self::IMAGES[$image];
        $hash = (string) $this->configModel->getOption($hashKey, '');
        if ($hash === '' || $this->configModel->getOption($pathKey, '') === '') {
            return '';
        }
        return $this->helper->url->href('StewardBrandingController', 'image', ['plugin' => 'Steward', 'slot' => $image, 'h' => $hash]);
    }

    /** @return array<string,array{0:int,1:int,2:string}> */
    public function targets(string $slot): array
    {
        if (! isset(self::SLOTS[$slot])) {
            throw new InvalidArgumentException('Unknown branding slot '.$slot);
        }
        return self::SLOTS[$slot]['targets'];
    }

    /** @return array<string,string> image => stored path, set paths only */
    private function paths(string $slot): array
    {
        $paths = [];
        foreach (array_keys($this->targets($slot)) as $image) {
            $path = (string) $this->configModel->getOption(self::IMAGES[$image][0], '');
            if ($path !== '') {
                $paths[$image] = $path;
            }
        }
        return $paths;
    }

    /** Best effort: a leftover object costs disk space only, so failures are logged. */
    private function removeObjects(array $keys): void
    {
        foreach ($keys as $key) {
            if (! str_starts_with($key, self::PREFIX) || str_contains($key, '..')) {
                continue; // never delete outside our prefix, whatever config says
            }
            try {
                if ($this->objectStorage->remove($key) === false) {
                    $this->logger->error('Steward branding: could not delete '.$key);
                }
            } catch (Throwable $e) {
                $this->logger->error('Steward branding: could not delete '.$key.': '.$e->getMessage());
            }
        }
    }
}
