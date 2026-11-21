<?php
namespace Kanboard\Plugin\Steward;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Steward\Helper\StewardBrandingHelper;
use Kanboard\Plugin\Steward\Helper\StewardTextHelper;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\Banner;
use Kanboard\Plugin\Steward\Model\Branding;
use Kanboard\Plugin\Steward\Model\FeatureFlags;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\LoginLinks;
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
        $this->container['stewardLoginLinks'] = fn ($c) => new LoginLinks($c);
        $this->container['stewardBranding'] = fn ($c) => new Branding($c);
        $this->container['stewardBanner'] = fn ($c) => new Banner($c);

        // Security first: core's Loader catches any exception from initialize(), so nothing that can throw (the
        // text helper swap below) may come before the ACL rules, the gate, the switch guard and the subscribers.
        // ACL for every Steward controller (AclCheck::EXPECTED), whatever the switches say; FeatureGate hides disabled ones.
        $acl = $this->container['stewardAcl'];
        $acl->register();
        // app.bootstrap listeners run in registration order: AclCheck, then FeatureGate, then SwitchGuard, all
        // before core's authentication and ACL middleware. Plugins loaded after Steward register onStartup on the
        // same event after us, so the check and the gate run again last, in case one of them replaced a rule.
        $gate = new FeatureGate($this->container);
        $this->dispatcher->addListener('app.bootstrap', [$acl, 'onBootstrap']);
        $this->dispatcher->addListener('app.bootstrap', [$gate, 'onBootstrap']);
        // Whatever the switches say: with switching off it ends any leftover switch on its next request.
        $this->dispatcher->addListener('app.bootstrap', [new SwitchGuard($this->container), 'onBootstrap']);
        $this->dispatcher->addListener('app.bootstrap', [$acl, 'onBootstrap'], self::LATE_BOOTSTRAP);
        $this->dispatcher->addListener('app.bootstrap', [$gate, 'onBootstrap'], self::LATE_BOOTSTRAP);

        $this->dispatcher->addSubscriber(new LoginSubscriber($this->container));
        $this->dispatcher->addSubscriber(new SwitchSubscriber($this->container));

        // Every pretty route, whatever the switches say: with a feature off, FeatureGate answers its URLs with the
        // fixed 404 (the same one an unknown token or identifier gets) instead of core's fallthrough.
        $this->route->addRoute('steward/settings', 'StewardSettingsController', 'show', 'Steward');
        $this->route->addRoute('steward/settings/save', 'StewardSettingsController', 'save', 'Steward');
        $this->route->addRoute('steward/audit', 'StewardAuditController', 'index', 'Steward');
        $this->route->addRoute('steward/branding', 'StewardBrandingController', 'show', 'Steward');
        $this->route->addRoute('steward/branding/save', 'StewardBrandingController', 'save', 'Steward');
        $this->route->addRoute('steward/branding/import', 'StewardBrandingController', 'import', 'Steward');
        $this->route->addRoute('steward/project/:project_id/page', 'StewardProjectSettingsController', 'show', 'Steward');
        $this->route->addRoute('steward/project/:project_id/page/save', 'StewardProjectSettingsController', 'save', 'Steward');
        $this->route->addRoute('steward/project/:project_id/home', 'StewardProjectHomeController', 'show', 'Steward');
        $this->route->addRoute('steward/test-email', 'StewardTestEmailController', 'send', 'Steward');
        $this->route->addRoute('steward/banner', 'StewardBannerController', 'show', 'Steward');
        $this->route->addRoute('steward/banner/save', 'StewardBannerController', 'save', 'Steward');
        $this->route->addRoute('steward/banner/dismiss', 'StewardBannerController', 'dismiss', 'Steward');
        $this->route->addRoute('public/project/:token', 'StewardPublicController', 'show', 'Steward');
        $this->route->addRoute('explore', 'StewardExploreController', 'index', 'Steward');
        $this->route->addRoute('explore/:identifier', 'StewardExploreController', 'show', 'Steward');
        $this->route->addRoute('steward/user/:user_id/login-link', 'StewardLoginLinkController', 'create', 'Steward');
        // LoginLinks::url() builds this path under application_url.
        $this->route->addRoute('login-link/:token', 'StewardLoginLinkController', 'confirm', 'Steward');
        $this->route->addRoute('login-link/:token/redeem', 'StewardLoginLinkController', 'redeem', 'Steward');

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

        if ($this->container['stewardFeatures']->isEnabled('branding')) {
            // On app.bootstrap, after every plugin's initialize(): plugins load in directory order, so this is
            // how Steward's header/title override wins over ShadcnTheme 1.1's whichever loads last.
            $this->dispatcher->addListener('app.bootstrap', [$this, 'applyBranding'], self::LATE_BOOTSTRAP);
        }
        if ($this->container['stewardFeatures']->isEnabled('project_pages')) {
            $this->hook->on('template:project:sidebar', ['template' => 'Steward:project/sidebar']);
            // Spec §5.2: first in the view switcher; core's default view is unchanged.
            $this->hook->on('template:project-header:view-switcher-before-project-overview', ['template' => 'Steward:project_header/home_link']);
        }
        if ($this->container['stewardFeatures']->isEnabled('test_email')) {
            // The button sits inside core's email settings form; the form it submits renders at the page bottom.
            $this->hook->on('template:config:email', [
                'template' => 'Steward:config/test_email',
                'callable' => fn () => ['steward_test_email_to' => $this->testEmailRecipient()],
            ]);
            $this->hook->on('template:layout:bottom', [
                'template' => 'Steward:config/test_email_form',
                'callable' => fn () => ['steward_test_email_redirect' => $this->testEmailRedirect()],
            ]);
        }
        if ($this->container['stewardFeatures']->isEnabled('banner')) {
            // The login page has no layout:top; the banner renders above the form (and above Steward's logo).
            $this->hook->on('template:auth:login-form:before', [
                'template' => 'Steward:banner/announcement',
                'callable' => fn () => ['steward_announcement' => $this->container['stewardBanner']->forViewer(Banner::LOGIN)],
            ]);
        }
        $this->hook->on('template:config:sidebar', ['template' => 'Steward:config/sidebar']);

        // Switching UI (spec §2 Flow steps 1 and 4). The button shows only when start() would allow the switch.
        $switching = fn () => $this->container['stewardSwitching'];
        $this->hook->on('template:layout:css', ['template' => 'plugins/Steward/Assets/css/steward.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Steward/Assets/js/steward.js']);
        $this->hook->on('template:user:sidebar:actions', [
            'template' => 'Steward:user/switch_button',
            'callable' => fn (array $user) => ['steward_can_switch' => $switching()->refusalFor($user) === null],
        ]);
        // Sign-in links (Steward 1.1): a POST form, shown only when mint() would allow it (feature off: 'disabled').
        $this->hook->on('template:user:sidebar:actions', [
            'template' => 'Steward:user/login_link_button',
            'callable' => fn (array $user) => ['steward_can_mint_link' => $this->container['stewardLoginLinks']->refusalFor($user) === null],
        ]);
        // layout/top renders the switch banner first, then the announcement banner (null while the feature is off).
        $this->hook->on('template:layout:top', [
            'template' => 'Steward:layout/top',
            'callable' => fn () => [
                'steward_switched' => $switching()->isSwitched(),
                'steward_announcement' => $this->container['stewardBanner']->forViewer(Banner::APP),
                'steward_return_to' => $this->request->getUri(),
            ],
        ]);
        $this->hook->on('template:header:dropdown', [
            'template' => 'Steward:header/switch_back',
            'callable' => fn () => ['steward_switched' => $switching()->isSwitched()],
        ]);
    }

    /** The signed-in user's profile email, the only address a test email goes to (Decision 10). */
    public function testEmailRecipient(): string
    {
        $user = $this->userModel->getById((int) $this->userSession->getId());
        return trim((string) ($user['email'] ?? ''));
    }

    /** Which page shows the test-email button: 'email' (core's), '' (Steward settings) or null (neither). */
    public function testEmailRedirect(): ?string
    {
        if (! $this->userSession->isAdmin()) {
            return null;
        }
        $page = strtolower($this->router->getController().'::'.$this->router->getAction());
        return match ($page) {
            'configcontroller::email' => 'email',
            'stewardsettingscontroller::show' => '',
            default => null,
        };
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
     * Spec §3 Applying, run on app.bootstrap. Favicon links render after other plugins' (browsers prefer the later
     * ones) and the layout override drops core's own icon links. The header/title override replaces ShadcnTheme 1.1's and falls back to its logo
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
