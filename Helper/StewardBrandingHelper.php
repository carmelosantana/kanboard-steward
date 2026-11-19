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

    /** Core's icon links, %s = url->dir(). */
    private const CORE_ICON_LINKS = [
        '<link rel="icon" href="%sassets/img/adaptive-favicon.svg" type="image/svg+xml">',
        '<link rel="icon" type="image/png" href="%sassets/img/favicon.png">',
        '<link rel="apple-touch-icon" href="%sassets/img/touch-icon-iphone.png">',
        '<link rel="apple-touch-icon" sizes="72x72" href="%sassets/img/touch-icon-ipad.png">',
        '<link rel="apple-touch-icon" sizes="114x114" href="%sassets/img/touch-icon-iphone-retina.png">',
        '<link rel="apple-touch-icon" sizes="144x144" href="%sassets/img/touch-icon-ipad-retina.png">',
    ];

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

    /**
     * $html without core's six icon links (layout.php on 1.2.47 and 1.2.57) while a Steward favicon is set, so a
     * browser cannot pick core's SVG over Steward's PNG. Unchanged unless every one of them matches exactly.
     */
    public function withoutCoreIcons(string $html): string
    {
        if ($this->faviconUrl(32) === '') {
            return $html;
        }
        $dir = $this->helper->url->dir();
        $links = array_map(fn ($line) => sprintf($line, $dir), self::CORE_ICON_LINKS);
        foreach ($links as $link) {
            if (substr_count($html, $link) !== 1) {
                return $html;
            }
        }
        // Each link with its indentation and line break, so no blank lines are left in the head.
        return preg_replace(array_map(fn ($link) => '/^[ \t]*'.preg_quote($link, '/').'\R?/m', $links), '', $html) ?? $html;
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
