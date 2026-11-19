<?php
namespace Kanboard\Plugin\Steward;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Steward\Model\FeatureFlags;
use Kanboard\Plugin\Steward\Model\FeatureGate;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->container['stewardFeatures'] = fn ($c) => new FeatureFlags($c);

        // ACL for every Steward controller, whatever the switches say; FeatureGate hides disabled ones.
        $this->applicationAccessMap->add('StewardSettingsController', ['show', 'save'], Role::APP_ADMIN);
        $this->route->addRoute('steward/settings', 'StewardSettingsController', 'show', 'Steward');
        $this->route->addRoute('steward/settings/save', 'StewardSettingsController', 'save', 'Steward');
        // app.bootstrap listeners run in registration order: AclCheck (added before this line),
        // then FeatureGate, then SwitchGuard (added after it).
        $this->dispatcher->addListener('app.bootstrap', [new FeatureGate($this->container), 'onBootstrap']);

        $this->hook->on('template:config:sidebar', ['template' => 'Steward:config/sidebar']);
    }

    public function getPluginName(): string        { return 'Steward'; }
    public function getPluginDescription(): string { return t('Admin tools, branding, Markdown and project pages for Kanboard.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '1.0.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-steward'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
