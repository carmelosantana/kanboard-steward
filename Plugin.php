<?php
namespace Kanboard\Plugin\Steward;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Steward\Helper\StewardBrandingHelper;
use Kanboard\Plugin\Steward\Helper\StewardTextHelper;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\Branding;
use Kanboard\Plugin\Steward\Model\FeatureFlags;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchGuard;
use Kanboard\Plugin\Steward\Model\SwitchService;
use Kanboard\Plugin\Steward\Subscriber\LoginSubscriber;
use Kanboard\Plugin\Steward\Subscriber\SwitchSubscriber;
use Pimple\Exception\FrozenServiceException;

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
        $this->container['stewardBranding'] = fn ($c) => new Branding($c);
        // Always registered so other plugins can call it; it answers '' while branding is off.
        $this->helper->register('stewardBranding', StewardBrandingHelper::class);
        // Spec §4: one swap of the text helper covers templates, notification emails and the editor preview.
        // A plugin loaded earlier that already used helper->text froze the entry; carry on without the swap rather
        // than let the exception (caught by core's Loader) skip the rest of initialize().
        if ($this->container['stewardFeatures']->isEnabled('markdown')) {
            try {
                $this->helper->register('text', StewardTextHelper::class);
            } catch (FrozenServiceException $e) {
                $this->logger->error('Steward: text helper already in use before Steward loaded; safer Markdown is off');
            }
            $this->registerMarkdownAssets();
        }

        // ACL for every Steward controller (AclCheck::EXPECTED), whatever the switches say; FeatureGate hides disabled ones.
        $acl = $this->container['stewardAcl'];
        $acl->register();
        $this->route->addRoute('steward/settings', 'StewardSettingsController', 'show', 'Steward');
        $this->route->addRoute('steward/settings/save', 'StewardSettingsController', 'save', 'Steward');
        $this->route->addRoute('steward/audit', 'StewardAuditController', 'index', 'Steward');
        if ($this->container['stewardFeatures']->isEnabled('branding')) {
            $this->route->addRoute('steward/branding', 'StewardBrandingController', 'show', 'Steward');
            $this->route->addRoute('steward/branding/save', 'StewardBrandingController', 'save', 'Steward');
            $this->route->addRoute('steward/branding/import', 'StewardBrandingController', 'import', 'Steward');
            // On app.bootstrap, after every plugin's initialize(): plugins load in directory order, so this is
            // how Steward's header/title override wins over ShadcnTheme 1.1's whichever loads last.
            $this->dispatcher->addListener('app.bootstrap', [$this, 'applyBranding'], self::LATE_BOOTSTRAP);
        }
        if ($this->container['stewardFeatures']->isEnabled('project_pages')) {
            $this->route->addRoute('steward/project/:project_id/page', 'StewardProjectSettingsController', 'show', 'Steward');
            $this->route->addRoute('steward/project/:project_id/page/save', 'StewardProjectSettingsController', 'save', 'Steward');
            $this->hook->on('template:project:sidebar', ['template' => 'Steward:project/sidebar']);
            $this->route->addRoute('steward/project/:project_id/home', 'StewardProjectHomeController', 'show', 'Steward');
            // Spec §5.2: first in the view switcher; core's default view is unchanged.
            $this->hook->on('template:project-header:view-switcher-before-project-overview', ['template' => 'Steward:project_header/home_link']);
        }
        // app.bootstrap listeners run in registration order: AclCheck, then FeatureGate, then SwitchGuard, all
        // before core's authentication and ACL middleware. Plugins loaded after Steward register onStartup on the same event after us, so
        // the check and the gate run again last, in case one of them replaced a rule.
        $gate = new FeatureGate($this->container);
        $this->dispatcher->addListener('app.bootstrap', [$acl, 'onBootstrap']);
        $this->dispatcher->addListener('app.bootstrap', [$gate, 'onBootstrap']);
        // Whatever the switches say: with switching off it ends any leftover switch on its next request.
        $this->dispatcher->addListener('app.bootstrap', [new SwitchGuard($this->container), 'onBootstrap']);
        $this->dispatcher->addListener('app.bootstrap', [$acl, 'onBootstrap'], self::LATE_BOOTSTRAP);
        $this->dispatcher->addListener('app.bootstrap', [$gate, 'onBootstrap'], self::LATE_BOOTSTRAP);

        $this->dispatcher->addSubscriber(new LoginSubscriber($this->container));
        $this->dispatcher->addSubscriber(new SwitchSubscriber($this->container));

        $this->hook->on('template:config:sidebar', ['template' => 'Steward:config/sidebar']);

        // Switching UI (spec §2 Flow steps 1 and 4). The button shows only when start() would allow the switch.
        $switching = fn () => $this->container['stewardSwitching'];
        $this->hook->on('template:layout:css', ['template' => 'plugins/Steward/Assets/css/steward.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Steward/Assets/js/steward.js']);
        $this->hook->on('template:user:sidebar:actions', [
            'template' => 'Steward:user/switch_button',
            'callable' => fn (array $user) => ['steward_can_switch' => $switching()->refusalFor($user) === null],
        ]);
        // layout/top renders the switch banner first; Task 24's announcement banner goes after it there.
        $this->hook->on('template:layout:top', [
            'template' => 'Steward:layout/top',
            'callable' => fn () => ['steward_switched' => $switching()->isSwitched()],
        ]);
        $this->hook->on('template:header:dropdown', [
            'template' => 'Steward:header/switch_back',
            'callable' => fn () => ['steward_switched' => $switching()->isSwitched()],
        ]);
    }

    /**
     * Spec §4 client side: highlight.js, its light theme and Steward's Markdown script and styles, on every page
     * that renders core's layout hooks (core's public board does too). Mermaid is not listed: steward-markdown.js
     * loads it only on a page that holds a diagram. highlight.js comes first: both are deferred, so they run in order.
     */
    public function registerMarkdownAssets(): void
    {
        $this->hook->on('template:layout:css', ['template' => 'plugins/Steward/Assets/vendor/highlight-github.css']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/Steward/Assets/css/markdown.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Steward/Assets/vendor/highlight.min.js']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Steward/Assets/js/steward-markdown.js']);
    }

    /**
     * Spec §3 Applying, run on app.bootstrap. Favicon links render after core's and after other plugins' (browsers
     * prefer the later ones). The header/title override replaces ShadcnTheme 1.1's and falls back to its logo
     * (spec §5.6).
     */
    public function applyBranding(): void
    {
        $this->template->setTemplateOverride('layout', 'Steward:layout');
        $this->template->setTemplateOverride('header/title', 'Steward:header/title');
        $this->hook->on('template:layout:head', ['template' => 'Steward:layout/favicon']);
        $this->hook->on('template:auth:login-form:before', [
            'template' => 'Steward:auth/logo',
            'callable' => fn () => [
                'steward_logo_url' => $this->container['stewardBranding']->imageUrl('logo'),
                'steward_site_name' => $this->container['stewardBranding']->siteName(),
            ],
        ]);
    }

    public function getPluginName(): string        { return 'Steward'; }
    public function getPluginDescription(): string { return t('Admin tools, branding, Markdown and project pages for Kanboard.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '1.0.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-steward'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
