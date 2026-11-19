<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;

class SwitchActionTableTest extends Base
{
    /** @return array<string,true> 'Controller::action' for every public action a core controller exposes. */
    private static function coreActions(): array
    {
        $dir = dirname(__DIR__, 2).'/testing/kanboard-src/app/Controller';
        if (! is_dir($dir)) {
            $dir = dirname((new ReflectionClass(\Kanboard\Controller\BaseController::class))->getFileName());
        }
        $pairs = [];
        foreach (glob($dir.'/*.php') as $file) {
            $class = 'Kanboard\\Controller\\'.basename($file, '.php');
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $declaring = $method->getDeclaringClass()->getName();
                if ($method->isStatic() || str_starts_with($method->getName(), '__')
                    || ! str_starts_with($declaring, 'Kanboard\\Controller\\')
                    || $declaring === \Kanboard\Controller\BaseController::class) {
                    continue;
                }
                $pairs[$ref->getShortName().'::'.$method->getName()] = true;
            }
        }
        return $pairs;
    }

    public function testTableCoversEveryCoreAction(): void
    {
        $actions = self::coreActions();
        $this->assertGreaterThan(350, count($actions), 'reflection found the core controllers');
        $missing = array_keys(array_diff_key($actions, SwitchActionTable::CORE));
        $this->assertSame([], $missing, 'core actions without a row');
        $stale = array_keys(array_diff_key(SwitchActionTable::CORE, $actions));
        $this->assertSame([], $stale, 'rows for actions core does not have');
    }

    public function testEveryRowHasAKnownLevel(): void
    {
        foreach (SwitchActionTable::CORE + SwitchActionTable::STEWARD as $pair => $level) {
            $this->assertContains($level, ['read', 'write', 'blocked'], $pair);
        }
    }

    public function testStewardRowsCoverEveryStewardAction(): void
    {
        $pairs = [];
        foreach (glob(dirname(__DIR__).'/Controller/*.php') as $file) {
            $ref = new ReflectionClass('Kanboard\\Plugin\\Steward\\Controller\\'.basename($file, '.php'));
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $ref->getName() && ! str_starts_with($method->getName(), '__')) {
                    $pairs[] = $ref->getShortName().'::'.$method->getName();
                }
            }
        }
        sort($pairs);
        $rows = array_keys(SwitchActionTable::STEWARD);
        sort($rows);
        $this->assertSame($pairs, $rows);
        $this->assertSame('write', (new SwitchActionTable())->classify('Steward', 'StewardSwitchController', 'back'));
        $this->assertSame('blocked', (new SwitchActionTable())->classify('Steward', 'StewardSwitchController', 'start'));
    }

    /** Spec §2 "Always refused", Decision 8. */
    public function testAccountSecurityAndAdminControllersAreBlocked(): void
    {
        $prefixes = ['UserModificationController::', 'UserCredentialController::', 'UserApiAccessController::',
            'TwoFactorController::', 'OAuthController::', 'ConfigController::', 'PluginController::',
            'UserListController::', 'UserCreationController::', 'UserImportController::', 'UserStatusController::',
            'UserInviteController::', 'PasswordResetController::', 'GroupListController::', 'GroupCreationController::',
            'GroupModificationController::', 'CurrencyController::', 'LinkController::', 'TagController::'];
        $checked = 0;
        foreach (SwitchActionTable::CORE as $pair => $level) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($pair, $prefix)) {
                    $this->assertSame('blocked', $level, $pair);
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(60, $checked);
        foreach (['AvatarFileController::upload', 'AvatarFileController::remove', 'UserViewController::sessions',
            'UserViewController::removeSession', 'UserViewController::passwordReset', 'UserViewController::integrations',
            'UserViewController::external', 'UserViewController::share', 'UserViewController::notifications',
            'UserViewController::lastLogin'] as $pair) {
            $this->assertSame('blocked', SwitchActionTable::CORE[$pair], $pair);
        }
    }

    /** Core mutates on GET: never classified read. */
    public function testGetMutationsAreWrites(): void
    {
        foreach (['TaskStatusController::close', 'TaskStatusController::open', 'TaskModificationController::assignToMe',
            'TaskModificationController::start', 'SubtaskStatusController::change', 'SubtaskStatusController::timer',
            'BoardAjaxController::collapse', 'BoardAjaxController::expand', 'CommentController::toggleSorting',
            'WebNotificationController::redirect', 'TaskDuplicationController::duplicate', 'ProjectViewController::updateSharing',
            'ProjectCreationController::save'] as $pair) {
            $this->assertSame('write', SwitchActionTable::CORE[$pair], $pair);
        }
    }

    public function testClassifyIsCaseInsensitiveAndUnknownIsBlocked(): void
    {
        $table = new SwitchActionTable();
        $this->assertSame('write', $table->classify('', 'taskstatuscontroller', 'CLOSE'));
        $this->assertSame('read', $table->classify('', 'BoardViewController', 'show'));
        $this->assertSame('blocked', $table->classify('', 'BoardViewController', 'nope'));
        $this->assertSame('blocked', $table->classify('', 'NoSuchController', 'show'));
    }

    public function testPluginPairsComeOnlyFromThatPluginsRegistration(): void
    {
        $table = new SwitchActionTable(['Alpha' => ['ThingController::show' => 'read']]);
        $this->assertSame('read', $table->classify('Alpha', 'ThingController', 'show'));
        $this->assertSame('read', $table->classify('alpha', 'thingcontroller', 'SHOW'));
        $this->assertSame('blocked', $table->classify('Beta', 'ThingController', 'show'));
        $this->assertSame('blocked', $table->classify('', 'ThingController', 'show'));
        $this->assertSame('blocked', $table->classify('Alpha', 'ThingController', 'save'));
        // A plugin's same-named core controller is not core's.
        $this->assertSame('blocked', $table->classify('Alpha', 'BoardViewController', 'show'));
    }

    public function testStewardRowsCannotBeReplaced(): void
    {
        $table = new SwitchActionTable(['Steward' => ['StewardSettingsController::show' => 'read']]);
        $this->assertSame('blocked', $table->classify('Steward', 'StewardSettingsController', 'show'));
    }

    public function testSuiteRegistrationsOnlyForInstalledPlugins(): void
    {
        $suite = SwitchActionTable::suite();
        foreach (SwitchActionTable::SUITE as $plugin => [$class, $actions]) {
            $this->assertSame(class_exists($class), isset($suite[$plugin]), $plugin);
            foreach ($actions as $pair => $level) {
                $this->assertSame('read', $level, $plugin.' '.$pair);
                [$controller, $action] = explode('::', $pair);
                $full = 'Kanboard\\Plugin\\'.$plugin.'\\Controller\\'.$controller;
                if (class_exists($full)) {
                    $this->assertTrue(method_exists($full, $action), $plugin.' '.$pair.' exists');
                }
            }
        }
        $this->assertArrayHasKey('Pulse', $suite, 'the harness mounts the suite plugins');
    }
}
