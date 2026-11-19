<?php
namespace Kanboard\Plugin\Steward\Helper;

use Kanboard\Helper\TextHelper;
use Kanboard\Plugin\Steward\Core\StewardMarkdown;

/**
 * Spec §4: replaces core's `text` helper while the markdown feature is on, so templates, notification emails and
 * the editor preview all render through StewardMarkdown.
 */
class StewardTextHelper extends TextHelper
{
    /**
     * Same signature as core. In public mode #N links only to tasks of the project whose token is in the request.
     *
     * @param  string|null $text
     * @param  boolean     $isPublicLink
     * @return string
     */
    public function markdown($text, $isPublicLink = false)
    {
        $token = null;
        if ($isPublicLink) {
            $param = $this->request->getStringParam('token');
            $token = is_string($param) && $param !== '' ? $param : null;
        }

        return (new StewardMarkdown($this->container, (bool) $isPublicLink, $token))->text($text ?: '');
    }

    /** Public-page rendering: #N and @user stay plain text. */
    public function markdownPublic(?string $text): string
    {
        return (new StewardMarkdown($this->container, true, null, true))->text($text ?? '');
    }
}
