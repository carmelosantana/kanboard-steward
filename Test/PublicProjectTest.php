<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';
require_once __DIR__.'/support/BrandingDoubles.php';

use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Steward\Controller\StewardPublicController;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;
use Kanboard\Plugin\Steward\Model\PublicActivity;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;

/**
 * Spec §5.3 public page, rendered through core's real layout as an anonymous visitor. Every private value is
 * seeded with a sentinel string that must not reach the HTML.
 */
class PublicProjectTest extends Base
{
    private const ADMIN = 1;

    private const ACTOR_USERNAME = 'zzactorloginzz';
    private const ACTOR_EMAIL = 'zzactor@mail.example';
    private const ACTOR_NAME = 'Zz Actor Display';
    private const ASSIGNEE_USERNAME = 'zzassigneeloginzz';
    private const ASSIGNEE_EMAIL = 'zzassignee@mail.example';
    private const ASSIGNEE_NAME = 'Zz Assignee Display';
    private const COMMENT = 'ZZCOMMENTSENTINELZZ';
    private const SUBTASK = 'ZZSUBTASKSENTINELZZ';
    private const TASK_DESCRIPTION = 'ZZTASKDESCSENTINELZZ';
    private const PROJECT_FILE = 'zzprojectfilesentinel.pdf';
    private const TASK_FILE = 'zztaskfilesentinel.png';
    private const CATEGORY = 'ZZCATEGORYSENTINELZZ';

    private int $project;
    private int $other;
    private int $actor;
    private int $assignee;
    private int $task;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\Steward\Schema\version_2($this->container['db']->getConnection());
        $on = $this->getName(false) === 'testRouteIsRegisteredWithTheFeatureOff' ? '0' : '1';
        $this->container['configModel']->save(['steward_public_pages' => $on, 'steward_markdown' => '1']);
        $this->container['memoryCache']->flush();
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();

        $users = $this->container['userModel'];
        $this->actor = $users->create(['username' => self::ACTOR_USERNAME, 'email' => self::ACTOR_EMAIL, 'name' => self::ACTOR_NAME]);
        $this->assignee = $users->create(['username' => self::ASSIGNEE_USERNAME, 'email' => self::ASSIGNEE_EMAIL, 'name' => self::ASSIGNEE_NAME]);

        $projects = $this->container['projectModel'];
        $this->project = $projects->create(['name' => 'Open <Project>', 'identifier' => 'OPEN']);
        $this->other = $projects->create(['name' => 'Elsewhere']);
        $this->assertTrue($projects->enablePublicAccess($this->project));
        $this->assertTrue($projects->enablePublicAccess($this->other));
        foreach ([$this->actor, $this->assignee] as $userId) {
            $this->container['projectUserRoleModel']->addUser($this->project, $userId, Role::PROJECT_MEMBER);
        }

        $category = $this->container['categoryModel']->create(['project_id' => $this->project, 'name' => self::CATEGORY]);
        $this->task = $this->task('Login <fix>', [
            'owner_id' => $this->assignee, 'creator_id' => $this->actor, 'description' => self::TASK_DESCRIPTION,
            'date_due' => strtotime('2031-07-19 10:00'), 'category_id' => $category,
        ]);
        $this->container['commentModel']->create(['task_id' => $this->task, 'user_id' => $this->actor, 'comment' => self::COMMENT]);
        $this->container['subtaskModel']->create(['task_id' => $this->task, 'title' => self::SUBTASK, 'user_id' => $this->assignee]);
        $this->container['projectFileModel']->create($this->project, self::PROJECT_FILE, 'p/1', 10);
        $this->container['taskFileModel']->create($this->task, self::TASK_FILE, 't/1', 10);
        $closed = $this->task('Shipped thing', ['owner_id' => $this->assignee]);
        $this->container['db']->table(TaskModel::TABLE)->eq('id', $closed)
            ->update(['is_active' => TaskModel::STATUS_CLOSED, 'date_completed' => time() - 3600]);
        $this->event($this->task, TaskModel::EVENT_CREATE);

        $projects->update(['id' => $this->project, 'description' => "Intro for #{$this->task} by @admin\n\n```php\necho 1;\n```"]);
        $this->publish();
        session_remove('user');
        $this->container['memoryCache']->flush();
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function task(string $title, array $extra = [], ?int $projectId = null): int
    {
        $id = $this->container['taskCreationModel']->create(['project_id' => $projectId ?? $this->project, 'title' => $title] + $extra);
        $this->assertGreaterThan(0, $id);
        return $id;
    }

    /** A project_activities row as core's subscriber writes it: the full task details, usernames and all. */
    private function event(int $taskId, string $name, ?int $creatorId = null, array $taskOverride = [], ?int $projectId = null): void
    {
        $task = $taskOverride + $this->container['taskFinderModel']->getDetails($taskId);
        $this->assertTrue((bool) $this->container['db']->table('project_activities')->insert([
            'project_id' => $projectId ?? (int) $task['project_id'], 'task_id' => $taskId, 'creator_id' => $creatorId ?? $this->actor,
            'event_name' => $name, 'date_creation' => time(),
            'data' => json_encode(['task' => $task, 'changes' => ['description' => self::TASK_DESCRIPTION]]),
        ]));
    }

    private function publish(array $override = []): void
    {
        $settings = new ProjectPageSettings($this->container);
        $this->assertSame([], $settings->save($this->project, $override + [
            'tagline' => 'Tag <line>',
            'links' => [['label' => 'Site', 'url' => 'https://example.com/?a=1&b=2']],
            'public_panels' => ProjectPageSettings::PUBLIC_PANELS,
            'public' => true,
            'show_names' => false,
        ]));
    }

    private function token(?int $projectId = null): string
    {
        return (string) $this->container['projectModel']->getById($projectId ?? $this->project)['token'];
    }

    private function show(string $token): StewardBrandingResponse
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'],
            ['controller' => 'StewardPublicController', 'action' => 'show', 'plugin' => 'Steward', 'token' => $token],
            [], [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        $this->container['memoryCache']->flush();
        (new StewardPublicController($this->container))->show();
        return $response;
    }

    private function assertNotFound(StewardBrandingResponse $response, string $why): void
    {
        $this->assertSame(404, $response->code(), $why);
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body(), $why);
        $this->assertSame('no-referrer', $response->headers()['Referrer-Policy'] ?? null, $why);
    }

    /** @return string the HTML of one panel section */
    private function panel(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('#<section [^>]*data-steward-panel="'.$name.'"[^>]*>(.*?)</section>#s', $html, $m), $name.' panel missing');
        return $m[1];
    }

    // --- registration -----------------------------------------------------------------------------------

    public function testRegisteredAsPublicReadPageBehindItsFeature(): void
    {
        $this->assertContains(['StewardPublicController', ['show'], Role::APP_PUBLIC], AclCheck::EXPECTED['applicationAccessMap']);
        $this->assertTrue($this->container['applicationAuthorization']->isAllowed('StewardPublicController', 'show', Role::APP_PUBLIC));
        $this->assertSame('public_pages', (new FeatureGate($this->container))->featureFor('StewardPublicController', 'show'));
        $this->assertSame('read', SwitchActionTable::STEWARD['StewardPublicController::show']);
        $this->assertRoute();
    }

    /**
     * The route exists with the feature off too, so FeatureGate answers its 404 there: without it core sends an
     * unknown path to the login page, which would tell "feature off" apart from "unknown token".
     */
    public function testRouteIsRegisteredWithTheFeatureOff(): void
    {
        $this->assertFalse($this->container['stewardFeatures']->isEnabled('public_pages'));
        $this->assertRoute();
    }

    private function assertRoute(): void
    {
        $this->assertSame(
            ['controller' => 'StewardPublicController', 'action' => 'show', 'plugin' => 'Steward'],
            $this->container['route']->findRoute('public/project/abc123')
        );
        $this->assertSame('abc123', $this->container['request']->getStringParam('token'));
    }

    // --- availability -----------------------------------------------------------------------------------

    public function testShowsThePageWhenEverythingIsOn(): void
    {
        $response = $this->show($this->token());
        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('Open &lt;Project&gt;', $response->body());
        $this->assertStringContainsString('<!DOCTYPE html>', $response->body());
    }

    public function testEveryMissingToggleAnswersTheSame404(): void
    {
        $token = $this->token();
        $this->assertNotFound($this->show('not-a-token'), 'unknown token');
        $this->assertNotFound($this->show(''), 'empty token');

        $this->publish(['public' => false]);
        $this->assertNotFound($this->show($token), 'Steward public page off');
        $this->publish();

        $this->container['configModel']->save(['steward_public_pages' => '0']);
        $this->assertNotFound($this->show($token), 'feature off');
        $this->container['configModel']->save(['steward_public_pages' => '1']);

        // Core public access off, token left in place by a database edit: still 404.
        $this->container['db']->table('projects')->eq('id', $this->project)->update(['is_public' => 0]);
        $this->assertNotFound($this->show($token), 'core public access off');
        $this->container['db']->table('projects')->eq('id', $this->project)->update(['is_public' => 1]);

        $this->assertSame(200, $this->show($token)->code());
    }

    public function testAnotherProjectsTokenNeverShowsThisProject(): void
    {
        // The other project has core public access but no Steward public page.
        $this->assertNotFound($this->show($this->token($this->other)), 'other project');
    }

    public function testDisablingAndReEnablingPublicAccessKillsTheOldUrl(): void
    {
        $old = $this->token();
        $projects = $this->container['projectModel'];
        $this->assertTrue($projects->disablePublicAccess($this->project));
        $this->assertNotFound($this->show($old), 'public access off');
        $this->assertTrue($projects->enablePublicAccess($this->project));
        $new = $this->token();
        $this->assertNotSame($old, $new);
        $this->assertNotFound($this->show($old), 'old token after re-enable');
        $this->assertSame(200, $this->show($new)->code());
    }

    // --- headers ----------------------------------------------------------------------------------------

    public function testHeadersNoReferrerAndNoindexUnlessListed(): void
    {
        $headers = $this->show($this->token())->headers();
        $this->assertSame('no-referrer', $headers['Referrer-Policy'] ?? null);
        $this->assertSame('noindex', $headers['X-Robots-Tag'] ?? null);

        $this->publish(['listed' => true]);
        $headers = $this->show($this->token())->headers();
        $this->assertSame('no-referrer', $headers['Referrer-Policy'] ?? null);
        $this->assertArrayNotHasKey('X-Robots-Tag', $headers);
    }

    // --- nothing private leaks --------------------------------------------------------------------------

    public function testNoTokenAndNoLinksIntoCorePublicPages(): void
    {
        $token = $this->token();
        $html = $this->show($token)->body();
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringNotContainsString('token=', $html);
        $this->assertStringNotContainsString('csrf', strtolower($html));
        foreach (['/public/', 'BoardViewController', 'TaskViewController', 'FeedController', 'ICalendarController', '/feed/', '/ical/'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function testNoCommentsFilesEmailsUsernamesOrHiddenFieldsEvenWithNames(): void
    {
        $this->event($this->task, TaskModel::EVENT_MOVE_COLUMN);
        $this->event($this->task, TaskModel::EVENT_CLOSE);
        $this->event($this->task, 'comment.create');
        $this->event($this->task, 'task.file.create');
        foreach ([false, true] as $names) {
            $this->publish(['show_names' => $names]);
            $html = $this->show($this->token())->body();
            foreach ([self::ACTOR_USERNAME, self::ACTOR_EMAIL, self::ASSIGNEE_USERNAME, self::ASSIGNEE_EMAIL, self::ASSIGNEE_NAME,
                self::COMMENT, self::SUBTASK, self::TASK_DESCRIPTION, self::PROJECT_FILE, self::TASK_FILE, self::CATEGORY,
                '2031', 'admin@'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $html, $sentinel.' leaked (show_names '.var_export($names, true).')');
            }
            $this->assertStringNotContainsString('task_id', $html);
        }
    }

    public function testActorNamesOnlyWithTheToggle(): void
    {
        $this->assertStringNotContainsString(self::ACTOR_NAME, $this->show($this->token())->body());
        $this->publish(['show_names' => true]);
        $this->assertStringContainsString(self::ACTOR_NAME, $this->show($this->token())->body());
    }

    public function testDescriptionKeepsTaskRefsAndMentionsPlain(): void
    {
        $description = $this->panel($this->show($this->token())->body(), 'description');
        $this->assertStringContainsString('#'.$this->task.' by @admin', $description);
        $this->assertStringNotContainsString('<a ', $description);
        $this->assertStringContainsString('language-php', $description);
    }

    public function testBoardAndCompletedShowTitlesWithoutLinksOrIds(): void
    {
        $html = $this->show($this->token())->body();
        $board = $this->panel($html, 'board');
        $this->assertStringContainsString('Login &lt;fix&gt;', $board);
        $completed = $this->panel($html, 'completed');
        $this->assertStringContainsString('Shipped thing', $completed);
        foreach ([$board, $completed, $this->panel($html, 'progress'), $this->panel($html, 'activity')] as $section) {
            $this->assertStringNotContainsString('<a ', $section);
            $this->assertStringNotContainsString('#'.$this->task, $section);
        }
    }

    public function testHeaderEscapesAndLinksOutWithoutReferrerOrRank(): void
    {
        $header = $this->panel($this->show($this->token())->body(), 'header');
        $this->assertStringContainsString('Tag &lt;line&gt;', $header);
        $this->assertStringContainsString('<a href="https://example.com/?a=1&amp;b=2" rel="noopener noreferrer nofollow"', $header);
    }

    public function testPanelsFollowPublicPanelsInOrderAndNeverFiles(): void
    {
        $this->publish(['public_panels' => ['activity', 'header']]);
        $html = $this->show($this->token())->body();
        preg_match_all('/data-steward-panel="([a-z]+)"/', $html, $m);
        $this->assertSame(['activity', 'header'], $m[1]);

        // A stored 'files' (database edit) is dropped on read.
        $this->container['db']->table(ProjectPageSettings::TABLE)->eq('project_id', $this->project)
            ->update(['public_panels' => json_encode(['files', 'progress'])]);
        $html = $this->show($this->token())->body();
        preg_match_all('/data-steward-panel="([a-z]+)"/', $html, $m);
        $this->assertSame(['progress'], $m[1]);
    }

    public function testMarkdownAssetsLoadOnThePublicPage(): void
    {
        $html = $this->show($this->token())->body();
        $this->assertStringContainsString('plugins/Steward/Assets/vendor/highlight.min.js', $html);
        $this->assertStringContainsString('plugins/Steward/Assets/js/steward-markdown.js', $html);
        $this->assertStringContainsString('plugins/Steward/Assets/css/public.css', $html);
        // Public chrome: no core app scripts (jQuery, KB).
        $this->assertStringNotContainsString('assets/js/app.min.js', $html);
    }

    public function testDescriptionRendersWithStewardsParserEvenWithMarkdownOff(): void
    {
        $this->container['configModel']->save(['steward_markdown' => '0']);
        $description = $this->panel($this->show($this->token())->body(), 'description');
        $this->assertStringContainsString('#'.$this->task.' by @admin', $description);
        $this->assertStringNotContainsString('<a ', $description);
    }

    // --- PublicActivity ---------------------------------------------------------------------------------

    private function activity(bool $names = false, int $limit = 20): array
    {
        return (new PublicActivity($this->container))->recent($this->project, $names, $limit);
    }

    public function testActivityTextForTheFourSafeEventsOnly(): void
    {
        $this->container['db']->table('project_activities')->remove();
        $done = $this->container['columnModel']->getAll($this->project)[3];
        $this->event($this->task, TaskModel::EVENT_CREATE);
        $this->event($this->task, TaskModel::EVENT_MOVE_COLUMN, null, ['column_title' => 'Done <now>', 'column_id' => $done['id']]);
        $this->event($this->task, TaskModel::EVENT_CLOSE);
        $this->event($this->task, TaskModel::EVENT_OPEN);
        foreach (['comment.create', 'task.update', 'task.assignee_change', 'subtask.create', 'task.file.create', 'task.move.position'] as $other) {
            $this->event($this->task, $other);
        }

        $rows = $this->activity();
        $this->assertSame([
            '<em>Login &lt;fix&gt;</em> reopened',
            '<em>Login &lt;fix&gt;</em> closed',
            '<em>Login &lt;fix&gt;</em> moved to <em>Done &lt;now&gt;</em>',
            '<em>Login &lt;fix&gt;</em> created',
        ], array_column($rows, 'text'));
        foreach ($rows as $row) {
            $this->assertSame(['text', 'at'], array_keys($row));
            $this->assertIsInt($row['at']);
        }
    }

    public function testActivityNamesUseTheDisplayNameOnlyAndOnlyWhenAsked(): void
    {
        $this->container['db']->table('project_activities')->remove();
        $nameless = $this->container['userModel']->create(['username' => 'zznonamezz', 'email' => 'zz@noname.example']);
        $this->event($this->task, TaskModel::EVENT_CREATE);
        $this->event($this->task, TaskModel::EVENT_CLOSE, $nameless);

        $this->assertSame(['<em>Login &lt;fix&gt;</em> closed', '<em>Login &lt;fix&gt;</em> created'], array_column($this->activity(), 'text'));
        $this->assertSame([
            '<em>Login &lt;fix&gt;</em> closed',
            'Zz Actor Display: <em>Login &lt;fix&gt;</em> created',
        ], array_column($this->activity(true), 'text'));
    }

    public function testActivityIsThisProjectsOnlyNewestFirstAndCapped(): void
    {
        $this->container['db']->table('project_activities')->remove();
        $foreign = $this->task('Foreign task', [], $this->other);
        $this->event($foreign, TaskModel::EVENT_CREATE);
        for ($i = 0; $i < 25; $i++) {
            $this->event($this->task, TaskModel::EVENT_CREATE);
        }
        $this->assertCount(20, $this->activity());
        $this->assertCount(3, $this->activity(false, 3));
        $this->assertStringNotContainsString('Foreign', implode(' ', array_column($this->activity(), 'text')));
    }

    public function testActivitySkipsRowsWithoutATitle(): void
    {
        $this->container['db']->table('project_activities')->remove();
        $this->container['db']->table('project_activities')->insert([
            'project_id' => $this->project, 'task_id' => $this->task, 'creator_id' => $this->actor,
            'event_name' => TaskModel::EVENT_CREATE, 'date_creation' => time(), 'data' => 'not json',
        ]);
        $this->assertSame([], $this->activity());
    }
}
