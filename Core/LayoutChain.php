<?php

namespace Kanboard\Plugin\Steward\Core;

use Kanboard\Core\Template;
use WeakMap;

/**
 * Harbor D7: layout wrappers chain. Steward records the layout registered before its own wrapper (core's, or
 * another plugin's wrapper such as Harbor's) and includes that, so wrappers stack in either load order.
 */
final class LayoutChain
{
    /** @var WeakMap<Template, string>|null */
    private static ?WeakMap $previous = null;

    public static function register(Template $template, string $wrapper): void
    {
        self::$previous ??= new WeakMap();
        if (isset(self::$previous[$template])) {
            return;
        }
        self::$previous[$template] = $template->getTemplateFile('layout');
        $template->setTemplateOverride('layout', $wrapper);
    }

    public static function previous(Template $template): string
    {
        return self::$previous[$template] ?? $template->getTemplateFile('kanboard:layout');
    }
}
