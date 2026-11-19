<?php
namespace Kanboard\Plugin\Steward;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
    }

    public function getPluginName(): string        { return 'Steward'; }
    public function getPluginDescription(): string { return t('Admin tools, branding, Markdown and project pages for Kanboard.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '1.0.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-steward'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
