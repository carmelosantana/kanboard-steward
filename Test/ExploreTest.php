<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';
require_once __DIR__.'/support/BrandingDoubles.php';

use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Route;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Steward\Controller\StewardExploreController;
use Kanboard\Plugin\Steward\Controller\StewardPublicController;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;
use Kanboard\Plugin\Steward\Model\PublicPage;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;

/**
 * Spec §5.4 directory and listed pages, rendered through core's real layout as an anonymous visitor. Several
 * projects each miss one condition; none of them may show up anywhere.
 */
class ExploreTest extends Base
{
    private const DESCRIPTION_SENTINEL = 'ZZDESCRIPTIONSENTINELZZ';
    private const LINK_URL = 'https://example.com/zzlinksentinel';

    /** name => project id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\Steward\Schema\version_2($this->container['db']->getConnection());
        $on = $this->getName(false) === 'testRoutesAreRegisteredWithTheFeaturesOff' ? '0' : '1';
        $this->container['configModel']->save(['steward_public_pages' => $on, 'steward_explore' => $on]);
        $this->container['memoryCache']->flush();
        $this->container['route']->enable();
        if ($this->getName(false) === 'testCoreRoutesStillReachCore') {
            // As in production: core's RouteProvider adds its routes before any plugin loads.
            foreach (self::coreRoutes() as [$path, $controller, $action]) {
                $this->container['route']->addRoute($path, $controller, $action);
            }
        }
        (new Plugin($this->container))->initialize();

        // Listed and shown, created out of name order.
        $this->publish('Zeta <listed>', 'ZETA', 'Zeta tag', true);
        $this->publish('alpha listed', 'ALPHA', 'Alpha tag', true);
        $this->publish('Mid listed', 'MID', '', true);
        // Each misses one condition.
        $this->publish('Unlisted', 'UNLISTED', 'Unlisted tag', false);
        $this->publish('Core off', 'COREOFF', 'Core off tag', true);
        $this->db()->table('projects')->eq('id', $this->ids['Core off'])->update(['is_public' => 0]);
        $this->publish('Steward off', 'STEWARDOFF', 'Steward off tag', true);
        $this->db()->table(ProjectPageSettings::TABLE)->eq('project_id', $this->ids['Steward off'])->update(['is_public' => 0]);
        $this->publish('No identifier', 'NOIDENT', 'No identifier tag', true);
        $this->db()->table('projects')->eq('id', $this->ids['No identifier'])->update(['identifier' => '']);

        session_remove('user');
        $this->container['memoryCache']->flush();
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function db(): \PicoDb\Database
    {
        return $this->container['db'];
    }

    private function publish(string $name, string $identifier, string $tagline, bool $listed): void
    {
        $projects = $this->container['projectModel'];
        $id = $projects->create(['name' => $name, 'identifier' => $identifier, 'description' => self::DESCRIPTION_SENTINEL]);
        $this->assertGreaterThan(0, $id);
        $this->assertTrue($projects->enablePublicAccess($id));
        $this->assertSame([], (new ProjectPageSettings($this->container))->save($id, [
            'tagline' => $tagline,
            'links' => [['label' => 'Site', 'url' => self::LINK_URL]],
            'public_panels' => ProjectPageSettings::PUBLIC_PANELS,
            'public' => true,
            'listed' => $listed,
        ]));
        $this->ids[$name] = $id;
    }

    private function token(string $name): string
    {
        return (string) $this->container['projectModel']->getById($this->ids[$name])['token'];
    }

    private function call(string $action, array $params = []): StewardBrandingResponse
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'],
            ['controller' => 'StewardExploreController', 'action' => $action, 'plugin' => 'Steward'] + $params, [], [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        $this->container['memoryCache']->flush();
        (new StewardExploreController($this->container))->$action();
        return $response;
    }

    private function directory(): StewardBrandingResponse
    {
        return $this->call('index');
    }

    private function listed(string $identifier): StewardBrandingResponse
    {
        return $this->call('show', ['identifier' => $identifier]);
    }

    private function features(string $publicPages, string $explore): void
    {
        $this->container['configModel']->save(['steward_public_pages' => $publicPages, 'steward_explore' => $explore]);
    }

    private function assertNotFound(StewardBrandingResponse $response, string $why): void
    {
        $this->assertSame(404, $response->code(), $why);
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body(), $why);
        $this->assertSame('no-referrer', $response->headers()['Referrer-Policy'] ?? null, $why);
    }

    private function assertIndexable(StewardBrandingResponse $response): void
    {
        $this->assertSame(200, $response->code());
        $this->assertSame('no-referrer', $response->headers()['Referrer-Policy'] ?? null);
        $this->assertArrayNotHasKey('X-Robots-Tag', $response->headers());
        $this->assertStringNotContainsString('name="robots"', $response->body());
        $this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $response->body());
    }

    // --- registration -----------------------------------------------------------------------------------

    public function testRegisteredAsPublicReadPagesBehindTheExploreFeature(): void
    {
        foreach (['index', 'show'] as $action) {
            $this->assertContains(['StewardExploreController', ['index', 'show'], Role::APP_PUBLIC], AclCheck::EXPECTED['applicationAccessMap']);
            $this->assertTrue($this->container['applicationAuthorization']->isAllowed('StewardExploreController', $action, Role::APP_PUBLIC));
            $this->assertSame('explore', (new FeatureGate($this->container))->featureFor('StewardExploreController', $action));
            $this->assertSame('read', SwitchActionTable::STEWARD['StewardExploreController::'.$action]);
        }
        $this->assertRoutes();
    }

    /** Registered with the switches off too, so FeatureGate's 404 answers there instead of core's login redirect. */
    public function testRoutesAreRegisteredWithTheFeaturesOff(): void
    {
        $this->assertFalse($this->container['stewardFeatures']->isEnabled('explore'));
        $this->assertRoutes();
    }

    private function assertRoutes(): void
    {
        $route = $this->container['route'];
        $this->assertSame(['controller' => 'StewardExploreController', 'action' => 'index', 'plugin' => 'Steward'], $route->findRoute('explore'));
        $this->assertSame(['controller' => 'StewardExploreController', 'action' => 'show', 'plugin' => 'Steward'], $route->findRoute('explore/Alpha'));
        $this->assertSame('Alpha', $this->container['request']->getStringParam('identifier'));
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

    // --- directory --------------------------------------------------------------------------------------

    public function testDirectoryIs404WhileExploreIsOff(): void
    {
        $this->features('1', '0');
        $this->assertNotFound($this->directory(), 'explore off');
        $this->assertFalse((new FeatureGate($this->container))->check('Steward', 'StewardExploreController', 'index'));
    }

    public function testDirectoryListsOnlyListedProjectsSortedByName(): void
    {
        $response = $this->directory();
        $this->assertIndexable($response);
        preg_match_all('#<a href="([^"]*)"[^>]*>([^<]*)</a>#', $response->body(), $m);
        $this->assertSame(['alpha listed', 'Mid listed', 'Zeta &lt;listed&gt;'], $m[2]);
        foreach (['ALPHA', 'MID', 'ZETA'] as $i => $identifier) {
            $this->assertStringEndsWith('explore/'.$identifier, $m[1][$i]);
        }
        foreach (['Unlisted', 'Core off', 'Steward off', 'No identifier', 'UNLISTED', 'COREOFF', 'STEWARDOFF', 'NOIDENT'] as $absent) {
            $this->assertStringNotContainsString($absent, $response->body());
        }
    }

    public function testDirectoryShowsNameTaglineAndLinkOnly(): void
    {
        $html = $this->directory()->body();
        $this->assertStringContainsString('Alpha tag', $html);
        $this->assertStringContainsString('Zeta tag', $html);
        foreach ([self::DESCRIPTION_SENTINEL, 'zzlinksentinel', '/public/', 'token=', 'data-steward-panel'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
        foreach (['Zeta <listed>', 'alpha listed', 'Mid listed', 'Unlisted', 'Core off', 'Steward off', 'No identifier'] as $name) {
            $this->assertStringNotContainsString($this->token($name), $html);
        }
    }

    public function testDirectoryHidesATokenPastedIntoATagline(): void
    {
        $token = $this->token('alpha listed');
        $this->db()->table(ProjectPageSettings::TABLE)->eq('project_id', $this->ids['alpha listed'])
            ->update(['tagline' => 'Board: '.$token]);
        $html = $this->directory()->body();
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringContainsString('Board: '.PublicPage::HIDDEN, $html);
    }

    /** Every entry's page would 404, so none is listed; the directory itself stays up. */
    public function testDirectoryListsNothingWhilePublicPagesAreOff(): void
    {
        $this->features('0', '1');
        $response = $this->directory();
        $this->assertSame(200, $response->code());
        $this->assertStringNotContainsString('explore/', $response->body());
        $this->assertStringNotContainsString('alpha listed', $response->body());
    }

    public function testDirectoryWithNothingListed(): void
    {
        $this->db()->table(ProjectPageSettings::TABLE)->update(['is_listed' => 0]);
        $response = $this->directory();
        $this->assertSame(200, $response->code());
        $this->assertStringNotContainsString('explore/', $response->body());
    }

    // --- listed page ------------------------------------------------------------------------------------

    public function testListedPageRendersThePublicPageWithCaseInsensitiveLookup(): void
    {
        foreach (['ALPHA', 'alpha', 'Alpha'] as $identifier) {
            $response = $this->listed($identifier);
            $this->assertIndexable($response);
            $this->assertStringContainsString('alpha listed', $response->body());
            $this->assertStringContainsString('data-steward-panel="description"', $response->body());
            $this->assertStringContainsString('plugins/Steward/Assets/css/public.css', $response->body());
        }
    }

    public function testEveryFailedCheckAnswersTheSame404(): void
    {
        foreach (['UNLISTED', 'coreoff', 'STEWARDOFF', 'NOIDENT', 'NOPE', '', '1', (string) $this->ids['alpha listed']] as $identifier) {
            $this->assertNotFound($this->listed($identifier), 'identifier '.$identifier);
        }
        $this->features('0', '1');
        $this->assertNotFound($this->listed('ALPHA'), 'public pages off');
        $this->features('1', '0');
        $this->assertNotFound($this->listed('ALPHA'), 'explore off');
        $this->assertFalse((new FeatureGate($this->container))->check('Steward', 'StewardExploreController', 'show'));
        $this->features('1', '1');
        $this->assertSame(200, $this->listed('ALPHA')->code());
    }

    public function testListedPageHidesTheTokenPastedIntoDescriptionAndTagline(): void
    {
        $id = $this->ids['alpha listed'];
        $token = $this->token('alpha listed');
        $this->container['projectModel']->update(['id' => $id, 'description' => 'Board at /public/board/'.$token.' and '.strtoupper($token)]);
        $this->db()->table(ProjectPageSettings::TABLE)->eq('project_id', $id)->update(['tagline' => 'Tag '.$token]);

        $html = $this->listed('ALPHA')->body();
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringNotContainsString(strtoupper($token), $html);
        $this->assertStringContainsString('Tag '.PublicPage::HIDDEN, $html);
        $this->assertStringContainsString('/public/board/'.PublicPage::HIDDEN, $html);
    }

    public function testDisablingCoreAccessTakesTheListedPageDownAndReEnablingBringsItBack(): void
    {
        $projects = $this->container['projectModel'];
        $this->assertTrue($projects->disablePublicAccess($this->ids['alpha listed']));
        $this->assertNotFound($this->listed('ALPHA'), 'core public access off');
        $this->assertStringNotContainsString('alpha listed', $this->directory()->body());
        $this->assertTrue($projects->enablePublicAccess($this->ids['alpha listed']));
        $this->assertSame(200, $this->listed('ALPHA')->code());
    }

    /** An archived project leaves the directory and its listed page; its token page keeps core's behaviour. */
    public function testArchivedProjectLeavesTheDirectoryButKeepsItsTokenPage(): void
    {
        $this->assertTrue($this->container['projectModel']->disable($this->ids['alpha listed']));

        $html = $this->directory()->body();
        $this->assertStringNotContainsString('alpha listed', $html);
        $this->assertStringNotContainsString('explore/ALPHA', $html);
        $this->assertStringContainsString('Mid listed', $html);
        $this->assertNotFound($this->listed('ALPHA'), 'archived');

        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'],
            ['controller' => 'StewardPublicController', 'action' => 'show', 'plugin' => 'Steward', 'token' => $this->token('alpha listed')], [], [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardPublicController($this->container))->show();
        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('alpha listed', $response->body());
    }

    /** Case-insensitive, but the identifier is matched as sent: no trimming. */
    public function testListedIdentifierIsNotTrimmed(): void
    {
        foreach ([' ALPHA', 'ALPHA ', "ALPHA\n", "\talpha"] as $identifier) {
            $this->assertNotFound($this->listed($identifier), json_encode($identifier));
        }
        $this->assertSame(200, $this->listed('aLpHa')->code());
    }
}
