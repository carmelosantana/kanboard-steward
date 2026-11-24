<?php
/* A layout wrapper registered before Steward's (Harbor-style): renders core's layout and marks <html>. */
ob_start();
include $this->getTemplateFile('kanboard:layout');
echo preg_replace('/<html\b/', '<html data-prior-wrapper="1"', (string) ob_get_clean(), 1);
