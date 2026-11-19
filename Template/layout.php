<?php
/*
 * Steward wrapper of core's layout (registered only while branding is on): where core's <title> would fall
 * back to "Kanboard", it shows the site name. Everything else is core's own file, included unchanged.
 */
if (! isset($page_title) && ! isset($title)) {
    $stewardName = $this->stewardBranding->siteName();
    if ($stewardName !== '') {
        $page_title = $stewardName;
    }
    unset($stewardName);
}
include $this->getTemplateFile('kanboard:layout');
