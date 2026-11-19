<?php
namespace Kanboard\Plugin\Steward;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\FeatureFlags;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchService;
use Kanboard\Plugin\Steward\Subscriber\LoginSubscriber;

class Plugin extends Base
{
    /** Priority of the second AclCheck/FeatureGate pass: after every default-priority listener, incl. other plugins' onStartup. */
    public const LATE_BOOTSTRAP = -1000;

    public function initialize(): void
    {
        $this->container['stewardFeatures'] = fn ($c) => new FeatureFlags($c);
        $this->container['stewardAudit'] = fn ($c) => new AuditLog($c);
        $this->container[AclCheck::FLAG] = false;
        $this->container['stewardAcl'] = fn ($c) => new AclCheck($c);
        $this->container['stewardSwitching'] = fn ($c) => new SwitchService($c);

        // ACL for every Steward controller (AclCheck::EXPECTED), whatever the switches say; FeatureGate hides disabled ones.
        $acl = $this->container['stewardAcl'];
        $acl->register();
        $this->route->addRoute('steward/settings', 'StewardSettingsController', 'show', 'Steward');
        $this->route->addRoute('steward/settings/save', 'StewardSettingsController', 'save', 'Steward');
        $this->route->addRoute('steward/audit', 'StewardAuditController', 'index', 'Steward');
        // app.bootstrap listeners run in registration order: AclCheck, then FeatureGate, then SwitchGuard
        // (added after these). Plugins loaded after Steward register onStartup on the same event after us, so
        // the check and the gate run again last, in case one of them replaced a rule.
        $gate = new FeatureGate($this->container);
        $this->dispatcher->addListener('app.bootstrap', [$acl, 'onBootstrap']);
        $this->dispatcher->addListener('app.bootstrap', [$gate, 'onBootstrap']);
        $this->dispatcher->addListener('app.bootstrap', [$acl, 'onBootstrap'], self::LATE_BOOTSTRAP);
        $this->dispatcher->addListener('app.bootstrap', [$gate, 'onBootstrap'], self::LATE_BOOTSTRAP);

        $this->dispatcher->addSubscriber(new LoginSubscriber($this->container));

        $this->hook->on('template:config:sidebar', ['template' => 'Steward:config/sidebar']);
    }

    public function getPluginName(): string        { return 'Steward'; }
    public function getPluginDescription(): string { return t('Admin tools, branding, Markdown and project pages for Kanboard.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '1.0.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-steward'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
