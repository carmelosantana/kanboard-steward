<?php
/*
 * Steward wrapper of core's layout (registered only while branding is on): where core's <title> would fall
 * back to "Kanboard", it shows the site name, and with a Steward favicon set core's own icon links are dropped
 * (spec §3 Applying). Everything else is the layout registered before this wrapper (core's, or another wrapper such as Harbor's; D7).
 */
if (! isset($page_title) && ! isset($title)) {
    $stewardName = $this->stewardBranding->siteName();
    if ($stewardName !== '') {
        $page_title = $stewardName;
    }
    unset($stewardName);
}
ob_start();
try {
    include \Kanboard\Plugin\Steward\Core\LayoutChain::previous($this);
} catch (\Throwable $e) {
    ob_end_clean();
    throw $e;
}
echo $this->stewardBranding->withoutCoreIcons((string) ob_get_clean());
