<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardSwitchController;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Model\SwitchPolicy;
use Kanboard\Plugin\Steward\Model\SwitchService;
use Kanboard\Plugin\Steward\Plugin;

class StewardUiLogSink extends \Kanboard\Core\Log\Base
{
    public function log($level, $message, array $context = []): void {}
}

/** Spec §2 Flow steps 1 and 4: the profile button, the confirmation modal, the banner and the dropdown item. */
class SwitchUiTest extends StewardTestCase
{
    private const ADMIN = 1;

    private int $ana;
    private int $dave;
    private int $boss;
    private int $gone;
    private ?string $html = null;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();

        $logger = new Logger();
        $logger->setLogger(new StewardUiLogSink());
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $cookie = $this->getMockBuilder(RememberMeCookie::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['remove', 'write'])->getMock();
        $cookie->method('remove')->willReturn(true);
        unset($this->container['rememberMeCookie']);
        $this->container['rememberMeCookie'] = $cookie;

        $users = new UserModel($this->container);
        $this->ana = $users->create(['username' => 'ana', 'name' => '<b>Ana & "Co"</b>', 'password' => 'secret-password']);
        $this->dave = $users->create(['username' => 'dave', 'name' => 'Dave Example', 'password' => 'secret-password']);
        $this->boss = $users->create(['username' => 'boss', 'name' => 'Big Boss', 'password' => 'secret-password', 'role' => Role::APP_ADMIN]);
        $this->gone = $users->create(['username' => 'gone', 'name' => 'Gone Away', 'password' => 'secret-password', 'is_active' => 0]);

        $this->loginAs(self::ADMIN);
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['userSession']->setPostAuthenticationAsValidated();
        $this->container['memoryCache']->flush();
    }

    private function switchTo(int $userId): void
    {
        $this->assertNull($this->container['stewardSwitching']->start($userId));
        $this->assertTrue($this->container['stewardSwitching']->isSwitched());
    }

    private function hook(string $name, array $variables = []): string
    {
        return $this->container['helper']->hook->render($name, $variables);
    }

    private function profileButton(int $userId): string
    {
        return $this->hook('template:user:sidebar:actions', ['user' => $this->container['userModel']->getById($userId)]);
    }

    /** Runs StewardSwitchController::confirm for $get; returns the HTML it answered. */
    private function confirm(array $get): string
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'],
            $get + ['controller' => 'StewardSwitchController', 'action' => 'confirm', 'plugin' => 'Steward'], [], [], []);
        $this->html = null;
        $response = $this->createMock(Response::class);
        $response->method('html')->willReturnCallback(function ($body) { $this->html = (string) $body; });
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardSwitchController($this->container))->confirm();
        $this->assertNotNull($this->html);
        return $this->html;
    }

    private function assertPostFormTo(string $action, string $html): void
    {
        $this->assertMatchesRegularExpression(
            '~<form method="post" action="[^"]*controller=StewardSwitchController&amp;action='.$action.'&amp;plugin=Steward"~', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
    }

    // --- wiring -----------------------------------------------------------------------------------------

    /** The confirmation: admins only, behind the switching feature, refused while switched. */
    public function testConfirmIsAdminOnlyBehindSwitchingAndRefusedWhileSwitched(): void
    {
        $map = $this->container['applicationAccessMap'];
        $this->assertSame($map->getRoleHierarchy(Role::APP_ADMIN), $map->getRoles('StewardSwitchController', 'confirm'));
        $this->assertSame('switching', (new FeatureGate($this->container))->featureFor('StewardSwitchController', 'confirm'));
        $this->assertSame(SwitchActionTable::BLOCKED, (new SwitchActionTable())->classify('Steward', 'StewardSwitchController', 'confirm'));
    }

    public function testStylesheetIsHooked(): void
    {
        $this->assertContains(['template' => 'plugins/Steward/Assets/css/steward.css'], $this->container['hook']->getListeners('template:layout:css'));
        $this->assertFileExists(dirname(__DIR__).'/Assets/css/steward.css');
    }

    /** Core's Dropdown.js removes the cloned menu on any click, before a submit button's form could submit. */
    public function testDropdownScriptIsHookedAndKeepsClicksFromTheDropdownCloser(): void
    {
        $this->assertContains(['template' => 'plugins/Steward/Assets/js/steward.js'], $this->container['hook']->getListeners('template:layout:js'));
        $js = (string) @file_get_contents(dirname(__DIR__).'/Assets/js/steward.js');
        $this->assertStringContainsString('.steward-link-button', $js);
        $this->assertStringContainsString('stopPropagation', $js);
    }

    // --- profile button ---------------------------------------------------------------------------------

    public function testButtonOpensTheConfirmationForAnEligibleUser(): void
    {
        $html = $this->profileButton($this->dave);
        $this->assertStringContainsString('Switch to this user', $html);
        $this->assertStringContainsString('js-modal-confirm', $html);
        $this->assertStringContainsString('controller=StewardSwitchController&amp;action=confirm&amp;plugin=Steward&amp;user_id='.$this->dave, $html);
    }

    public function testButtonHiddenForAdminTargetsSelfAndInactiveUsers(): void
    {
        foreach (['admin target' => $this->boss, 'self' => self::ADMIN, 'inactive' => $this->gone] as $case => $userId) {
            $this->assertStringNotContainsString('StewardSwitchController', $this->profileButton($userId), $case);
        }
    }

    public function testButtonHiddenForNonAdmins(): void
    {
        $this->loginAs($this->ana);
        $this->assertStringNotContainsString('StewardSwitchController', $this->profileButton($this->dave));
    }

    public function testButtonHiddenWhenSwitchingIsOff(): void
    {
        $this->container['configModel']->save(['steward_switching' => '0']);
        $this->container['memoryCache']->flush();
        $this->assertStringNotContainsString('StewardSwitchController', $this->profileButton($this->dave));
    }

    public function testButtonHiddenWhileTwoFactorIsPending(): void
    {
        $this->container['userModel']->update(['id' => self::ADMIN, 'twofactor_activated' => 1]);
        $this->container['userSession']->initialize($this->container['userModel']->getById(self::ADMIN));
        $this->assertStringNotContainsString('StewardSwitchController', $this->profileButton($this->dave));
    }

    // --- confirmation modal -----------------------------------------------------------------------------

    public function testConfirmationNamesTheUserAndSaysActionsAreRecordedAsThem(): void
    {
        $html = $this->confirm(['user_id' => (string) $this->ana]);
        $this->assertStringContainsString('&lt;b&gt;Ana &amp; &quot;Co&quot;&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Ana', $html);
        $this->assertStringContainsString('recorded as', $html);
        $this->assertStringContainsString('audit log', $html);
        $this->assertPostFormTo('start', $html);
        $this->assertStringContainsString('name="user_id" id="form-user_id" value="'.$this->ana.'"', $html);
        $this->assertStringContainsString('js-submit-buttons', $html);
    }

    public function testConfirmationForAnIneligibleOrUnknownTargetShowsOnlyTheUniformMessage(): void
    {
        foreach (['admin target' => (string) $this->boss, 'unknown' => '9999', 'not a number' => 'x'] as $case => $userId) {
            $html = $this->confirm(['user_id' => $userId]);
            $this->assertStringContainsString(htmlspecialchars(SwitchPolicy::UNIFORM_MESSAGE, ENT_QUOTES), $html, $case);
            $this->assertStringNotContainsString('<form', $html, $case);
            $this->assertStringNotContainsString('Big Boss', $html, $case);
        }
    }

    // --- banner and dropdown ----------------------------------------------------------------------------

    public function testNoBannerOrDropdownItemWhenNotSwitched(): void
    {
        $this->assertSame('', trim($this->hook('template:layout:top')));
        $this->assertSame('', trim($this->hook('template:header:dropdown')));
    }

    public function testBannerNamesTheTargetEscapedAndPostsSwitchBack(): void
    {
        $this->switchTo($this->ana);
        $html = $this->hook('template:layout:top');
        $this->assertStringContainsString('You are viewing Kanboard as <strong>&lt;b&gt;Ana &amp; &quot;Co&quot;&lt;/b&gt;</strong>', $html);
        $this->assertStringNotContainsString('<b>Ana', $html);
        $this->assertStringContainsString('recorded as', $html);
        $this->assertStringContainsString('steward-banner-warning', $html);
        $this->assertPostFormTo('back', $html);
        $this->assertStringContainsString('Switch back', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testDropdownHasSwitchBackWhenSwitched(): void
    {
        $this->switchTo($this->ana);
        $html = $this->hook('template:header:dropdown');
        $this->assertStringStartsWith('<li', trim($html));
        $this->assertPostFormTo('back', $html);
        $this->assertStringContainsString('Switch back', $html);
    }

    /** Task 24's announcement banner reuses the partial. */
    public function testBannerPartialIsGeneric(): void
    {
        $render = fn (array $vars) => $this->container['template']->render('Steward:switch/banner', $vars);

        $html = $render(['level' => 'info', 'message_html' => 'Hello <em>world</em>', 'form' => null]);
        $this->assertStringContainsString('steward-banner-info', $html);
        $this->assertStringContainsString('Hello <em>world</em>', $html);
        $this->assertStringNotContainsString('<form', $html);

        $this->assertStringContainsString('steward-banner-info', $render(['level' => '"><script>', 'message_html' => 'x', 'form' => null]));

        $html = $render(['level' => 'error', 'message_html' => 'x', 'form' => ['action' => '/?a=1&amp;b=2', 'label' => 'Do <it>']]);
        $this->assertStringContainsString('steward-banner-error', $html);
        $this->assertStringContainsString('action="/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('Do &lt;it&gt;', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
    }
}
