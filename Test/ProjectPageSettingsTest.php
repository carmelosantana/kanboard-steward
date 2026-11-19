<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';
require_once __DIR__.'/support/BrandingDoubles.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Steward\Controller\StewardProjectSettingsController;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;

class StewardProjectBodyOnlyLayoutHelper extends \Kanboard\Helper\LayoutHelper
{
    public function project($template, array $params, $sidebar = 'project/sidebar')
    {
        return $this->template->render($template, $params);
    }
}

class ProjectPageSettingsTest extends Base
{
    private const ADMIN = 1;

    private int $manager;
    private int $member;
    private int $viewer;
    /** Has an identifier and core public access. */
    private int $project;
    /** No identifier, core public access off. */
    private int $plain;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\Steward\Schema\version_2($this->container['db']->getConnection());
        // Plugin::initialize() wires the sidebar hook only while the feature is on.
        $on = $this->getName(false) !== 'testSidebarLinkNeedsTheFeatureAtStartup';
        $this->container['configModel']->save(['steward_project_pages' => $on ? '1' : '0']);
        $this->container['memoryCache']->flush();
        (new Plugin($this->container))->initialize();
        $this->container['helper']->register('layout', StewardProjectBodyOnlyLayoutHelper::class);

        $users = $this->container['userModel'];
        $this->manager = $users->create(['username' => 'manager', 'name' => 'Manager']);
        $this->member = $users->create(['username' => 'member', 'name' => 'Member']);
        $this->viewer = $users->create(['username' => 'viewer', 'name' => 'Viewer']);

        $projects = $this->container['projectModel'];
        $this->project = $projects->create(['name' => 'Public one', 'identifier' => 'PUB']);
        $this->plain = $projects->create(['name' => 'Plain']);
        $this->assertTrue($projects->enablePublicAccess($this->project));

        $roles = $this->container['projectUserRoleModel'];
        foreach ([$this->project, $this->plain] as $id) {
            $roles->addUser($id, $this->manager, Role::PROJECT_MANAGER);
            $roles->addUser($id, $this->member, Role::PROJECT_MEMBER);
            $roles->addUser($id, $this->viewer, Role::PROJECT_VIEWER);
        }

        $this->loginAs(self::ADMIN);
    }

    protected function tearDown(): void
    {
        session_set('flash', []);
        parent::tearDown();
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function settings(): ProjectPageSettings
    {
        return new ProjectPageSettings($this->container);
    }

    private function valid(array $override = []): array
    {
        return $override + [
            'tagline' => 'Ship it',
            'links' => [['label' => 'Site', 'url' => 'https://example.com']],
            'panels' => ['header', 'progress'],
            'public_panels' => ['header'],
            'public' => false,
            'listed' => false,
            'show_names' => false,
        ];
    }

    /** The stored row, [] when there is none. */
    private function metadata(int $projectId): array
    {
        return $this->container['db']->table(ProjectPageSettings::TABLE)->eq('project_id', $projectId)->findOne() ?: [];
    }

    /** Writes a row straight into the table, as a database edit or another bug would. */
    private function storeRaw(int $projectId, array $columns): void
    {
        $this->container['db']->table(ProjectPageSettings::TABLE)->eq('project_id', $projectId)->remove();
        $this->assertTrue($this->container['db']->table(ProjectPageSettings::TABLE)->insert(['project_id' => $projectId] + $columns));
    }

    private function auditRows(): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->eq('event', 'public.changed')->asc('id')->findAll();
    }

    private function act(string $action, int $projectId, array $post = [], bool $csrf = true, string $method = 'POST'): StewardBrandingResponse
    {
        if ($csrf && $method === 'POST') {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method],
            ['controller' => 'StewardProjectSettingsController', 'action' => $action, 'plugin' => 'Steward', 'project_id' => (string) $projectId],
            $post, [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardProjectSettingsController($this->container))->$action();
        return $response;
    }

    private function form(array $override = []): array
    {
        return $override + [
            'tagline' => '  Hello  ',
            'link_label' => ['Docs', '', 'Repo'],
            'link_url' => ['https://docs.example.com', '', 'http://git.example.com/repo'],
            'panels' => ['progress' => '1', 'header' => '1', 'files' => '1'],
            'order' => ['header' => '2', 'progress' => '1', 'files' => '3', 'board' => '4'],
            'public_panels' => ['header' => '1', 'progress' => '1'],
            'public' => '1',
            'show_names' => '1',
        ];
    }

    private function flash(): array
    {
        return session_get('flash') ?: [];
    }

    // --- model: reading ---------------------------------------------------------------------------------

    public function testDefaultsForAProjectWithNothingStored(): void
    {
        $this->assertSame([
            'tagline' => '',
            'links' => [],
            'panels' => ['header', 'description', 'progress', 'activity'],
            'public_panels' => ['header', 'description', 'progress'],
            'public' => false,
            'listed' => false,
            'show_names' => false,
        ], $this->settings()->get($this->plain));
    }

    public function testSaveThenGetRoundTrips(): void
    {
        $errors = $this->settings()->save($this->project, [
            'tagline' => "  Ship it \t",
            'links' => [['label' => ' Site ', 'url' => ' HTTPS://example.com/a?b=1 '], ['label' => 'Repo', 'url' => 'http://git.example.com']],
            'panels' => ['progress', 'header', 'files'],
            'public_panels' => ['progress', 'header'],
            'public' => true,
            'listed' => true,
            'show_names' => true,
        ]);
        $this->assertSame([], $errors);

        $this->assertSame([
            'tagline' => 'Ship it',
            'links' => [['label' => 'Site', 'url' => 'HTTPS://example.com/a?b=1'], ['label' => 'Repo', 'url' => 'http://git.example.com']],
            'panels' => ['progress', 'header', 'files'],
            'public_panels' => ['progress', 'header'],
            'public' => true,
            'listed' => true,
            'show_names' => true,
        ], $this->settings()->get($this->project));

        $stored = $this->metadata($this->project);
        $this->assertSame(1, (int) $stored['is_public']);
        $this->assertSame('["progress","header","files"]', $stored['panels']);
        $this->assertSame([], $this->container['projectMetadataModel']->getAll($this->project));
    }

    public function testFalseIsStoredAndReadBackAsFalse(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['public' => true, 'show_names' => true])));
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['public' => false, 'show_names' => false])));
        $this->assertFalse($this->settings()->get($this->project)['public']);
        $this->assertFalse($this->settings()->get($this->project)['show_names']);
    }

    public function testTaglineIsStoredRaw(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['tagline' => '<b>Tom & Jerry</b>'])));
        $this->assertSame('<b>Tom & Jerry</b>', $this->settings()->get($this->project)['tagline']);
    }

    public function testUnknownAndDuplicatePanelNamesAreDroppedOnRead(): void
    {
        $this->storeRaw($this->project, [
            'panels' => '["header","comments","header","files"]',
            'public_panels' => '["files","board","nope"]',
        ]);
        $settings = $this->settings()->get($this->project);
        $this->assertSame(['header', 'files'], $settings['panels']);
        $this->assertSame(['board'], $settings['public_panels']);
    }

    public function testCorruptStoredValuesFallBackToDefaults(): void
    {
        $this->storeRaw($this->project, [
            'links' => '{not json',
            'panels' => '"header"',
            'public_panels' => '[1,2]',
        ]);
        $settings = $this->settings()->get($this->project);
        $this->assertSame([], $settings['links']);
        $this->assertSame(['header', 'description', 'progress', 'activity'], $settings['panels']);
        $this->assertSame([], $settings['public_panels']);
    }

    public function testBadStoredValuesAreNeutralisedOnRead(): void
    {
        // $plain has no identifier and core public access off.
        $this->storeRaw($this->plain, [
            'tagline' => str_repeat('a', 170)."\n<script>",
            'links' => json_encode([
                ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
                ['label' => 'Data', 'url' => 'data:text/html,x'],
                ['label' => 'No host', 'url' => 'https://'],
                ['label' => "Two\nlines", 'url' => 'https://example.com/a'],
                ['label' => '', 'url' => 'https://example.com/b'],
                ['label' => 'Good', 'url' => 'https://example.com/ok'],
            ]),
            'panels' => '["files","comments","header"]',
            'public_panels' => '["files","header"]',
            'is_public' => 1,
            'is_listed' => 1,
            'show_names' => 1,
        ]);
        $settings = $this->settings()->get($this->plain);

        $this->assertSame('', $settings['tagline']);
        $this->assertSame([['label' => 'Good', 'url' => 'https://example.com/ok']], $settings['links']);
        $this->assertSame(['files', 'header'], $settings['panels']);
        $this->assertSame(['header'], $settings['public_panels']);
        $this->assertFalse($settings['public'], 'core public access is off');
        $this->assertFalse($settings['listed']);
        $this->assertTrue($settings['show_names']);
    }

    public function testLongStoredTaglineIsClampedOnRead(): void
    {
        $this->storeRaw($this->project, ['tagline' => str_repeat('é', 200)]);
        $this->assertSame(str_repeat('é', ProjectPageSettings::TAGLINE_MAX), $this->settings()->get($this->project)['tagline']);
    }

    public function testListedNeedsAnIdentifierOnRead(): void
    {
        $this->storeRaw($this->project, ['is_public' => 1, 'is_listed' => 1]);
        $this->assertTrue($this->settings()->get($this->project)['listed']);

        $this->assertTrue($this->container['projectModel']->update(['id' => $this->project, 'identifier' => '']));
        $settings = $this->settings()->get($this->project);
        $this->assertTrue($settings['public']);
        $this->assertFalse($settings['listed']);
    }

    public function testPublicFollowsCorePublicAccessOnRead(): void
    {
        $this->storeRaw($this->project, ['is_public' => 1, 'is_listed' => 1]);
        $this->assertTrue($this->container['projectModel']->disablePublicAccess($this->project));
        $settings = $this->settings()->get($this->project);
        $this->assertFalse($settings['public']);
        $this->assertFalse($settings['listed']);
    }

    public function testCoreProjectMetadataCannotChangeTheSettings(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid()));
        $before = $this->settings()->get($this->project);

        // Core's JSON-RPC saveProjectMetadata, called by a project viewer.
        $this->loginAs($this->viewer);
        $api = new \Kanboard\Api\Procedure\ProjectMetaDataProcedure($this->container);
        $api->saveProjectMetadata($this->project, [
            'steward_public' => '1', 'steward_listed' => '1', 'steward_show_names' => '1',
            'steward_tagline' => 'Hijacked',
            'steward_links' => '[{"label":"x","url":"javascript:alert(1)"}]',
            'steward_panels' => '["files"]',
        ]);

        $this->assertSame($before, $this->settings()->get($this->project));
    }

    public function testRowGoesWithItsProject(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid()));
        $this->assertNotSame([], $this->metadata($this->project));
        $this->assertTrue($this->container['projectModel']->remove($this->project));
        $this->assertSame([], $this->metadata($this->project));
    }

    // --- model: validation ------------------------------------------------------------------------------

    public function testTaglineAtMost160Characters(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['tagline' => str_repeat('é', 160)])));
        $errors = $this->settings()->save($this->project, $this->valid(['tagline' => str_repeat('é', 161)]));
        $this->assertSame(['tagline'], array_keys($errors));
        $this->assertStringContainsString('160', $errors['tagline']);
    }

    public function testTaglineIsOneLineOfPlainText(): void
    {
        foreach (["two\nlines", "bell\x07", ['array']] as $bad) {
            $errors = $this->settings()->save($this->project, $this->valid(['tagline' => $bad]));
            $this->assertArrayHasKey('tagline', $errors, var_export($bad, true));
        }
    }

    public function testAtMostFiveLinks(): void
    {
        $link = ['label' => 'L', 'url' => 'https://example.com'];
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['links' => array_fill(0, 5, $link)])));
        $errors = $this->settings()->save($this->project, $this->valid(['links' => array_fill(0, 6, $link)]));
        $this->assertSame(['links'], array_keys($errors));
    }

    public function testLinkLabelIsRequiredAndAtMost40Characters(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['links' => [['label' => str_repeat('a', 40), 'url' => 'https://example.com']]])));
        foreach (['', '   ', str_repeat('a', 41), "a\nb"] as $label) {
            $errors = $this->settings()->save($this->project, $this->valid(['links' => [['label' => $label, 'url' => 'https://example.com']]]));
            $this->assertArrayHasKey('links', $errors, var_export($label, true));
        }
    }

    public function testLinkUrlMustBeHttpOrHttpsWithAHost(): void
    {
        foreach (['http://example.com', 'https://example.com/x', 'HtTpS://EXAMPLE.com'] as $url) {
            $this->assertSame([], $this->settings()->save($this->project, $this->valid(['links' => [['label' => 'ok', 'url' => $url]]])), $url);
        }
        foreach (['javascript:alert(1)', 'JaVaScRiPt://example.com/%0aalert(1)', 'data:text/html,hi', 'ftp://example.com',
            '//example.com', 'example.com', 'https://', 'http:///path', 'mailto:a@example.com', '', 'https://exa mple.com',
            "https://example.com/\nx"] as $url) {
            $errors = $this->settings()->save($this->project, $this->valid(['links' => [['label' => 'bad', 'url' => $url]]]));
            $this->assertArrayHasKey('links', $errors, $url);
        }
    }

    public function testMalformedLinkRowsAreErrors(): void
    {
        foreach (['not a list', [['label' => 'x']], ['https://example.com'], [['label' => ['x'], 'url' => 'https://example.com']]] as $links) {
            $errors = $this->settings()->save($this->project, $this->valid(['links' => $links]));
            $this->assertArrayHasKey('links', $errors, var_export($links, true));
        }
    }

    public function testPanelsMustComeFromTheHomeSet(): void
    {
        foreach ([['header', 'comments'], ['header', 'header'], 'header', [['header']]] as $panels) {
            $errors = $this->settings()->save($this->project, $this->valid(['panels' => $panels]));
            $this->assertArrayHasKey('panels', $errors, var_export($panels, true));
        }
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['panels' => []])));
    }

    public function testPublicPanelsMustComeFromThePublicSafeSet(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['public_panels' => ProjectPageSettings::PUBLIC_PANELS])));
        foreach ([['files'], ['header', 'header'], ['comments']] as $panels) {
            $errors = $this->settings()->save($this->project, $this->valid(['public_panels' => $panels]));
            $this->assertArrayHasKey('public_panels', $errors, var_export($panels, true));
        }
    }

    public function testPublicPageNeedsCorePublicAccess(): void
    {
        $errors = $this->settings()->save($this->plain, $this->valid(['public' => true]));
        $this->assertSame(['public'], array_keys($errors));
        $this->assertSame('Turn on public access for this project first', $errors['public']);
    }

    public function testListedNeedsThePublicPage(): void
    {
        $errors = $this->settings()->save($this->project, $this->valid(['listed' => true]));
        $this->assertSame(['listed'], array_keys($errors));
        $this->assertSame('Turn on the public page first', $errors['listed']);
    }

    public function testListedNeedsAProjectIdentifier(): void
    {
        $this->assertTrue($this->container['projectModel']->enablePublicAccess($this->plain));
        $errors = $this->settings()->save($this->plain, $this->valid(['public' => true, 'listed' => true]));
        $this->assertSame(['listed' => 'Set a project identifier first'], $errors);
    }

    public function testBooleansMustBeBooleans(): void
    {
        $errors = $this->settings()->save($this->project, $this->valid(['public' => '1', 'listed' => 1, 'show_names' => 'yes']));
        $this->assertSame(['public', 'listed', 'show_names'], array_keys($errors));
    }

    public function testAnyErrorWritesNothing(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['public' => true])));
        $before = $this->metadata($this->project);
        $auditBefore = count($this->auditRows());

        $errors = $this->settings()->save($this->project, $this->valid([
            'tagline' => 'Changed', 'public' => false, 'show_names' => true,
            'links' => [['label' => 'x', 'url' => 'javascript:alert(1)']],
        ]));
        $this->assertSame(['links'], array_keys($errors));
        $this->assertSame($before, $this->metadata($this->project));
        $this->assertCount($auditBefore, $this->auditRows());
    }

    public function testMissingKeysKeepTheirStoredValues(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['public' => true])));
        $this->assertSame([], $this->settings()->save($this->project, ['tagline' => 'Only this']));
        $settings = $this->settings()->get($this->project);
        $this->assertSame('Only this', $settings['tagline']);
        $this->assertTrue($settings['public']);
        $this->assertSame(['header', 'progress'], $settings['panels']);
    }

    // --- model: audit -----------------------------------------------------------------------------------

    public function testPublicToggleChangesAreAudited(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['public' => true, 'listed' => true])));
        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::ADMIN, (int) $rows[0]['actor_user_id']);
        $this->assertSame([
            'project_id' => $this->project,
            'changes' => ['public' => [false, true], 'listed' => [false, true]],
        ], json_decode($rows[0]['details'], true));

        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['public' => true, 'listed' => false, 'show_names' => true])));
        $rows = $this->auditRows();
        $this->assertCount(2, $rows);
        $this->assertSame(['listed' => [true, false], 'show_names' => [false, true]], json_decode($rows[1]['details'], true)['changes']);
    }

    public function testOtherChangesAreNotAudited(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['tagline' => 'a', 'panels' => ['files']])));
        $this->assertSame([], $this->settings()->save($this->project, $this->valid(['tagline' => 'b'])));
        $this->assertSame([], $this->auditRows());
    }

    // --- wiring -----------------------------------------------------------------------------------------

    public function testRegisteredAsProjectManagerInTheProjectAccessMap(): void
    {
        $this->assertContains(['StewardProjectSettingsController', ['show', 'save'], Role::PROJECT_MANAGER], AclCheck::EXPECTED['projectAccessMap']);
        $map = $this->container['projectAccessMap'];
        foreach (['show', 'save'] as $action) {
            $this->assertSame($map->getRoleHierarchy(Role::PROJECT_MANAGER), $map->getRoles('StewardProjectSettingsController', $action));
        }
        $this->assertTrue($this->container['stewardAcl']->verify());
    }

    public function testFeatureGateAndSwitchTableRows(): void
    {
        $gate = new FeatureGate($this->container);
        $this->assertSame('project_pages', $gate->featureFor('StewardProjectSettingsController', 'show'));
        $this->assertSame('project_pages', $gate->featureFor('StewardProjectSettingsController', 'save'));
        $table = new SwitchActionTable();
        $this->assertSame('read', $table->classify('Steward', 'StewardProjectSettingsController', 'show'));
        $this->assertSame('write', $table->classify('Steward', 'StewardProjectSettingsController', 'save'));
    }

    public function testProjectAccessByRole(): void
    {
        $expected = [self::ADMIN => true, $this->manager => true, $this->member => false, $this->viewer => false];
        foreach ($expected as $userId => $allowed) {
            $this->loginAs($userId);
            foreach (['show', 'save'] as $action) {
                $this->assertSame($allowed, $this->container['helper']->user->hasProjectAccess('StewardProjectSettingsController', $action, $this->project), $userId.' '.$action);
            }
        }
    }

    // --- controller -------------------------------------------------------------------------------------

    public function testViewerAndMemberAreRefusedByTheActionsThemselves(): void
    {
        foreach ([$this->viewer, $this->member] as $userId) {
            foreach (['show', 'save'] as $action) {
                $this->loginAs($userId);
                try {
                    $this->act($action, $this->project, $this->form());
                    $this->fail('no 403 for user '.$userId.' on '.$action);
                } catch (AccessForbiddenException $e) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertSame([], $this->metadata($this->project));
    }

    public function testManagerAndAdminSeeTheForm(): void
    {
        foreach ([$this->manager, self::ADMIN] as $userId) {
            $this->loginAs($userId);
            $response = $this->act('show', $this->project, [], true, 'GET');
            $this->assertSame(200, $response->code());
            $body = $response->body();
            $this->assertStringContainsString('id="form-tagline"', $body);
            $this->assertStringContainsString('name="link_url[4]"', $body);
            $this->assertStringContainsString('name="public_panels[activity]"', $body);
            $this->assertStringNotContainsString('name="public_panels[files]"', $body);
            $this->assertStringContainsString('id="form-project_id" value="'.$this->project.'"', $body);
        }
    }

    /** Spec §5.3: beside the Public page switch, what core public access already publishes. */
    public function testPublicPageSwitchSaysWhatCorePublicAccessExposes(): void
    {
        foreach ([$this->project, $this->plain] as $projectId) {
            $body = $this->act('show', $projectId, [], true, 'GET')->body();
            $fieldset = substr($body, strpos($body, '<legend>Public page</legend>'));
            $fieldset = substr($fieldset, 0, strpos($fieldset, '</fieldset>'));
            foreach (['read-only board (with assignees)', 'RSS', 'iCal', 'task pages (with comments and subtasks)', 'never shows that link'] as $needle) {
                $this->assertStringContainsString($needle, $fieldset);
            }
            $this->assertLessThan(strpos($fieldset, 'read-only board'), strpos($fieldset, 'name="public"'));
        }
    }

    /**
     * Reads stay pure: with core public access off the stored switch is kept, and the page says it comes back
     * when public access is turned on again.
     */
    public function testNoticeWhenThePublicPageWillComeBackWithPublicAccess(): void
    {
        $notice = 'The public page will come back if public access is turned on again';
        $fieldset = function (): string {
            $body = $this->act('show', $this->project, [], true, 'GET')->body();
            $fieldset = substr($body, strpos($body, '<legend>Public page</legend>'));
            return substr($fieldset, 0, strpos($fieldset, '</fieldset>'));
        };

        $this->storeRaw($this->project, ['is_public' => 1, 'is_listed' => 1]);
        $this->assertStringNotContainsString($notice, $fieldset(), 'core public access on');

        $this->assertTrue($this->container['projectModel']->disablePublicAccess($this->project));
        $html = $fieldset();
        $this->assertStringContainsString($notice, $html);
        $this->assertLessThan(strpos($html, $notice), strpos($html, 'name="public"'));
        $this->assertSame(1, (int) $this->container['db']->table(ProjectPageSettings::TABLE)
            ->eq('project_id', $this->project)->findOneColumn('is_public'), 'the read changed nothing');

        $this->storeRaw($this->project, ['is_public' => 0, 'is_listed' => 0]);
        $this->assertStringNotContainsString($notice, $fieldset(), 'stored off');
        $this->assertStringNotContainsString($notice, $this->act('show', $this->plain, [], true, 'GET')->body(), 'nothing stored');
    }

    public function testShowEscapesStoredValues(): void
    {
        $this->assertSame([], $this->settings()->save($this->project, $this->valid([
            'tagline' => '"><script>x</script>',
            'links' => [['label' => '<img src=x>', 'url' => 'https://example.com/?a="b"&c']],
        ])));
        $body = $this->act('show', $this->project, [], true, 'GET')->body();
        $this->assertStringNotContainsString('<script>x', $body);
        $this->assertStringNotContainsString('<img src=x>', $body);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $body);
    }

    public function testManagerSavesTheForm(): void
    {
        $this->loginAs($this->manager);
        $response = $this->act('save', $this->project, $this->form() + ['project_id' => (string) $this->project]);
        $this->assertStringContainsString('StewardProjectSettingsController', $response->headers()['Location'] ?? '');
        $this->assertSame('Settings saved successfully.', $this->flash()['success'] ?? null);

        $this->assertSame([
            'tagline' => 'Hello',
            'links' => [['label' => 'Docs', 'url' => 'https://docs.example.com'], ['label' => 'Repo', 'url' => 'http://git.example.com/repo']],
            'panels' => ['progress', 'header', 'files'],
            'public_panels' => ['progress', 'header'],
            'public' => true,
            'listed' => false,
            'show_names' => true,
        ], $this->settings()->get($this->project));

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertSame($this->manager, (int) $rows[0]['actor_user_id']);
    }

    public function testAdminSavesTheForm(): void
    {
        $response = $this->act('save', $this->project, $this->form(['tagline' => 'By admin']));
        $this->assertArrayHasKey('Location', $response->headers());
        $this->assertSame('By admin', $this->settings()->get($this->project)['tagline']);
    }

    public function testUncheckedBoxesTurnThingsOff(): void
    {
        $this->act('save', $this->project, $this->form());
        $form = $this->form();
        unset($form['public'], $form['show_names'], $form['panels'], $form['public_panels']);
        $this->act('save', $this->project, $form);
        $settings = $this->settings()->get($this->project);
        $this->assertFalse($settings['public']);
        $this->assertFalse($settings['show_names']);
        $this->assertSame([], $settings['panels']);
        $this->assertSame([], $settings['public_panels']);
    }

    public function testBodyProjectIdOtherThanTheQueryIsRefused(): void
    {
        $this->loginAs($this->manager);
        $response = $this->act('save', $this->project, $this->form() + ['project_id' => (string) $this->plain]);
        $this->assertArrayHasKey('Location', $response->headers());
        $this->assertNotEmpty($this->flash()['failure'] ?? null);
        $this->assertSame([], $this->metadata($this->project));
        $this->assertSame([], $this->metadata($this->plain));
        $this->assertSame([], $this->auditRows());
    }

    public function testInvalidValuesRerenderWithTheErrorAndWriteNothing(): void
    {
        $this->loginAs($this->manager);
        $response = $this->act('save', $this->plain, $this->form(['link_url' => ['javascript:alert(1)']]));
        $this->assertSame(200, $response->code());
        $body = $response->body();
        $this->assertStringContainsString('Links must start with http:// or https://', $body);
        $this->assertStringContainsString('Turn on public access for this project first', $body);
        $this->assertStringContainsString('value="javascript:alert(1)"', $body, 'the submitted value is shown again');
        $this->assertSame([], $this->metadata($this->plain));
        $this->assertSame([], $this->auditRows());
    }

    public function testSaveNeedsPostAndAValidCsrfToken(): void
    {
        foreach ([[true, 'GET'], [false, 'POST']] as [$csrf, $method]) {
            try {
                $this->act('save', $this->project, $this->form(), $csrf, $method);
                $this->fail('accepted '.$method.($csrf ? ' with' : ' without').' token');
            } catch (AccessForbiddenException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->metadata($this->project));
    }

    public function testFeatureOffAnswersTheGates404(): void
    {
        $this->container['configModel']->save(['steward_project_pages' => '0']);
        $this->container['memoryCache']->flush();
        foreach (['show', 'save'] as $action) {
            $response = $this->act($action, $this->project, $this->form());
            $this->assertSame(404, $response->code());
            $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body());
        }
        $this->assertSame([], $this->metadata($this->project));
    }

    public function testUnknownProjectIs404(): void
    {
        $this->expectException(\Kanboard\Core\Controller\PageNotFoundException::class);
        $this->act('show', 9999, [], true, 'GET');
    }

    // --- sidebar ----------------------------------------------------------------------------------------

    private function sidebar(): string
    {
        $project = $this->container['projectModel']->getById($this->project);
        return $this->container['template']->render('project/sidebar', ['project' => $project]);
    }

    public function testSidebarLinkForManagersAndAdmins(): void
    {
        foreach ([self::ADMIN => true, $this->manager => true, $this->member => false, $this->viewer => false] as $userId => $shown) {
            $this->loginAs($userId);
            $html = $this->sidebar();
            $this->assertSame($shown, str_contains($html, 'StewardProjectSettingsController'), (string) $userId);
        }
    }

    public function testSidebarLinkNeedsTheFeatureAtStartup(): void
    {
        $this->assertStringNotContainsString('StewardProjectSettingsController', $this->sidebar());
    }
}
