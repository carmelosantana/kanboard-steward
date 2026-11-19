<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';
require_once __DIR__.'/support/BrandingDoubles.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Steward\Controller\StewardProjectHomeController;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\PanelData;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;

class StewardHomeBodyOnlyLayoutHelper extends \Kanboard\Helper\LayoutHelper
{
    public function app($template, array $params = array())
    {
        return $this->template->render($template, $params);
    }
}

class ProjectHomeTest extends Base
{
    private const ADMIN = 1;

    private int $viewer;
    private int $outsider;
    private int $project;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        // Plugin::initialize() wires the view-switcher hook only while the feature is on.
        $on = $this->getName(false) !== 'testHomeLinkNeedsTheFeatureAtStartup';
        $this->container['configModel']->save(['steward_project_pages' => $on ? '1' : '0', 'steward_markdown' => '1']);
        $this->container['memoryCache']->flush();
        (new Plugin($this->container))->initialize();
        $this->container['helper']->register('layout', StewardHomeBodyOnlyLayoutHelper::class);

        $users = $this->container['userModel'];
        $this->viewer = $users->create(['username' => 'viewer', 'name' => 'Viewer']);
        $this->outsider = $users->create(['username' => 'outsider', 'name' => 'Outsider']);

        $projects = $this->container['projectModel'];
        $this->project = $projects->create(['name' => 'Home <one>', 'description' => "Intro\n\n- [x] shipped"]);
        $this->other = $projects->create(['name' => 'Other']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->viewer, Role::PROJECT_VIEWER);
        $this->container['projectUserRoleModel']->addUser($this->other, $this->outsider, Role::PROJECT_MEMBER);

        $this->loginAs(self::ADMIN);
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function show(int $projectId): StewardBrandingResponse
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'],
            ['controller' => 'StewardProjectHomeController', 'action' => 'show', 'plugin' => 'Steward', 'project_id' => (string) $projectId],
            [], [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardProjectHomeController($this->container))->show();
        return $response;
    }

    private function panels(array $panels, array $extra = []): void
    {
        $settings = new ProjectPageSettings($this->container);
        $this->assertSame([], $settings->save($this->project, ['panels' => $panels] + $extra));
    }

    private function data(): PanelData
    {
        return new PanelData($this->container);
    }

    private function task(int $projectId, string $title, array $extra = []): int
    {
        $id = $this->container['taskCreationModel']->create(['project_id' => $projectId, 'title' => $title] + $extra);
        $this->assertGreaterThan(0, $id);
        return $id;
    }

    private function closeAt(int $taskId, int $timestamp): void
    {
        $this->container['db']->table(TaskModel::TABLE)->eq('id', $taskId)
            ->update(['is_active' => TaskModel::STATUS_CLOSED, 'date_completed' => $timestamp]);
    }

    private function column(int $projectId, int $position): int
    {
        $columns = $this->container['columnModel']->getAll($projectId);
        return (int) $columns[$position]['id'];
    }

    /** @return list<string> the data-panel names in the order they appear */
    private function panelOrder(string $html): array
    {
        preg_match_all('/data-steward-panel="([a-z]+)"/', $html, $m);
        return $m[1];
    }

    // --- access -----------------------------------------------------------------------------------------

    public function testNonMemberIsRefused(): void
    {
        $this->loginAs($this->outsider);
        $this->assertFalse($this->container['helper']->user->hasProjectAccess('StewardProjectHomeController', 'show', $this->project));
        $this->expectException(AccessForbiddenException::class);
        $this->show($this->project);
    }

    public function testProjectViewerAndAdminSeeHome(): void
    {
        foreach ([$this->viewer, self::ADMIN] as $userId) {
            $this->loginAs($userId);
            $this->assertTrue($this->container['helper']->user->hasProjectAccess('StewardProjectHomeController', 'show', $this->project));
            $response = $this->show($this->project);
            $this->assertSame(200, $response->code());
            $this->assertStringContainsString('Home &lt;one&gt;', $response->body());
        }
    }

    public function testFeatureOffAnswersTheGates404(): void
    {
        $this->container['configModel']->save(['steward_project_pages' => '0']);
        $this->container['memoryCache']->flush();
        $response = $this->show($this->project);
        $this->assertSame(404, $response->code());
        $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body());
    }

    public function testUnknownProjectIs404(): void
    {
        $this->expectException(\Kanboard\Core\Controller\PageNotFoundException::class);
        $this->show(9999);
    }

    public function testRulesAreRegistered(): void
    {
        $this->assertContains(['StewardProjectHomeController', ['show'], Role::PROJECT_VIEWER], AclCheck::EXPECTED['projectAccessMap']);
        $this->assertContains(['StewardProjectHomeController', ['show'], Role::APP_USER], AclCheck::EXPECTED['applicationAccessMap']);
        $this->assertSame('project_pages', FeatureGate::MAP['StewardProjectHomeController::*']);
        $this->assertSame('read', SwitchActionTable::STEWARD['StewardProjectHomeController::show']);
    }

    // --- panels -----------------------------------------------------------------------------------------

    public function testDefaultPanelsWhenNothingIsStored(): void
    {
        $this->assertSame(ProjectPageSettings::DEFAULT_PANELS, $this->panelOrder($this->show($this->project)->body()));
    }

    public function testPanelsRenderInConfiguredOrder(): void
    {
        $this->panels(['files', 'completed', 'header', 'board', 'progress']);
        $this->assertSame(['files', 'completed', 'header', 'board', 'progress'], $this->panelOrder($this->show($this->project)->body()));
    }

    public function testEveryPanelRendersWithEmptyStatesForAnEmptyProject(): void
    {
        $empty = $this->container['projectModel']->create(['name' => 'Empty']);
        $settings = new ProjectPageSettings($this->container);
        $this->assertSame([], $settings->save($empty, ['panels' => ProjectPageSettings::HOME_PANELS]));
        $this->container['db']->table('project_activities')->eq('project_id', $empty)->remove();

        $html = $this->show($empty)->body();
        $this->assertSame(ProjectPageSettings::HOME_PANELS, $this->panelOrder($html));
        // description, board, activity, completed, files
        $this->assertSame(5, substr_count($html, 'Nothing yet'));
        $this->assertStringNotContainsString('alert-error', $html);
    }

    public function testHeaderEscapesTaglineAndOpensLinksSafely(): void
    {
        $this->panels(['header'], [
            'tagline' => 'Fast <b>& loud</b>',
            'links' => [['label' => 'Docs "<i>"', 'url' => 'https://docs.example.com/?a=1&b=2']],
        ]);
        $html = $this->show($this->project)->body();

        $this->assertStringContainsString('Fast &lt;b&gt;&amp; loud&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('<a href="https://docs.example.com/?a=1&amp;b=2" rel="noopener noreferrer" target="_blank">Docs &quot;&lt;i&gt;&quot;</a>', $html);
    }

    public function testDescriptionGoesThroughStewardsRenderer(): void
    {
        $this->panels(['description']);
        $html = $this->show($this->project)->body();
        $this->assertStringContainsString('<li class="task-list-item"><input type="checkbox" disabled checked> shipped</li>', $html);
    }

    public function testDescriptionRawHtmlIsEscaped(): void
    {
        $this->container['projectModel']->update(['id' => $this->project, 'description' => '<script>alert(1)</script>']);
        $this->panels(['description']);
        $html = $this->show($this->project)->body();
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    // --- progress ---------------------------------------------------------------------------------------

    public function testProgressCountsOpenPerColumnAndClosedOverall(): void
    {
        $this->task($this->project, 'a', ['column_id' => $this->column($this->project, 0)]);
        $this->task($this->project, 'b', ['column_id' => $this->column($this->project, 0)]);
        $this->task($this->project, 'c', ['column_id' => $this->column($this->project, 2)]);
        $this->closeAt($this->task($this->project, 'd'), time());
        $this->task($this->other, 'elsewhere');
        $this->closeAt($this->task($this->other, 'elsewhere closed'), time());

        $progress = $this->data()->progress($this->project);
        $this->assertSame([2, 0, 1, 0], array_column($progress['columns'], 'open'));
        $this->assertSame(['Backlog', 'Ready', 'Work in progress', 'Done'], array_column($progress['columns'], 'title'));
        $this->assertSame(3, $progress['open']);
        $this->assertSame(1, $progress['closed']);
        $this->assertSame(25, $progress['percent']);

        $this->assertSame(0, $this->data()->progress($this->container['projectModel']->create(['name' => 'None']))['percent']);
    }

    // --- board ------------------------------------------------------------------------------------------

    public function testBoardListsOpenTasksOfThisProjectOnlyWithSwimlanesFlattened(): void
    {
        $lane = $this->container['swimlaneModel']->create($this->project, 'Second lane');
        $first = $this->column($this->project, 0);
        $a = $this->task($this->project, 'Lane two task', ['column_id' => $first, 'swimlane_id' => $lane]);
        $b = $this->task($this->project, 'Lane one task', ['column_id' => $first]);
        $this->closeAt($this->task($this->project, 'Closed task', ['column_id' => $first]), time());
        $this->task($this->other, 'Other project task');

        $board = $this->data()->board($this->project, false);
        $this->assertSame(['Backlog', 'Ready', 'Work in progress', 'Done'], array_column($board, 'title'));
        $this->assertSame([['id' => $b, 'title' => 'Lane one task'], ['id' => $a, 'title' => 'Lane two task']], $board[0]['tasks']);
        foreach (array_slice($board, 1) as $column) {
            $this->assertSame([], $column['tasks']);
        }
    }

    public function testPublicBoardCarriesNoTaskIds(): void
    {
        $this->task($this->project, 'Visible');
        $board = $this->data()->board($this->project, true);
        $this->assertSame([['title' => 'Visible']], $board[0]['tasks']);
    }

    public function testBoardPanelEscapesTitlesAndLinksToCoreTaskView(): void
    {
        $id = $this->task($this->project, '<img src=x onerror=alert(1)>');
        $this->task($this->other, 'Other project task');
        $this->panels(['board']);
        $html = $this->show($this->project)->body();

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('controller=TaskViewController&amp;action=show&amp;task_id='.$id, $html);
        $this->assertStringNotContainsString('Other project task', $html);
    }

    // --- completed --------------------------------------------------------------------------------------

    public function testCompletedListsTasksClosedInTheWindowNewestFirst(): void
    {
        $old = $this->task($this->project, 'Long ago');
        $recent = $this->task($this->project, 'Recent');
        $newest = $this->task($this->project, 'Newest');
        $this->task($this->project, 'Still open');
        $foreign = $this->task($this->other, 'Other project closed');
        $this->closeAt($old, time() - 20 * 86400);
        $this->closeAt($recent, time() - 3 * 86400);
        $this->closeAt($newest, time() - 60);
        $this->closeAt($foreign, time() - 60);

        $this->assertSame([$newest, $recent], array_column($this->data()->completed($this->project), 'id'));
        $this->assertSame([$newest], array_column($this->data()->completed($this->project, 1), 'id'));

        $this->panels(['completed']);
        $html = $this->show($this->project)->body();
        $this->assertStringContainsString('Recent', $html);
        $this->assertStringNotContainsString('Long ago', $html);
        $this->assertStringNotContainsString('Other project closed', $html);
    }

    // --- files ------------------------------------------------------------------------------------------

    public function testFilesListsThisProjectsAttachmentsWithCoreLinks(): void
    {
        $files = $this->container['projectFileModel'];
        $mine = $files->create($this->project, 'spec <v2>.pdf', 'projects/1/spec', 2048);
        $files->create($this->other, 'secret.txt', 'projects/2/secret', 10);

        $this->assertSame(['spec <v2>.pdf'], array_column($this->data()->files($this->project), 'name'));

        $this->panels(['files']);
        $html = $this->show($this->project)->body();
        $this->assertStringContainsString('spec &lt;v2&gt;.pdf', $html);
        $this->assertStringContainsString('controller=FileViewerController&amp;action=download&amp;project_id='.$this->project.'&amp;file_id='.$mine, $html);
        $this->assertStringNotContainsString('secret.txt', $html);
    }

    // --- activity ---------------------------------------------------------------------------------------

    public function testActivityIsThisProjectsCoreStreamCapped(): void
    {
        $this->container['db']->table('project_activities')->remove();
        $task = $this->task($this->project, 'Activity task');
        $foreign = $this->task($this->other, 'Foreign task');
        $this->container['db']->table('project_activities')->remove();
        $insert = function (int $projectId, int $taskId): void {
            $this->container['db']->table('project_activities')->insert([
                'project_id' => $projectId, 'task_id' => $taskId, 'creator_id' => self::ADMIN,
                'event_name' => TaskModel::EVENT_CREATE, 'date_creation' => time(),
                'data' => json_encode(['task' => $this->container['taskFinderModel']->getDetails($taskId)]),
            ]);
        };
        for ($i = 0; $i < 25; $i++) {
            $insert($this->project, $task);
        }
        $insert($this->other, $foreign);

        $events = $this->data()->activity($this->project);
        $this->assertCount(20, $events);
        $this->assertSame([$this->project], array_values(array_unique(array_map('intval', array_column($events, 'project_id')))));
        $this->assertCount(5, $this->data()->activity($this->project, 5));

        $this->panels(['activity']);
        $html = $this->show($this->project)->body();
        $this->assertStringContainsString('activity-event', $html);
        $this->assertStringContainsString('Activity task', $html);
        $this->assertStringNotContainsString('Foreign task', $html);
    }

    // --- view switcher ----------------------------------------------------------------------------------

    private function switcher(): string
    {
        $project = $this->container['projectModel']->getById($this->project);
        return $this->container['template']->render('project_header/views', [
            'project' => $project,
            'filters' => ['search' => '', 'controller' => 'BoardViewController', 'action' => 'show', 'project_id' => $this->project, 'plugin' => ''],
        ]);
    }

    public function testHomeLinkIsFirstInTheViewSwitcher(): void
    {
        $html = $this->switcher();
        $home = strpos($html, 'controller=StewardProjectHomeController&amp;action=show&amp;plugin=Steward&amp;project_id='.$this->project);
        $this->assertNotFalse($home);
        $this->assertLessThan(strpos($html, 'ProjectOverviewController'), $home);
    }

    public function testHomeLinkNeedsTheFeatureAtStartup(): void
    {
        $this->assertStringNotContainsString('StewardProjectHomeController', $this->switcher());
    }
}
