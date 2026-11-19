<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';
require_once __DIR__.'/support/BrandingDoubles.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Mail\ClientInterface;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardTestEmailController;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;

class StewardTestEmailLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

/** Records every send; core's Client builds a new instance per container lookup, so the record is static. */
class StewardFakeTransport implements ClientInterface
{
    public static array $sent = [];

    public function __construct($container)
    {
    }

    public function sendEmail($recipientEmail, $recipientName, $subject, $html, $authorName, $authorEmail = '')
    {
        self::$sent[] = compact('recipientEmail', 'recipientName', 'subject', 'html', 'authorName', 'authorEmail');
    }
}

class StewardThrowingTransport implements ClientInterface
{
    public const DEFAULT = 'SMTP auth failed for user=ops password=hunter2 (api_key: sk-live-123)';
    public static string $message = self::DEFAULT;

    public function __construct($container)
    {
    }

    public function sendEmail($recipientEmail, $recipientName, $subject, $html, $authorName, $authorEmail = '')
    {
        throw new RuntimeException(self::$message,
            0, new RuntimeException('Authorization: Bearer abc.def'));
    }
}

class TestEmailTest extends Base
{
    private const ADMIN = 1;

    private StewardTestEmailLogSink $log;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        // On before initialize(): the route and the core email page hook are wired only with the feature on.
        $this->container['configModel']->save(['steward_test_email' => '1', 'mail_transport' => 'stewardfake']);
        $this->container['memoryCache']->flush();
        (new Plugin($this->container))->initialize();
        $this->container['helper']->register('layout', StewardBrandingBodyOnlyLayoutHelper::class);
        // Core 1.2.57's test Base stubs the whole client (no transports); use a real one, as 1.2.47's Base does.
        $client = $this->getMockBuilder(\Kanboard\Core\Mail\Client::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['send'])
            ->getMock();
        unset($this->container['emailClient']);
        $this->container['emailClient'] = $client;
        $this->container['emailClient']->setTransport('stewardfake', StewardFakeTransport::class);
        $this->container['emailClient']->setTransport('stewardthrow', StewardThrowingTransport::class);
        $this->container['memoryCache']->flush();
        StewardFakeTransport::$sent = [];
        StewardThrowingTransport::$message = StewardThrowingTransport::DEFAULT;

        $this->log = new StewardTestEmailLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $this->container['userModel']->update(['id' => self::ADMIN, 'email' => 'admin@example.test', 'name' => 'Ada Admin']);
        $this->loginAs(self::ADMIN);
    }

    protected function tearDown(): void
    {
        session_set('flash', []);
        parent::tearDown();
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function send(array $post = [], bool $csrf = true, string $method = 'POST', array $get = []): StewardBrandingResponse
    {
        if ($csrf) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method],
            $get + ['controller' => 'StewardTestEmailController', 'action' => 'send', 'plugin' => 'Steward'], $post, [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardTestEmailController($this->container))->send();
        return $response;
    }

    private function flash(): array
    {
        return session_exists('flash') ? (array) session_get('flash') : [];
    }

    private function auditRows(): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->eq('event', 'email.test')->asc('id')->findAll();
    }

    private function allowNextSend(): void
    {
        $this->container['userMetadataModel']->save(self::ADMIN, ['steward_test_email_at' => (string) (time() - 31)]);
        session_set('flash', []);
    }

    // --- wiring -----------------------------------------------------------------------------------------

    public function testWiring(): void
    {
        $map = $this->container['applicationAccessMap'];
        $this->assertSame([Role::APP_ADMIN], $map->getRoles('StewardTestEmailController', 'send'));
        $this->assertTrue($this->container['stewardAcl']->verify());
        $this->assertSame('test_email', (new FeatureGate($this->container))->featureFor('StewardTestEmailController', 'send'));
        $this->assertSame('blocked', (new SwitchActionTable())->classify('Steward', 'StewardTestEmailController', 'send'));
    }

    // --- access -----------------------------------------------------------------------------------------

    public function testNonAdminIsRefused(): void
    {
        $manager = (new UserModel($this->container))->create(['username' => 'mia', 'role' => Role::APP_MANAGER, 'email' => 'mia@example.test']);
        $this->loginAs($manager);
        try {
            $this->send();
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame([], StewardFakeTransport::$sent);
        $this->assertSame([], $this->auditRows());
    }

    public function testMissingCsrfOrGetIsRefused(): void
    {
        foreach ([[false, 'POST'], [true, 'GET']] as [$csrf, $method]) {
            try {
                $this->send([], $csrf, $method);
                $this->fail('expected AccessForbiddenException');
            } catch (AccessForbiddenException) {
            }
        }
        $this->assertSame([], StewardFakeTransport::$sent);
    }

    public function testFeatureOffAnswersTheFixed404(): void
    {
        $this->container['configModel']->save(['steward_test_email' => '0']);
        $this->container['memoryCache']->flush();
        $response = $this->send();
        $this->assertSame(404, $response->code());
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body());
        $this->assertSame([], StewardFakeTransport::$sent);
        $this->assertSame([], $this->flash());
    }

    // --- recipient --------------------------------------------------------------------------------------

    public function testPostedRecipientIsIgnored(): void
    {
        $this->send(['to' => 'victim@example.test', 'email' => 'victim@example.test']);
        $this->assertCount(1, StewardFakeTransport::$sent);
        $this->assertSame('admin@example.test', StewardFakeTransport::$sent[0]['recipientEmail']);
    }

    public function testEmptyProfileEmailIsRefused(): void
    {
        $this->container['userModel']->update(['id' => self::ADMIN, 'email' => '']);
        $this->send(['to' => 'victim@example.test']);
        $this->assertSame([], StewardFakeTransport::$sent);
        $this->assertSame('Add an email address to your profile first', $this->flash()['failure'] ?? null);
        $this->assertSame([], $this->auditRows());
    }

    // --- cooldown ---------------------------------------------------------------------------------------

    public function testSecondSendWithin30SecondsIsRefused(): void
    {
        $this->send();
        session_set('flash', []);
        $this->send();
        $this->assertCount(1, StewardFakeTransport::$sent);
        $this->assertSame('Please wait a few seconds', $this->flash()['failure'] ?? null);
        $this->assertCount(1, $this->auditRows());

        $this->allowNextSend();
        $this->send();
        $this->assertCount(2, StewardFakeTransport::$sent);
    }

    public function testCooldownIsPerAdmin(): void
    {
        $this->send();
        $other = (new UserModel($this->container))->create(['username' => 'ops', 'role' => Role::APP_ADMIN, 'email' => 'ops@example.test']);
        $this->loginAs($other);
        session_set('flash', []);
        $this->send();
        $this->assertCount(2, StewardFakeTransport::$sent);
        $this->assertSame('ops@example.test', StewardFakeTransport::$sent[1]['recipientEmail']);
    }

    public function testFailedSendStillStartsTheCooldown(): void
    {
        $this->container['configModel']->save(['mail_transport' => 'stewardthrow']);
        $this->container['memoryCache']->flush();
        $this->send();
        $this->container['configModel']->save(['mail_transport' => 'stewardfake']);
        $this->container['memoryCache']->flush();
        session_set('flash', []);
        $this->send();
        $this->assertSame([], StewardFakeTransport::$sent);
        $this->assertSame('Please wait a few seconds', $this->flash()['failure'] ?? null);
    }

    // --- outcomes ---------------------------------------------------------------------------------------

    public function testSuccessSendsThroughTheActiveTransportAndAudits(): void
    {
        $response = $this->send();

        $this->assertCount(1, StewardFakeTransport::$sent);
        $mail = StewardFakeTransport::$sent[0];
        $this->assertSame('Kanboard test email', $mail['subject']);
        $this->assertSame('Ada Admin', $mail['recipientName']);
        $this->assertStringContainsString('Kanboard', $mail['html']);
        $this->assertSame('Handed to stewardfake without error — check your inbox', $this->flash()['success'] ?? null);

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::ADMIN, (int) $rows[0]['actor_user_id']);
        $details = json_decode($rows[0]['details'], true);
        $this->assertSame('stewardfake', $details['transport']);
        $this->assertTrue($details['ok']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $details['ref']);

        $this->assertStringContainsString('StewardSettingsController', $response->headers()['Location'] ?? '');
    }

    public function testFailureFlashesOnlyTheRefAndLogsRedactedDetail(): void
    {
        $this->container['configModel']->save(['mail_transport' => 'stewardthrow']);
        $this->container['memoryCache']->flush();
        $this->send();

        $failure = $this->flash()['failure'] ?? '';
        $this->assertMatchesRegularExpression('/^The test email failed \(ref ([0-9a-f]{8})\)$/', $failure);
        $this->assertStringNotContainsString('hunter2', $failure);
        $this->assertStringNotContainsString('SMTP', $failure);
        preg_match('/ref ([0-9a-f]{8})/', $failure, $m);

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $details = json_decode($rows[0]['details'], true);
        $this->assertSame(['transport' => 'stewardthrow', 'ok' => false, 'ref' => $m[1]], $details);

        $logged = implode("\n", array_column($this->log->lines, 1));
        $this->assertStringContainsString($m[1], $logged);
        $this->assertStringContainsString('password=[redacted]', $logged);
        $this->assertStringContainsString('api_key=[redacted]', $logged);
        $this->assertStringContainsString('Authorization=[redacted]', $logged);
        $this->assertStringNotContainsString('hunter2', $logged);
        $this->assertStringNotContainsString('sk-live-123', $logged);
        $this->assertStringNotContainsString('Bearer', $logged);
        $this->assertStringNotContainsString('abc.def', $logged);
        $this->assertStringNotContainsString('ops', $logged);
    }

    public static function loggedShapes(): array
    {
        return [
            'url userinfo' => ['Connection to smtp://ops:hunter2@mail.example.test failed', ['ops:', 'hunter2'], 'smtp://[redacted]@'],
            'auth plain' => ['Expected 235 after AUTH PLAIN AG9wcwBodW50ZXIy', ['AG9wcwBodW50ZXIy'], 'AUTH PLAIN [redacted]'],
            'quoted password' => ['password="correct horse" rejected', ['correct', 'horse'], 'password=[redacted]'],
            'json body' => ['Resend 401: {"Authorization":"Bearer abc.def"} {"password":"x y"}', ['Bearer', 'abc.def', 'x y'], '"Authorization=[redacted]'],
            'basic header' => ['Authorization: Basic b3BzOmh1bnRlcjI=', ['Basic', 'b3BzOmh1bnRlcjI='], 'Authorization=[redacted]'],
        ];
    }

    /** @dataProvider loggedShapes */
    #[DataProvider('loggedShapes')]
    public function testLoggedFailureNeverCarriesTheSecret(string $message, array $gone, string $kept): void
    {
        StewardThrowingTransport::$message = $message;
        $this->container['configModel']->save(['mail_transport' => 'stewardthrow']);
        $this->container['memoryCache']->flush();
        $this->send();
        $line = implode("\n", array_column($this->log->lines, 1));
        $this->assertStringContainsString('Steward test email ref', $line);
        $this->assertStringContainsString($kept, $line);
        foreach ($gone as $secret) {
            $this->assertStringNotContainsString($secret, $line);
            $this->assertStringNotContainsString($secret, (string) ($this->flash()['failure'] ?? ''));
        }
    }

    public function testUnknownTransportFailsCleanly(): void
    {
        $this->container['configModel']->save(['mail_transport' => 'nosuch']);
        $this->container['memoryCache']->flush();
        $this->send();
        $this->assertMatchesRegularExpression('/^The test email failed \(ref [0-9a-f]{8}\)$/', $this->flash()['failure'] ?? '');
        $this->assertFalse(json_decode($this->auditRows()[0]['details'], true)['ok']);
    }

    public function testRedirectsBackToCoreEmailSettingsWhenAsked(): void
    {
        $response = $this->send([], true, 'POST', ['redirect' => 'email']);
        $this->assertStringContainsString('ConfigController', $response->headers()['Location'] ?? '');
        $this->assertStringContainsString('email', $response->headers()['Location'] ?? '');
    }

    // --- button -----------------------------------------------------------------------------------------

    public function testButtonRendersOnCoreEmailPageAndInStewardSettings(): void
    {
        $html = $this->container['template']->render('Steward:config/test_email', ['steward_test_email_to' => 'admin@example.test']);
        $this->assertStringContainsString('form="steward-test-email-form"', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertNotEmpty($this->container['hook']->getListeners('template:config:email'));

        $settings = $this->container['template']->render('Steward:config/settings', [
            'values' => array_merge(array_map('strval', \Kanboard\Plugin\Steward\Model\SettingsSchema::defaults()), ['steward_test_email' => '1']),
            'features' => \Kanboard\Plugin\Steward\Model\SettingsSchema::FEATURES,
            'switching_forced_off' => false,
            'steward_test_email_to' => 'admin@example.test',
        ]);
        $this->assertStringContainsString('form="steward-test-email-form"', $settings);
    }

    /** The submitted form sits outside the page's own form, on the two pages that show the button, for admins. */
    public function testHiddenFormRendersAtThePageBottomOnlyWhereTheButtonIs(): void
    {
        $bottom = function (string $controller, string $action): string {
            foreach (['currentControllerName' => $controller, 'currentActionName' => $action] as $name => $value) {
                (new ReflectionProperty($this->container['router'], $name))->setValue($this->container['router'], $value);
            }
            return $this->container['helper']->hook->render('template:layout:bottom');
        };

        $email = $bottom('ConfigController', 'email');
        $this->assertStringContainsString('<form id="steward-test-email-form" method="post"', $email);
        $this->assertStringContainsString('redirect=email', $email);
        $this->assertStringContainsString('name="csrf_token"', $email);

        $settings = $bottom('StewardSettingsController', 'show');
        $this->assertStringContainsString('id="steward-test-email-form"', $settings);
        $this->assertStringNotContainsString('redirect=email', $settings);

        $this->assertStringNotContainsString('steward-test-email-form', $bottom('DashboardController', 'show'));

        $manager = (new UserModel($this->container))->create(['username' => 'mia', 'role' => Role::APP_MANAGER, 'email' => 'mia@example.test']);
        $this->loginAs($manager);
        $this->assertStringNotContainsString('steward-test-email-form', $bottom('ConfigController', 'email'));
    }

    public function testButtonIsDisabledWithExplanationWithoutProfileEmail(): void
    {
        $html = $this->container['template']->render('Steward:config/test_email', ['steward_test_email_to' => '']);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('Add an email address to your profile first', $html);
    }
}
