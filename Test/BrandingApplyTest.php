<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';
require_once __DIR__.'/support/BrandingDoubles.php';
if (! class_exists('Kanboard\\Plugin\\ShadcnTheme\\Controller\\SettingsController')) {
    require_once __DIR__.'/fixtures/ShadcnSettingsControllerStub.php';
}

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardBrandingController;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\Branding;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;

/** Spec §3 Applying/Helper and §5.6: the logo, favicon and site name reach the pages; ShadcnTheme fallback and import. */
class BrandingApplyTest extends StewardTestCase
{
    private const ADMIN = 1;
    private const SVG_REFUSAL = 'Your ShadcnTheme logo is an SVG; upload a PNG instead';
    private const HEADER_ARGS = ['project' => null, 'task' => null, 'description' => null, 'title' => 'My board'];

    private StewardMemoryStorage $storage;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());

        $this->storage = new StewardMemoryStorage();
        unset($this->container['objectStorage']);
        $this->container['objectStorage'] = $this->storage;

        $this->tmpDir = sys_get_temp_dir().'/steward-apply-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir);

        $this->container['userSession']->initialize($this->container['userModel']->getById(self::ADMIN));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
        session_set('flash', []);
        parent::tearDown();
    }

    // --- helpers ----------------------------------------------------------------------------------------

    /** Plugin::initialize() reads the switch, then app.bootstrap fires, as on a real request. */
    private function boot(bool $branding = true, array $config = [], ?callable $afterPlugins = null): void
    {
        $this->container['configModel']->save(['steward_branding' => $branding ? '1' : '0'] + $config);
        $this->container['memoryCache']->flush();
        (new Plugin($this->container))->initialize();
        if ($afterPlugins !== null) {
            $afterPlugins();
        }
        $this->container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
        $this->container['helper']->register('layout', StewardBrandingBodyOnlyLayoutHelper::class);
    }

    private function pngBytes(int $width = 300, int $height = 100): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 140, 90));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** Stores a logo and a favicon the way Steward's own upload does. */
    private function setSlots(bool $logo, bool $favicon): void
    {
        $branding = new Branding($this->container);
        if ($logo) {
            $branding->save('logo', ['logo' => $this->pngBytes()], str_repeat('a', 64));
        }
        if ($favicon) {
            $branding->save('favicon', ['favicon32' => $this->pngBytes(32, 32), 'favicon180' => $this->pngBytes(180, 180)], str_repeat('b', 64));
        }
        $this->container['memoryCache']->flush();
    }

    private function render(string $template, array $args = []): string
    {
        return $this->container['template']->render($template, $args);
    }

    private function hook(string $name): string
    {
        return $this->container['helper']->hook->render($name);
    }

    private function helper()
    {
        return $this->container['helper']->stewardBranding;
    }

    private function titleTag(array $args): string
    {
        $html = $this->render('layout', $args + ['no_layout' => true, 'content_for_layout' => '']);
        $this->assertSame(1, preg_match('#<title>\s*(.*?)\s*</title>#s', $html, $m));
        return $m[1];
    }

    private function flash(): array
    {
        return session_exists('flash') ? (array) session_get('flash') : [];
    }

    private function setting(string $key): string
    {
        $this->container['memoryCache']->flush();
        return (string) $this->container['configModel']->getOption($key, '');
    }

    private function brandingConfig(): array
    {
        $out = [];
        foreach (['steward_logo_path', 'steward_logo_hash', 'steward_favicon32_path', 'steward_favicon180_path', 'steward_favicon_hash'] as $key) {
            $out[$key] = $this->setting($key);
        }
        return $out;
    }

    /** Puts files where ShadcnTheme 1.1 keeps them and points its config keys at them. */
    private function shadcn(?string $logo, ?string $favicon, string $logoExt = 'png', string $faviconExt = 'png'): void
    {
        $values = [];
        if ($logo !== null) {
            $values['shadcn_logo_path'] = 'shadcn-theme/logo/'.sha1('l').'.'.$logoExt;
            $this->storage->objects[$values['shadcn_logo_path']] = $logo;
        }
        if ($favicon !== null) {
            $values['shadcn_favicon_path'] = 'shadcn-theme/favicon/'.sha1('f').'.'.$faviconExt;
            $this->storage->objects[$values['shadcn_favicon_path']] = $favicon;
        }
        $this->container['configModel']->save($values);
        $this->container['memoryCache']->flush();
    }

    private function import(bool $csrf = true, string $method = 'POST'): StewardBrandingResponse
    {
        $post = $csrf ? ['csrf_token' => $this->container['token']->getCSRFToken()] : [];
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method],
            ['controller' => 'StewardBrandingController', 'action' => 'import', 'plugin' => 'Steward'], $post, [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardBrandingController($this->container))->import();
        return $response;
    }

    private function show(): string
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['plugin' => 'Steward'], [], [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardBrandingController($this->container))->show();
        return $response->body();
    }

    private function importRows(): array
    {
        $rows = $this->container['db']->table(AuditLog::TABLE)->eq('event', 'branding.changed')->asc('id')->findAll();
        return array_map(fn ($r) => json_decode($r['details'], true), $rows);
    }

    // --- off / nothing set: core markup -----------------------------------------------------------------

    public function testBrandingOffChangesNothing(): void
    {
        $this->setSlots(true, true);
        $this->shadcn($this->pngBytes(), null);
        $this->boot(false, ['steward_site_name' => 'Acme']);

        $this->assertSame('', $this->helper()->logoUrl());
        $this->assertSame('', $this->helper()->faviconUrl(32));
        $this->assertSame('', $this->helper()->faviconUrl(180));
        $this->assertSame('', $this->helper()->siteName());
        $template = $this->container['template'];
        $this->assertSame($template->getTemplateFile('kanboard:header/title'), $template->getTemplateFile('header/title'));
        $this->assertSame($template->getTemplateFile('kanboard:layout'), $template->getTemplateFile('layout'));
        $this->assertSame($this->render('kanboard:header/title', self::HEADER_ARGS), $this->render('header/title', self::HEADER_ARGS));
        $this->assertSame('Kanboard', $this->titleTag([]));
        $this->assertSame('', trim($this->hook('template:layout:head')));
        $this->assertSame('', trim($this->hook('template:auth:login-form:before')));
    }

    public function testNothingSetRendersCoreMarkup(): void
    {
        $this->boot(true);

        $this->assertSame('', $this->helper()->logoUrl());
        $this->assertSame('', $this->helper()->faviconUrl(32));
        $this->assertSame('', $this->helper()->siteName());
        $this->assertSame($this->render('kanboard:header/title', self::HEADER_ARGS), $this->render('header/title', self::HEADER_ARGS));
        $this->assertStringContainsString('K<span>B</span>', $this->render('header/title', self::HEADER_ARGS));
        $this->assertSame(
            $this->render('kanboard:layout', ['no_layout' => true, 'content_for_layout' => 'x']),
            $this->render('layout', ['no_layout' => true, 'content_for_layout' => 'x'])
        );
        $this->assertSame('Kanboard', $this->titleTag([]));
        $this->assertSame('', trim($this->hook('template:layout:head')));
        $this->assertSame('', trim($this->hook('template:auth:login-form:before')));
    }

    /** Plugins load in directory order: Steward's override must win even when ShadcnTheme initializes after it. */
    public function testHeaderOverrideWinsOverAPluginLoadedLater(): void
    {
        $template = $this->container['template'];
        $this->boot(true, [], fn () => $template->setTemplateOverride('header/title', 'ShadcnTheme:header/title'));

        $this->assertStringEndsWith('/Steward/Template/header/title.php', $template->getTemplateFile('header/title'));
        $this->assertStringEndsWith('/Steward/Template/layout.php', $template->getTemplateFile('layout'));
    }

    // --- each slot ----------------------------------------------------------------------------------------

    public function testLogoReplacesTheHeaderMarkAndShowsOnLogin(): void
    {
        $this->boot(true);
        $this->setSlots(true, false);

        $url = $this->helper()->logoUrl();
        $this->assertStringContainsString('StewardBrandingController', $url);
        $this->assertStringContainsString('slot=logo', $url);
        $this->assertStringContainsString('h='.str_repeat('a', 64), $url);

        $header = $this->render('header/title', self::HEADER_ARGS);
        $this->assertStringContainsString('<img src="'.$url.'"', $header);
        $this->assertStringContainsString('alt="Kanboard"', $header);
        $this->assertStringNotContainsString('K<span>B</span>', $header);
        $this->assertStringContainsString('My board', $header);

        $login = $this->hook('template:auth:login-form:before');
        $this->assertStringContainsString('<img src="'.$url.'"', $login);
        $this->assertSame('', trim($this->hook('template:layout:head')));
    }

    public function testFaviconAddsLinksAfterCore(): void
    {
        $this->boot(true);
        $this->setSlots(false, true);

        $this->assertSame('', $this->helper()->faviconUrl(64));
        $u32 = $this->helper()->faviconUrl(32);
        $u180 = $this->helper()->faviconUrl(180);
        $this->assertStringContainsString('slot=favicon32', $u32);
        $this->assertStringContainsString('slot=favicon180', $u180);
        $this->assertStringContainsString('h='.str_repeat('b', 64), $u32);

        $head = $this->hook('template:layout:head');
        $this->assertStringContainsString('<link rel="icon" type="image/png" sizes="32x32" href="'.$u32.'">', $head);
        $this->assertStringContainsString('<link rel="apple-touch-icon" sizes="180x180" href="'.$u180.'">', $head);

        $layout = $this->render('layout', ['no_layout' => true, 'content_for_layout' => '']);
        $this->assertStringContainsString($u32, $layout);

        $this->assertStringContainsString('K<span>B</span>', $this->render('header/title', self::HEADER_ARGS));
        $this->assertSame('', trim($this->hook('template:auth:login-form:before')));
    }

    /** Core's six icon links (layout.php, 1.2.47 and 1.2.57), relative to url->dir(). */
    private const CORE_ICONS = [
        'assets/img/adaptive-favicon.svg', 'assets/img/favicon.png', 'assets/img/touch-icon-iphone.png',
        'assets/img/touch-icon-ipad.png', 'assets/img/touch-icon-iphone-retina.png', 'assets/img/touch-icon-ipad-retina.png',
    ];

    /** The app layout, Steward's explore directory (a public page) and core's login page, as full HTML. */
    private function iconPages(): array
    {
        \Kanboard\Plugin\Steward\Schema\version_2($this->container['db']->getConnection());
        $this->container['configModel']->save(['steward_public_pages' => '1', 'steward_explore' => '1']);
        $pages = ['layout' => $this->render('layout', ['no_layout' => true, 'content_for_layout' => 'x'])];
        session_remove('user');
        foreach ([
            'explore' => fn () => (new \Kanboard\Plugin\Steward\Controller\StewardExploreController($this->container))->index(),
            'login' => fn () => (new \Kanboard\Controller\AuthController($this->container))->login(),
        ] as $name => $run) {
            unset($this->container['request'], $this->container['response']);
            $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], [], [], [], []);
            $this->container['response'] = $response = new StewardBrandingResponse($this->container);
            $this->container['memoryCache']->flush();
            $run();
            $this->assertSame(200, $response->code(), $name);
            $pages[$name] = $response->body();
        }
        return $pages;
    }

    /** Spec §3: browsers may pick core's SVG icon, so a Steward favicon replaces every core icon link. */
    public function testFaviconReplacesCoreIconLinksOnEveryPage(): void
    {
        $this->boot(true);
        $this->setSlots(false, true);
        $u32 = $this->helper()->faviconUrl(32);
        $u180 = $this->helper()->faviconUrl(180);

        foreach ($this->iconPages() as $name => $html) {
            foreach (self::CORE_ICONS as $href) {
                $this->assertStringNotContainsString($href, $html, $name);
            }
            // url->dir() differs between the in-process render ('') and a request ('/').
            $this->assertMatchesRegularExpression('#<link rel="icon" type="image/png" sizes="32x32" href="/?'.preg_quote($u32, '#').'">#', $html, $name);
            $this->assertMatchesRegularExpression('#<link rel="apple-touch-icon" sizes="180x180" href="/?'.preg_quote($u180, '#').'">#', $html, $name);
            $this->assertSame(1, substr_count($html, 'rel="icon"'), $name);
            $this->assertSame(1, substr_count($html, 'rel="apple-touch-icon"'), $name);
        }
    }

    public function testWithoutAFaviconCoreIconLinksStay(): void
    {
        $this->boot(true);
        $this->setSlots(true, false);

        foreach ($this->iconPages() as $name => $html) {
            foreach (self::CORE_ICONS as $href) {
                $this->assertMatchesRegularExpression('#href="/?'.preg_quote($href, '#').'"#', $html, $name);
            }
            $this->assertStringNotContainsString('slot=favicon', $html, $name);
        }
    }

    /** If core's markup ever changes, nothing is removed: the page keeps core's links next to Steward's. */
    public function testUnmatchedCoreMarkupIsLeftAlone(): void
    {
        $this->boot(true);
        $this->setSlots(false, true);
        $core = $this->render('kanboard:layout', ['no_layout' => true, 'content_for_layout' => 'x']);
        $this->assertStringNotContainsString('adaptive-favicon', $this->helper()->withoutCoreIcons($core));

        $changed = str_replace('type="image/svg+xml">', 'type="image/svg+xml" data-x>', $core);
        $this->assertNotSame($core, $changed);
        $this->assertSame($changed, $this->helper()->withoutCoreIcons($changed));
    }

    public function testSiteNameIsEscapedInHeaderLoginAndTitle(): void
    {
        $this->boot(true, ['steward_site_name' => '<b>Acme</b> & Co']);
        $escaped = '&lt;b&gt;Acme&lt;/b&gt; &amp; Co';

        $this->assertSame('<b>Acme</b> & Co', $this->helper()->siteName());

        $header = $this->render('header/title', self::HEADER_ARGS);
        $this->assertStringContainsString($escaped, $header);
        $this->assertStringNotContainsString('<b>Acme</b>', $header);
        $this->assertStringNotContainsString('K<span>B</span>', $header);

        $login = $this->hook('template:auth:login-form:before');
        $this->assertStringContainsString($escaped, $login);
        $this->assertStringNotContainsString('<b>Acme</b>', $login);

        $this->assertSame($escaped, $this->titleTag([]));
        $this->assertSame('Tasks', $this->titleTag(['title' => 'Tasks']));
        $this->assertSame('Task 1', $this->titleTag(['title' => 'Tasks', 'page_title' => 'Task 1']));
    }

    public function testLogoAltUsesTheSiteName(): void
    {
        $this->boot(true, ['steward_site_name' => 'Acme "HQ"']);
        $this->setSlots(true, false);

        $this->assertStringContainsString('alt="Acme &quot;HQ&quot;"', $this->render('header/title', self::HEADER_ARGS));
        $this->assertStringContainsString('alt="Acme &quot;HQ&quot;"', $this->hook('template:auth:login-form:before'));
    }

    // --- ShadcnTheme fallback (spec §5.6) ---------------------------------------------------------------

    public function testEmptyLogoSlotFallsBackToShadcnLogo(): void
    {
        $this->boot(true);
        $this->shadcn($this->pngBytes(), null);

        $url = $this->helper()->logoUrl();
        $this->assertStringContainsString('SettingsController', $url);
        $this->assertStringContainsString('serveAsset', $url);
        $this->assertStringContainsString('plugin=ShadcnTheme', $url);
        $this->assertStringContainsString('slot=logo', $url);

        $header = $this->render('header/title', self::HEADER_ARGS);
        $this->assertStringContainsString('<img src="'.$url.'"', $header);
        $this->assertStringNotContainsString('K<span>B</span>', $header);

        // ShadcnTheme's own login card still shows its logo; Steward adds nothing there.
        $this->assertSame('', trim($this->hook('template:auth:login-form:before')));

        $this->setSlots(true, false);
        $this->assertStringContainsString('StewardBrandingController', $this->helper()->logoUrl());
    }

    // --- import (spec §5.6) -----------------------------------------------------------------------------

    public function testImportWiring(): void
    {
        $this->boot(true);
        $this->assertSame([Role::APP_ADMIN], $this->container['applicationAccessMap']->getRoles('StewardBrandingController', 'import'));
        $this->assertTrue($this->container['stewardAcl']->verify());
        $this->assertSame('branding', (new FeatureGate($this->container))->featureFor('StewardBrandingController', 'import'));
        $this->assertSame('blocked', (new SwitchActionTable())->classify('Steward', 'StewardBrandingController', 'import'));
    }

    public function testImportButtonOnlyWhenShadcnHasImagesAndSlotsAreEmpty(): void
    {
        $this->boot(true);
        $this->assertStringNotContainsString('action=import', $this->show());

        $this->shadcn($this->pngBytes(), null);
        $html = $this->show();
        $this->assertStringContainsString('action=import', $html);
        $this->assertStringContainsString('Import from ShadcnTheme', $html);

        $this->setSlots(true, false);
        $this->assertStringNotContainsString('action=import', $this->show());

        $this->shadcn(null, $this->pngBytes(64, 64));
        $this->assertStringContainsString('action=import', $this->show());

        $this->setSlots(false, true);
        $this->assertStringNotContainsString('action=import', $this->show());
    }

    public function testImportReencodesPngLogoAndFavicon(): void
    {
        $this->boot(true);
        $this->shadcn($this->pngBytes(), $this->pngBytes(64, 64));
        $shadcnObjects = array_keys($this->storage->objects);

        $response = $this->import();

        $this->assertStringContainsString('action=show', $response->headers()['Location']);
        $this->assertNotSame('', $this->setting('steward_logo_hash'));
        $this->assertNotSame('', $this->setting('steward_favicon_hash'));
        foreach (['logo', 'favicon32', 'favicon180'] as $image) {
            $stored = $this->container['stewardBranding']->image($image);
            $this->assertNotNull($stored, $image);
            $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($stored['blob'])[2]);
        }
        // ShadcnTheme's files stay in place.
        foreach ($shadcnObjects as $key) {
            $this->assertArrayHasKey($key, $this->storage->objects);
        }
        $this->assertSame([
            ['slot' => 'logo', 'action' => 'import'],
            ['slot' => 'favicon', 'action' => 'import'],
        ], $this->importRows());
        $this->assertArrayHasKey('success', $this->flash());
    }

    public function testImportSkipsSlotsAlreadySet(): void
    {
        $this->boot(true);
        $this->setSlots(true, false);
        $before = $this->setting('steward_logo_hash');
        $this->shadcn($this->pngBytes(200, 50), $this->pngBytes(64, 64));

        $this->import();

        $this->assertSame($before, $this->setting('steward_logo_hash'));
        $this->assertNotSame('', $this->setting('steward_favicon_hash'));
        $this->assertSame([['slot' => 'favicon', 'action' => 'import']], $this->importRows());
    }

    public function testSvgLogoIsRefusedByContentNotExtension(): void
    {
        $this->boot(true);
        $svg = (string) file_get_contents(__DIR__.'/fixtures/logo.svg');
        // Named .png on purpose: the type is detected from the bytes.
        $this->shadcn($svg, $this->pngBytes(64, 64), 'png');
        $before = $this->brandingConfig();

        $this->import();

        $this->assertSame(self::SVG_REFUSAL, $this->flash()['failure'] ?? null);
        $this->assertSame($before, $this->brandingConfig());
        $this->assertSame([], $this->importRows());
    }

    public function testImageThatFailsTheProcessorFlashesItsErrorAndChangesNothing(): void
    {
        $this->boot(true);
        $this->shadcn($this->pngBytes(), (string) file_get_contents(__DIR__.'/fixtures/favicon.ico'), 'png', 'ico');
        $before = $this->brandingConfig();
        $objects = $this->storage->objects;

        $this->import();

        $this->assertSame('Unsupported image type', $this->flash()['failure'] ?? null);
        $this->assertSame($before, $this->brandingConfig());
        $this->assertSame($objects, $this->storage->objects);
        $this->assertSame([], $this->importRows());
    }

    public function testImportReadsOnlyShadcnThemeObjects(): void
    {
        $this->boot(true);
        // The memory store does not resolve '..', so both keys exist as given: only the path check stops them.
        $this->storage->objects['tasks/1/secret.png'] = $this->pngBytes();
        $this->storage->objects['shadcn-theme/../tasks/1/secret.png'] = $this->pngBytes(64, 64);
        $this->container['configModel']->save(['shadcn_logo_path' => 'tasks/1/secret.png']);
        $this->container['configModel']->save(['shadcn_favicon_path' => 'shadcn-theme/../tasks/1/secret.png']);

        $this->assertStringNotContainsString('action=import', $this->show());
        $this->import();

        $this->assertSame('', $this->setting('steward_logo_hash'));
        $this->assertSame('', $this->setting('steward_favicon_hash'));
        $this->assertSame('Nothing to import from ShadcnTheme', $this->flash()['failure'] ?? null);
    }

    public function testImportWithNothingToImportChangesNothing(): void
    {
        $this->boot(true);
        $this->import();
        $this->assertSame('Nothing to import from ShadcnTheme', $this->flash()['failure'] ?? null);
        $this->assertSame([], $this->importRows());
    }

    public function testImportRefusesNonAdminMissingCsrfAndGet(): void
    {
        $this->boot(true);
        $this->shadcn($this->pngBytes(), null);

        foreach ([[false, 'POST'], [true, 'GET']] as [$csrf, $method]) {
            try {
                $this->import($csrf, $method);
                $this->fail('expected AccessForbiddenException');
            } catch (AccessForbiddenException) {
            }
        }

        $manager = (new UserModel($this->container))->create(['username' => 'mia', 'role' => Role::APP_MANAGER]);
        $this->container['userSession']->initialize($this->container['userModel']->getById($manager));
        try {
            $this->import();
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame('', $this->setting('steward_logo_hash'));
    }

    public function testImportAnswers404WithBrandingOff(): void
    {
        $this->boot(false);
        $this->shadcn($this->pngBytes(), null);

        $response = $this->import();

        $this->assertSame(404, $response->code());
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body());
        $this->assertSame('', $this->setting('steward_logo_hash'));
    }
}
