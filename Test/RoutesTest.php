<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Core\Http\Route;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SettingsSchema;
use Kanboard\Plugin\Steward\Plugin;

/**
 * Steward's pretty routes, booted as in production (core's routes first) on a fresh install: every switch at its
 * default, so every feature but Markdown is off. Every route exists anyway, so a disabled feature's URL reaches
 * FeatureGate's 404 instead of falling through to core.
 */
class RoutesTest extends StewardTestCase
{
    /** path => [controller, action, feature or FeatureGate::ALWAYS] */
    private const ROUTES = [
        'steward/settings' => ['StewardSettingsController', 'show', FeatureGate::ALWAYS],
        'steward/settings/save' => ['StewardSettingsController', 'save', FeatureGate::ALWAYS],
        'steward/audit' => ['StewardAuditController', 'index', FeatureGate::ALWAYS],
        'steward/branding' => ['StewardBrandingController', 'show', 'branding'],
        'steward/branding/save' => ['StewardBrandingController', 'save', 'branding'],
        'steward/branding/import' => ['StewardBrandingController', 'import', 'branding'],
        'steward/project/1/page' => ['StewardProjectSettingsController', 'show', 'project_pages'],
        'steward/project/1/page/save' => ['StewardProjectSettingsController', 'save', 'project_pages'],
        'steward/project/1/home' => ['StewardProjectHomeController', 'show', 'project_pages'],
        'steward/test-email' => ['StewardTestEmailController', 'send', 'test_email'],
        'steward/banner' => ['StewardBannerController', 'show', 'banner'],
        'steward/banner/save' => ['StewardBannerController', 'save', 'banner'],
        'steward/banner/dismiss' => ['StewardBannerController', 'dismiss', 'banner'],
        'public/project/abc' => ['StewardPublicController', 'show', 'public_pages'],
        'explore' => ['StewardExploreController', 'index', 'explore'],
        'explore/ALPHA' => ['StewardExploreController', 'show', 'explore'],
        'steward/user/3/login-link' => ['StewardLoginLinkController', 'create', 'login_links'],
        'login-link/'.self::TOKEN => ['StewardLoginLinkController', 'confirm', 'login_links'],
        'login-link/'.self::TOKEN.'/redeem' => ['StewardLoginLinkController', 'redeem', 'login_links'],
    ];

    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\Steward\Schema\version_2($this->container['db']->getConnection());
        $this->container['route']->enable();
        // As in production: core's RouteProvider adds its routes before any plugin loads.
        foreach (self::coreRoutes() as [$path, $controller, $action]) {
            $this->container['route']->addRoute($path, $controller, $action);
        }
        (new Plugin($this->container))->initialize();
    }

    /**
     * Core's routes, read from its RouteProvider (tests run without URL rewriting, so core never adds them).
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private static function coreRoutes(): array
    {
        preg_match_all("/->addRoute\\('([^']+)', '([^']+)', '([^']+)'\\)/", file_get_contents(ROOT_DIR.'/app/ServiceProvider/RouteProvider.php'), $m, PREG_SET_ORDER);
        return array_map(fn ($row) => array_slice($row, 1), $m);
    }

    public function testEveryPrettyRouteIsRegisteredWithTheFeaturesOff(): void
    {
        $features = $this->container['stewardFeatures'];
        foreach (SettingsSchema::FEATURES as $feature) {
            $this->assertSame($feature === 'markdown', $features->isEnabled($feature), $feature);
        }
        $gate = new FeatureGate($this->container);
        foreach (self::ROUTES as $path => [$controller, $action, $feature]) {
            $this->assertSame(['controller' => $controller, 'action' => $action, 'plugin' => 'Steward'],
                $this->container['route']->findRoute($path), $path);
            $this->assertSame($feature, $gate->featureFor($controller, $action), $path);
            $this->assertSame($feature === FeatureGate::ALWAYS, $gate->check('Steward', $controller, $action), $path);
        }
    }

    /** Every route Steward adds is in the table above. */
    public function testTheTableCoversEveryStewardRoute(): void
    {
        preg_match_all("/->addRoute\\('([^']+)', '(Steward[A-Za-z]+)', '([a-z]+)', 'Steward'\\)/",
            file_get_contents(__DIR__.'/../Plugin.php'), $m, PREG_SET_ORDER);
        $added = array_map(fn ($row) => $row[2].'::'.$row[3], $m);
        $listed = array_map(fn ($row) => $row[0].'::'.$row[1], array_values(self::ROUTES));
        sort($added);
        sort($listed);
        $this->assertSame($listed, $added);
    }

    /** Decision 18: core's /p/:id and /projects (and every other core path) resolve exactly as without Steward. */
    public function testCoreRoutesStillReachCore(): void
    {
        $routes = self::coreRoutes();
        $this->assertGreaterThan(50, count($routes));
        $coreOnly = new Route($this->container);
        $coreOnly->enable();
        foreach ($routes as [$path, $controller, $action]) {
            $coreOnly->addRoute($path, $controller, $action);
        }
        $live = $this->container['route'];
        $this->assertSame(['controller' => 'ProjectViewController', 'action' => 'show', 'plugin' => ''], $live->findRoute('p/1'));
        $this->assertSame(['controller' => 'ProjectListController', 'action' => 'show', 'plugin' => ''], $live->findRoute('projects'));
        foreach ($routes as [$path]) {
            $concrete = preg_replace('#:[a-z_]+#', '1', $path);
            $this->assertSame($coreOnly->findRoute($concrete), $live->findRoute($concrete), $concrete);
        }
        $this->assertSame('StewardExploreController', $live->findRoute('explore')['controller']);
        $this->assertSame('StewardExploreController', $live->findRoute('explore/ALPHA')['controller']);
    }

    /** The link's route hands the controller the token as written, as LoginLinks::url() builds it. */
    public function testLoginLinkRouteCarriesTheToken(): void
    {
        $this->container['route']->findRoute('login-link/'.self::TOKEN);
        $this->assertSame(self::TOKEN, $this->container['request']->getStringParam('token'));
        $this->container['route']->findRoute('steward/user/3/login-link');
        $this->assertSame('3', $this->container['request']->getStringParam('user_id'));
    }

    /** Decision 17, rewriting on: the link is the pretty path under the Application URL, and it routes to confirm. */
    public function testLoginLinkUrlIsThePrettyPathWithRewritingOn(): void
    {
        foreach (['https://kb.example/', 'https://kb.example/kanboard'] as $base) {
            $this->container['configModel']->save(['application_url' => $base]);
            $this->container['memoryCache']->flush();
            $this->assertSame(rtrim($base, '/').'/login-link/'.self::TOKEN, $this->container['stewardLoginLinks']->url(self::TOKEN), $base);
        }
        $this->assertSame(['controller' => 'StewardLoginLinkController', 'action' => 'confirm', 'plugin' => 'Steward'],
            $this->container['route']->findRoute('login-link/'.self::TOKEN));
        $this->assertSame(self::TOKEN, $this->container['request']->getStringParam('token'));
    }

    /** Rewriting on: the confirm page's form posts to `login-link/<token>/redeem`, which routes to redeem. */
    public function testConfirmFormPostsToThePrettyRedeemPathWithRewritingOn(): void
    {
        $html = $this->container['template']->render('Steward:login_link/confirm', [
            'steward_brand' => null,
            'steward_site_name' => 'Kanboard',
            'steward_link_user' => ['id' => 2, 'username' => 'ana', 'name' => ''],
            'steward_link_token' => self::TOKEN,
        ]);
        $this->assertMatchesRegularExpression('~<form method="post" action="(?:[^"?]*/)?login-link/'.self::TOKEN.'/redeem"~', $html);
        $this->assertSame(['controller' => 'StewardLoginLinkController', 'action' => 'redeem', 'plugin' => 'Steward'],
            $this->container['route']->findRoute('login-link/'.self::TOKEN.'/redeem'));
    }
}
