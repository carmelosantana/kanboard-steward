<?php
namespace Kanboard\Plugin\Steward\Helper;

use Kanboard\Core\Base;
use Kanboard\Plugin\Steward\Model\Branding;

/**
 * `$this->helper->stewardBranding` for templates and other plugins (spec §3 Helper). Every method returns ''
 * when branding is off or the slot is unset. URLs are HTML-attribute ready (`&amp;`), as `url->href()` gives.
 */
class StewardBrandingHelper extends Base
{
    /** Favicon size => served image. */
    private const FAVICONS = [32 => 'favicon32', 180 => 'favicon180'];

    /** Steward's logo; while that slot is empty, ShadcnTheme's uploaded logo (spec §5.6), so no logo disappears. */
    public function logoUrl(): string
    {
        if (! $this->on()) {
            return '';
        }
        $url = $this->branding()->imageUrl('logo');
        return $url !== '' ? $url : $this->shadcnLogoUrl();
    }

    /** 32 or 180 (apple-touch); any other size is ''. */
    public function faviconUrl(int $size): string
    {
        if (! $this->on() || ! isset(self::FAVICONS[$size])) {
            return '';
        }
        return $this->branding()->imageUrl(self::FAVICONS[$size]);
    }

    public function siteName(): string
    {
        return $this->on() ? $this->branding()->siteName() : '';
    }

    /** ShadcnTheme 1.1's serveAsset URL while it still has that action and a logo is set there. */
    private function shadcnLogoUrl(): string
    {
        if ((string) $this->configModel->getOption(Branding::SHADCN_KEYS['logo'], '') === ''
            || ! method_exists('Kanboard\Plugin\ShadcnTheme\Controller\SettingsController', 'serveAsset')) {
            return '';
        }
        return $this->helper->url->href('SettingsController', 'serveAsset', ['plugin' => 'ShadcnTheme', 'slot' => 'logo']);
    }

    private function on(): bool
    {
        return $this->container['stewardFeatures']->isEnabled('branding');
    }

    private function branding(): Branding
    {
        return $this->container['stewardBranding'];
    }
}
