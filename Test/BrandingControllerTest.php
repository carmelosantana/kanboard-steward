<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\ObjectStorage\ObjectStorageException;
use Kanboard\Core\ObjectStorage\ObjectStorageInterface;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardBrandingController;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\Branding;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SettingsSchema;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;

/** In-memory ObjectStorage that can be told to fail. */
class StewardMemoryStorage implements ObjectStorageInterface
{
    public array $objects = [];
    public bool $failPut = false;
    public bool $failRemove = false;

    public function get($key)
    {
        if (! isset($this->objects[$key])) {
            throw new ObjectStorageException('missing '.$key);
        }
        return $this->objects[$key];
    }

    public function put($key, &$blob)
    {
        if ($this->failPut) {
            throw new ObjectStorageException('disk full');
        }
        $this->objects[$key] = $blob;
    }

    public function output($key)
    {
        echo $this->get($key);
    }

    public function moveFile($filename, $key)
    {
        throw new ObjectStorageException('not used');
    }

    public function moveUploadedFile($filename, $key)
    {
        throw new ObjectStorageException('not used');
    }

    public function remove($key)
    {
        if ($this->failRemove) {
            throw new ObjectStorageException('cannot remove');
        }
        unset($this->objects[$key]);
        return true;
    }
}

/** Records what would be sent instead of calling header(). */
class StewardBrandingResponse extends Response
{
    public bool $sent = false;

    public function send()
    {
        $this->sent = true;
    }

    private function prop(string $name): mixed
    {
        $p = new ReflectionProperty(Response::class, $name);
        return $p->getValue($this);
    }

    public function headers(): array { return $this->prop('httpHeaders'); }
    public function code(): int { return $this->prop('httpStatusCode'); }
    public function body(): string { return (string) $this->prop('httpBody'); }
}

class StewardBrandingBodyOnlyLayoutHelper extends \Kanboard\Helper\LayoutHelper
{
    public function config($template, array $params)
    {
        return $this->template->render($template, $params);
    }
}

class StewardBrandingLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

class BrandingControllerTest extends Base
{
    private const ADMIN = 1;

    private StewardMemoryStorage $storage;
    private StewardBrandingLogSink $log;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['helper']->register('layout', StewardBrandingBodyOnlyLayoutHelper::class);
        $this->container['configModel']->save(['steward_branding' => '1']);
        $this->container['memoryCache']->flush();

        $this->storage = new StewardMemoryStorage();
        unset($this->container['objectStorage']);
        $this->container['objectStorage'] = $this->storage;

        $this->log = new StewardBrandingLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $this->tmpDir = sys_get_temp_dir().'/steward-brand-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir);

        $this->loginAs(self::ADMIN);
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

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function png(int $width = 600, int $height = 200, array $rgb = [20, 120, 220]): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        $path = $this->tmpDir.'/'.bin2hex(random_bytes(4)).'.png';
        imagepng($im, $path);
        return $path;
    }

    private function file(string $path): array
    {
        return ['name' => basename($path), 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    /** Runs save(); returns the response. */
    private function save(array $post, array $files = [], bool $csrf = true, string $method = 'POST'): StewardBrandingResponse
    {
        // The real form always posts the site name field.
        $post += ['steward_site_name' => ''];
        if ($csrf) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method],
            ['controller' => 'StewardBrandingController', 'action' => 'save', 'plugin' => 'Steward'], $post, $files, []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardBrandingController($this->container))->save();
        return $response;
    }

    private function image(array $get, array $server = []): StewardBrandingResponse
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, $server + ['REQUEST_METHOD' => 'GET'],
            $get + ['controller' => 'StewardBrandingController', 'action' => 'image', 'plugin' => 'Steward'], [], [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardBrandingController($this->container))->image();
        return $response;
    }

    private function setting(string $key): string
    {
        $this->container['memoryCache']->flush();
        return (string) $this->container['configModel']->getOption($key, '');
    }

    private function flash(): array
    {
        return session_exists('flash') ? (array) session_get('flash') : [];
    }

    private function auditRows(string $event): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->eq('event', $event)->asc('id')->findAll();
    }

    private function brandingConfig(): array
    {
        $out = [];
        foreach (['steward_logo_path', 'steward_logo_hash', 'steward_favicon32_path', 'steward_favicon180_path', 'steward_favicon_hash', 'steward_site_name'] as $key) {
            $out[$key] = $this->setting($key);
        }
        return $out;
    }

    // --- wiring -----------------------------------------------------------------------------------------

    public function testWiring(): void
    {
        $map = $this->container['applicationAccessMap'];
        $this->assertSame([Role::APP_ADMIN], $map->getRoles('StewardBrandingController', 'show'));
        $this->assertSame([Role::APP_ADMIN], $map->getRoles('StewardBrandingController', 'save'));
        $this->assertSame($map->getRoleHierarchy(Role::APP_PUBLIC), $map->getRoles('StewardBrandingController', 'image'));
        $this->assertTrue($this->container['stewardAcl']->verify());

        $gate = new FeatureGate($this->container);
        foreach (['show', 'save', 'image'] as $action) {
            $this->assertSame('branding', $gate->featureFor('StewardBrandingController', $action));
        }

        $table = new SwitchActionTable();
        $this->assertSame('blocked', $table->classify('Steward', 'StewardBrandingController', 'save'));
        $this->assertSame('blocked', $table->classify('Steward', 'StewardBrandingController', 'show'));
        $this->assertSame('read', $table->classify('Steward', 'StewardBrandingController', 'image'));
    }

    public function testSchemaKnowsBrandingKeysButSettingsFormCannotSetThem(): void
    {
        $defaults = SettingsSchema::defaults();
        foreach (['steward_site_name', 'steward_logo_path', 'steward_logo_hash', 'steward_favicon32_path', 'steward_favicon180_path', 'steward_favicon_hash'] as $key) {
            $this->assertSame('', $defaults[$key], $key);
        }
        $this->assertSame([], SettingsSchema::filter([
            'steward_logo_path' => 'tasks/1/secret.pdf',
            'steward_logo_hash' => 'x',
            'steward_favicon32_path' => 'x',
            'steward_favicon180_path' => 'x',
            'steward_favicon_hash' => 'x',
            'steward_site_name' => 'x',
        ]));
    }

    // --- settings page ----------------------------------------------------------------------------------

    public function testShowRendersFormForAdminOnly(): void
    {
        $this->container['configModel']->save(['steward_site_name' => '<b>Acme</b>']);
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['plugin' => 'Steward'], [], [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardBrandingController($this->container))->show();
        $html = $response->body();

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('id="form-steward_site_name"', $html);
        $this->assertStringContainsString('name="logo"', $html);
        $this->assertStringContainsString('name="favicon"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('&lt;b&gt;Acme&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Acme</b>', $html);

        $manager = (new UserModel($this->container))->create(['username' => 'mia', 'role' => Role::APP_MANAGER]);
        $this->loginAs($manager);
        $this->expectException(AccessForbiddenException::class);
        (new StewardBrandingController($this->container))->show();
    }

    public function testSidebarLinksToBrandingPageWhenEnabled(): void
    {
        $html = $this->container['template']->render('Steward:config/sidebar', []);
        $this->assertStringContainsString('StewardBrandingController', $html);

        $this->container['configModel']->save(['steward_branding' => '0']);
        $this->container['memoryCache']->flush();
        $html = $this->container['template']->render('Steward:config/sidebar', []);
        $this->assertStringNotContainsString('StewardBrandingController', $html);
    }

    // --- save: access -----------------------------------------------------------------------------------

    public function testNonAdminCannotSave(): void
    {
        $manager = (new UserModel($this->container))->create(['username' => 'mia', 'role' => Role::APP_MANAGER]);
        $this->loginAs($manager);
        try {
            $this->save(['steward_site_name' => 'Hijacked'], ['logo' => $this->file($this->png())]);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame('', $this->setting('steward_site_name'));
        $this->assertSame([], $this->storage->objects);
    }

    public function testMissingCsrfOrGetIsRefused(): void
    {
        foreach ([[false, 'POST'], [true, 'GET']] as [$csrf, $method]) {
            try {
                $this->save(['steward_site_name' => 'Acme'], [], $csrf, $method);
                $this->fail('expected AccessForbiddenException');
            } catch (AccessForbiddenException) {
            }
        }
        $this->assertSame('', $this->setting('steward_site_name'));
    }

    // --- save: uploads ----------------------------------------------------------------------------------

    public function testLogoUploadStoresPngAndAudits(): void
    {
        $response = $this->save(['steward_site_name' => ''], ['logo' => $this->file($this->png())]);

        $hash = $this->setting('steward_logo_hash');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame('steward/branding/logo-'.$hash.'.png', $this->setting('steward_logo_path'));
        $this->assertSame(['steward/branding/logo-'.$hash.'.png'], array_keys($this->storage->objects));
        $blob = $this->storage->objects['steward/branding/logo-'.$hash.'.png'];
        $this->assertStringStartsWith("\x89PNG", $blob);
        $this->assertSame([384, 128], array_slice(getimagesizefromstring($blob), 0, 2));
        $this->assertSame(hash('sha256', $blob), $hash);
        $this->assertArrayHasKey('success', $this->flash());
        $this->assertStringContainsString('StewardBrandingController', $response->headers()['Location']);

        $rows = $this->auditRows('branding.changed');
        $this->assertCount(1, $rows);
        $this->assertSame(['slot' => 'logo', 'action' => 'upload'], json_decode($rows[0]['details'], true));
        $this->assertSame(self::ADMIN, (int) $rows[0]['actor_user_id']);
        $this->assertSame([], $this->auditRows('settings.changed'));
    }

    public function testFaviconUploadStoresBothSizesUnderOneHash(): void
    {
        $this->save([], ['favicon' => $this->file($this->png(300, 200))]);

        $hash = $this->setting('steward_favicon_hash');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $p32 = $this->setting('steward_favicon32_path');
        $p180 = $this->setting('steward_favicon180_path');
        $this->assertSame('steward/branding/favicon32-'.$hash.'.png', $p32);
        $this->assertSame('steward/branding/favicon180-'.$hash.'.png', $p180);
        $this->assertSame([32, 32], array_slice(getimagesizefromstring($this->storage->objects[$p32]), 0, 2));
        $this->assertSame([180, 180], array_slice(getimagesizefromstring($this->storage->objects[$p180]), 0, 2));
        $this->assertSame(hash('sha256', $this->storage->objects[$p32].$this->storage->objects[$p180]), $hash);
        $this->assertSame('', $this->setting('steward_logo_path'));
        $this->assertSame(['slot' => 'favicon', 'action' => 'upload'], json_decode($this->auditRows('branding.changed')[0]['details'], true));
    }

    public function testReplacingDeletesTheOldObjectAfterTheSwap(): void
    {
        $this->save([], ['logo' => $this->file($this->png())]);
        $old = $this->setting('steward_logo_path');
        $this->save([], ['logo' => $this->file($this->png(600, 200, [200, 30, 30]))]);
        $new = $this->setting('steward_logo_path');

        $this->assertNotSame($old, $new);
        $this->assertSame([$new], array_keys($this->storage->objects));
        $this->assertCount(2, $this->auditRows('branding.changed'));
    }

    public function testReuploadingTheSameImageKeepsItsObject(): void
    {
        $path = $this->png();
        $this->save([], ['logo' => $this->file($path)]);
        $this->save([], ['logo' => $this->file($path)]);
        $key = $this->setting('steward_logo_path');
        $this->assertSame([$key], array_keys($this->storage->objects));
    }

    public function testFailedProcessingKeepsOldAssetAndConfig(): void
    {
        $this->save([], ['logo' => $this->file($this->png())]);
        $before = $this->brandingConfig();
        $objects = $this->storage->objects;
        session_set('flash', []);

        $svg = $this->tmpDir.'/logo.svg';
        copy(__DIR__.'/fixtures/logo.svg', $svg);
        $this->save(['steward_site_name' => 'Acme'], ['logo' => $this->file($svg) + ['type' => 'image/svg+xml']]);

        $this->assertSame($before, $this->brandingConfig());
        $this->assertSame($objects, $this->storage->objects);
        $flash = $this->flash();
        $this->assertArrayNotHasKey('success', $flash);
        $this->assertStringContainsString('Unsupported image type', $flash['failure']);
        $this->assertCount(1, $this->auditRows('branding.changed'));
        $this->assertSame([], $this->auditRows('settings.changed'));
    }

    public function testOneBadUploadRejectsTheWholeForm(): void
    {
        $bad = $this->tmpDir.'/favicon.ico';
        copy(__DIR__.'/fixtures/favicon.ico', $bad);
        $this->save([], ['logo' => $this->file($this->png()), 'favicon' => $this->file($bad)]);

        $this->assertSame('', $this->setting('steward_logo_path'));
        $this->assertSame([], $this->storage->objects);
        $this->assertArrayHasKey('failure', $this->flash());
    }

    public function testUploadErrorIsRefused(): void
    {
        $this->save([], ['logo' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]);
        $this->assertSame([], $this->storage->objects);
        $this->assertArrayHasKey('failure', $this->flash());
    }

    public function testEmptyFileInputIsIgnored(): void
    {
        $this->save(['steward_site_name' => 'Acme'], ['logo' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]]);
        $this->assertSame('Acme', $this->setting('steward_site_name'));
        $this->assertArrayHasKey('success', $this->flash());
        $this->assertSame([], $this->auditRows('branding.changed'));
    }

    public function testStorageWriteFailureLeavesConfigAndOldObject(): void
    {
        $this->save([], ['logo' => $this->file($this->png())]);
        $before = $this->brandingConfig();
        $objects = $this->storage->objects;
        session_set('flash', []);

        $this->storage->failPut = true;
        $this->save([], ['logo' => $this->file($this->png(600, 200, [1, 2, 3]))]);

        $this->assertSame($before, $this->brandingConfig());
        $this->assertSame($objects, $this->storage->objects);
        $this->assertArrayHasKey('failure', $this->flash());
        $this->assertCount(1, $this->auditRows('branding.changed'));
    }

    public function testOldObjectDeleteFailureIsLoggedNotFatal(): void
    {
        $this->save([], ['logo' => $this->file($this->png())]);
        $old = $this->setting('steward_logo_path');
        session_set('flash', []);

        $this->storage->failRemove = true;
        $this->save([], ['logo' => $this->file($this->png(600, 200, [9, 9, 9]))]);

        $new = $this->setting('steward_logo_path');
        $this->assertNotSame($old, $new);
        $this->assertArrayHasKey($new, $this->storage->objects);
        $this->assertArrayHasKey('success', $this->flash());
        $errors = array_filter($this->log->lines, fn ($l) => str_contains($l[1], $old));
        $this->assertNotEmpty($errors);
    }

    public function testRemoveClearsConfigAndObjectsAndAudits(): void
    {
        $this->save([], ['favicon' => $this->file($this->png())]);
        $this->assertCount(2, $this->storage->objects);

        $this->save(['remove_favicon' => '1']);

        $this->assertSame('', $this->setting('steward_favicon32_path'));
        $this->assertSame('', $this->setting('steward_favicon180_path'));
        $this->assertSame('', $this->setting('steward_favicon_hash'));
        $this->assertSame([], $this->storage->objects);
        $rows = $this->auditRows('branding.changed');
        $this->assertSame(['slot' => 'favicon', 'action' => 'remove'], json_decode($rows[1]['details'], true));

        // Removing an empty slot changes nothing and audits nothing.
        $this->save(['remove_logo' => '1']);
        $this->assertCount(2, $this->auditRows('branding.changed'));
    }

    // --- save: site name --------------------------------------------------------------------------------

    public function testSiteNameIsTrimmedSavedAndAudited(): void
    {
        $name = str_repeat('é', 58);
        $this->save(['steward_site_name' => "  $name  "]);
        $this->assertSame($name, $this->setting('steward_site_name'));
        $this->assertSame($name, $this->container['stewardBranding']->siteName());

        $rows = $this->auditRows('settings.changed');
        $this->assertCount(1, $rows);
        $this->assertSame(['keys' => ['steward_site_name']], json_decode($rows[0]['details'], true));

        // Unchanged: no second row.
        $this->save(['steward_site_name' => $name]);
        $this->assertCount(1, $this->auditRows('settings.changed'));
    }

    public function testSiteNameOf61CharsIsRejected(): void
    {
        $this->save(['steward_site_name' => 'Acme']);
        session_set('flash', []);

        $this->save(['steward_site_name' => str_repeat('a', 61)], ['logo' => $this->file($this->png())]);

        $this->assertSame('Acme', $this->setting('steward_site_name'));
        $this->assertSame([], $this->storage->objects);
        $this->assertArrayHasKey('failure', $this->flash());
        $this->assertCount(1, $this->auditRows('settings.changed'));

        $this->save(['steward_site_name' => str_repeat('a', 60)]);
        $this->assertSame(str_repeat('a', 60), $this->setting('steward_site_name'));
    }

    public function testSiteNameReaderIsDefensive(): void
    {
        $this->container['configModel']->save(['steward_site_name' => '  '.str_repeat('b', 70)]);
        $this->assertSame(str_repeat('b', 60), $this->container['stewardBranding']->siteName());
        $this->container['configModel']->save(['steward_site_name' => '']);
        $this->assertSame('', $this->container['stewardBranding']->siteName());
    }

    // --- image serving ----------------------------------------------------------------------------------

    public function testImageWithMatchingHashIsImmutable(): void
    {
        $this->save([], ['logo' => $this->file($this->png())]);
        $hash = $this->setting('steward_logo_hash');

        $response = $this->image(['slot' => 'logo', 'h' => $hash]);
        $headers = $response->headers();

        $this->assertTrue($response->sent);
        $this->assertSame(200, $response->code());
        $this->assertSame('image/png', $headers['Content-Type']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame("default-src 'none'; sandbox", $headers['Content-Security-Policy']);
        $this->assertSame('"'.$hash.'"', $headers['ETag']);
        $this->assertSame('public, max-age=31536000, immutable', $headers['Cache-Control']);
        // Replace the session cache limiter's past Expires and Pragma: no-cache.
        $this->assertSame('cache', $headers['Pragma']);
        $this->assertGreaterThan(time() + 86400 * 300, strtotime($headers['Expires']));
        $this->assertSame($this->storage->objects[$this->setting('steward_logo_path')], $response->body());
    }

    public function testImageWithoutOrWithStaleHashIsNotCachedLong(): void
    {
        $this->save([], ['favicon' => $this->file($this->png())]);
        $hash = $this->setting('steward_favicon_hash');

        foreach ([[], ['h' => 'stale'], ['h' => strtoupper($hash)]] as $extra) {
            $response = $this->image(['slot' => 'favicon180'] + $extra);
            $headers = $response->headers();
            $this->assertSame(200, $response->code());
            $this->assertSame('no-cache', $headers['Cache-Control']);
            $this->assertArrayNotHasKey('Expires', $headers);
            $this->assertSame('"'.$hash.'"', $headers['ETag']);
            $this->assertSame([180, 180], array_slice(getimagesizefromstring($response->body()), 0, 2));
        }
        $this->assertSame([32, 32], array_slice(getimagesizefromstring($this->image(['slot' => 'favicon32'])->body()), 0, 2));
    }

    public function testIfNoneMatchAnswers304(): void
    {
        $this->save([], ['logo' => $this->file($this->png())]);
        $hash = $this->setting('steward_logo_hash');

        foreach (['"'.$hash.'"', 'W/"'.$hash.'"', '"other", "'.$hash.'"', '*'] as $inm) {
            $response = $this->image(['slot' => 'logo', 'h' => $hash], ['HTTP_IF_NONE_MATCH' => $inm]);
            $this->assertSame(304, $response->code(), $inm);
            $this->assertSame('', $response->body());
            $this->assertSame('"'.$hash.'"', $response->headers()['ETag']);
        }

        $response = $this->image(['slot' => 'logo'], ['HTTP_IF_NONE_MATCH' => '"other"']);
        $this->assertSame(200, $response->code());
    }

    public function testUnknownUnsetOrMissingSlotIs404(): void
    {
        foreach ([['slot' => 'nope'], ['slot' => '../../config'], ['slot' => ['logo']], [], ['slot' => 'logo'], ['slot' => 'favicon32']] as $get) {
            $response = $this->image($get);
            $this->assertSame(404, $response->code(), json_encode($get));
            $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body());
            $this->assertStringNotContainsString('image/png', (string) ($response->headers()['Content-Type'] ?? ''));
        }

        // Config points at an object that is gone.
        $this->save([], ['logo' => $this->file($this->png())]);
        $this->storage->objects = [];
        $this->assertSame(404, $this->image(['slot' => 'logo'])->code());

        // Config tampered to point outside steward/branding/.
        $this->storage->objects['tasks/1/secret.png'] = 'secret';
        $this->container['configModel']->save(['steward_logo_path' => 'tasks/1/secret.png']);
        $this->assertSame(404, $this->image(['slot' => 'logo'])->code());
    }

    /** Defence in depth (spec §1): each action repeats the gate's feature check. Called directly, bypassing it. */
    public function testEveryActionAnswers404WithBrandingOff(): void
    {
        $this->save(['steward_site_name' => 'Acme'], ['logo' => $this->file($this->png())]);
        $hash = $this->setting('steward_logo_hash');
        $before = $this->brandingConfig();
        $objects = $this->storage->objects;
        $audit = $this->container['db']->table(AuditLog::TABLE)->count();
        session_set('flash', []);

        $this->container['configModel']->save(['steward_branding' => '0']);
        $this->container['memoryCache']->flush();

        $image = $this->image(['slot' => 'logo', 'h' => $hash]);
        $this->assertSame(404, $image->code());
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $image->body());

        $save = $this->save(['steward_site_name' => 'Changed', 'remove_logo' => '1'], ['favicon' => $this->file($this->png())]);
        $this->assertSame(404, $save->code());
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $save->body());
        $this->assertSame($before, $this->brandingConfig());
        $this->assertSame($objects, $this->storage->objects);
        $this->assertSame($audit, $this->container['db']->table(AuditLog::TABLE)->count());
        $this->assertSame([], $this->flash());

        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['plugin' => 'Steward'], [], [], []);
        $show = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $show;
        (new StewardBrandingController($this->container))->show();
        $this->assertSame(404, $show->code());
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $show->body());
    }

    public function testBrandingUrlsCarryTheHash(): void
    {
        $branding = $this->container['stewardBranding'];
        $this->assertSame('', $branding->imageUrl('logo'));
        $this->save([], ['logo' => $this->file($this->png())]);
        $url = $branding->imageUrl('logo');
        $this->assertStringContainsString('StewardBrandingController', $url);
        $this->assertStringContainsString('slot=logo', $url);
        $this->assertStringContainsString('h='.$this->setting('steward_logo_hash'), $url);
        $this->assertInstanceOf(Branding::class, $branding);
    }
}
