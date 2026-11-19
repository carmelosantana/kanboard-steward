<?php
namespace Kanboard\Plugin\Steward\Model;

/**
 * What a switched session may do (spec §2, Decision 8): every `(controller, action)` is `read`, `write` (allowed,
 * and audited as `switch.action` before it runs) or `blocked` (403). Anything not listed is blocked.
 *
 * CORE covers every public action of every controller in Kanboard 1.2.47's app/Controller; SwitchActionTableTest
 * reflects over them so nothing is missed. Core mutates on GET in many places, so an action that writes on some
 * parameter is `write` (noted beside it). Session-only state (filters, list order) is not a write.
 */
final class SwitchActionTable
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const BLOCKED = 'blocked';

    public const CORE = [
        'ActionController::index' => 'read',
        'ActionController::confirm' => 'read',
        'ActionController::remove' => 'write',
        'ActionCreationController::create' => 'read',
        'ActionCreationController::event' => 'read',
        'ActionCreationController::params' => 'write',  // creates the action when it takes no parameters
        'ActionCreationController::save' => 'write',
        'ActivityController::user' => 'read',
        'ActivityController::project' => 'read',
        'ActivityController::task' => 'read',
        'AnalyticController::leadAndCycleTime' => 'read',
        'AnalyticController::timeComparison' => 'read',
        'AnalyticController::averageTimeByColumn' => 'read',
        'AnalyticController::taskDistribution' => 'read',
        'AnalyticController::userDistribution' => 'read',
        'AnalyticController::cfd' => 'read',
        'AnalyticController::burndown' => 'read',
        'AnalyticController::estimatedVsActualByColumn' => 'read',
        'AppController::accessForbidden' => 'read',
        'AppController::notFound' => 'read',
        'AuthController::login' => 'read',
        // A real sign-in replaces the switched session (SwitchSubscriber records the end).
        'AuthController::check' => 'write',
        // Leaving must always work: exempt from the 503 rule (SwitchGuard::LEAVING).
        'AuthController::logout' => 'write',
        'AvatarFileController::show' => 'read',
        'AvatarFileController::upload' => 'blocked',
        'AvatarFileController::remove' => 'blocked',
        'AvatarFileController::image' => 'read',
        'BoardAjaxController::save' => 'write',
        'BoardAjaxController::check' => 'read',
        // POST, but only stores the search filter in the session.
        'BoardAjaxController::reload' => 'read',
        'BoardAjaxController::collapse' => 'write',      // GET: user metadata
        'BoardAjaxController::expand' => 'write',        // GET: user metadata
        'BoardPopoverController::confirmCloseColumnTasks' => 'read',
        'BoardPopoverController::closeColumnTasks' => 'write',
        'BoardTooltipController::tasklinks' => 'read',
        'BoardTooltipController::externallinks' => 'read',
        'BoardTooltipController::subtasks' => 'read',
        'BoardTooltipController::attachments' => 'read',
        'BoardTooltipController::description' => 'read',
        'BoardTooltipController::recurrence' => 'read',
        'BoardTooltipController::swimlane' => 'read',
        'BoardViewController::readonly' => 'read',
        'BoardViewController::show' => 'read',
        'CaptchaController::image' => 'read',
        'CategoryController::index' => 'read',
        'CategoryController::create' => 'read',
        'CategoryController::save' => 'write',
        'CategoryController::edit' => 'read',
        'CategoryController::update' => 'write',
        'CategoryController::confirm' => 'read',
        'CategoryController::remove' => 'write',
        'ColumnController::index' => 'read',
        'ColumnController::create' => 'read',
        'ColumnController::save' => 'write',
        'ColumnController::edit' => 'read',
        'ColumnController::update' => 'write',
        'ColumnController::move' => 'write',
        'ColumnController::confirm' => 'read',
        'ColumnController::remove' => 'write',
        'ColumnMoveRestrictionController::create' => 'read',
        'ColumnMoveRestrictionController::save' => 'write',
        'ColumnMoveRestrictionController::confirm' => 'read',
        'ColumnMoveRestrictionController::remove' => 'write',
        'ColumnRestrictionController::create' => 'read',
        'ColumnRestrictionController::save' => 'write',
        'ColumnRestrictionController::confirm' => 'read',
        'ColumnRestrictionController::remove' => 'write',
        'CommentController::create' => 'read',
        'CommentController::save' => 'write',
        'CommentController::edit' => 'read',
        'CommentController::update' => 'write',
        'CommentController::confirm' => 'read',
        'CommentController::remove' => 'write',
        'CommentController::toggleSorting' => 'write',   // GET: user metadata
        'CommentListController::show' => 'read',
        'CommentListController::save' => 'write',
        'CommentListController::toggleSorting' => 'write', // GET: user metadata
        'CommentMailController::create' => 'read',
        'CommentMailController::save' => 'write',
        'ConfigController::index' => 'blocked',
        'ConfigController::save' => 'blocked',
        'ConfigController::application' => 'blocked',
        'ConfigController::email' => 'blocked',
        'ConfigController::project' => 'blocked',
        'ConfigController::board' => 'blocked',
        'ConfigController::integrations' => 'blocked',
        'ConfigController::webhook' => 'blocked',
        'ConfigController::api' => 'blocked',
        'ConfigController::downloadDb' => 'blocked',
        'ConfigController::optimizeDb' => 'blocked',
        'ConfigController::uploadDb' => 'blocked',
        'ConfigController::saveUploadedDb' => 'blocked',
        'ConfigController::token' => 'blocked',
        'CronjobController::run' => 'blocked',
        'CurrencyController::show' => 'blocked',
        'CurrencyController::create' => 'blocked',
        'CurrencyController::save' => 'blocked',
        'CurrencyController::change' => 'blocked',
        'CurrencyController::update' => 'blocked',
        'CustomFilterController::index' => 'read',
        'CustomFilterController::create' => 'read',
        'CustomFilterController::save' => 'write',
        'CustomFilterController::confirm' => 'read',
        'CustomFilterController::remove' => 'write',
        'CustomFilterController::edit' => 'read',
        'CustomFilterController::update' => 'write',
        'DashboardController::show' => 'read',
        'DashboardController::tasks' => 'read',
        'DashboardController::subtasks' => 'read',
        'DashboardController::projects' => 'read',
        'DocumentationController::shortcuts' => 'read',
        'ExportController::tasks' => 'read',
        'ExportController::subtasks' => 'read',
        'ExportController::summary' => 'read',
        'ExportController::transitions' => 'read',
        'ExternalTaskCreationController::step1' => 'read',
        'ExternalTaskCreationController::step2' => 'read',
        'ExternalTaskCreationController::step3' => 'write',
        'ExternalTaskViewController::show' => 'read',
        'FeedController::user' => 'read',
        'FeedController::project' => 'read',
        'FileViewerController::show' => 'read',
        'FileViewerController::image' => 'read',
        'FileViewerController::browser' => 'read',
        'FileViewerController::thumbnail' => 'read',
        'FileViewerController::download' => 'read',
        // Lists groups for the project permissions form.
        'GroupAjaxController::autocomplete' => 'read',
        'GroupCreationController::show' => 'blocked',
        'GroupCreationController::save' => 'blocked',
        'GroupListController::index' => 'blocked',
        'GroupListController::users' => 'blocked',
        'GroupListController::associate' => 'blocked',
        'GroupListController::addUser' => 'blocked',
        'GroupListController::dissociate' => 'blocked',
        'GroupListController::removeUser' => 'blocked',
        'GroupListController::confirm' => 'blocked',
        'GroupListController::remove' => 'blocked',
        'GroupModificationController::show' => 'blocked',
        'GroupModificationController::save' => 'blocked',
        'ICalendarController::user' => 'read',
        'ICalendarController::project' => 'read',
        // Link types are application settings.
        'LinkController::show' => 'blocked',
        'LinkController::create' => 'blocked',
        'LinkController::save' => 'blocked',
        'LinkController::edit' => 'blocked',
        'LinkController::update' => 'blocked',
        'LinkController::confirm' => 'blocked',
        'LinkController::remove' => 'blocked',
        'OAuthController::unlink' => 'blocked',
        'PasswordResetController::create' => 'blocked',
        'PasswordResetController::save' => 'blocked',
        'PasswordResetController::change' => 'blocked',
        'PasswordResetController::update' => 'blocked',
        'PluginController::show' => 'blocked',
        'PluginController::directory' => 'blocked',
        'PluginController::install' => 'blocked',
        'PluginController::update' => 'blocked',
        'PluginController::confirm' => 'blocked',
        'PluginController::uninstall' => 'blocked',
        'PredefinedTaskDescriptionController::create' => 'read',
        'PredefinedTaskDescriptionController::save' => 'write',
        'PredefinedTaskDescriptionController::edit' => 'read',
        'PredefinedTaskDescriptionController::update' => 'write',
        'PredefinedTaskDescriptionController::confirm' => 'read',
        'PredefinedTaskDescriptionController::remove' => 'write',
        'ProjectActionDuplicationController::show' => 'read',
        'ProjectActionDuplicationController::save' => 'write',
        // The target may create projects (private ones at least); the forms only render.
        'ProjectCreationController::create' => 'read',
        'ProjectCreationController::createPrivate' => 'read',
        'ProjectCreationController::save' => 'write',
        'ProjectEditController::show' => 'read',
        'ProjectEditController::update' => 'write',
        'ProjectFileController::create' => 'read',
        'ProjectFileController::save' => 'write',
        'ProjectFileController::remove' => 'write',
        'ProjectFileController::confirm' => 'read',
        'ProjectListController::show' => 'read',
        'ProjectOverviewController::show' => 'read',
        'ProjectPermissionController::index' => 'read',
        'ProjectPermissionController::addUser' => 'write',
        'ProjectPermissionController::removeUser' => 'write',
        'ProjectPermissionController::changeUserRole' => 'write',
        'ProjectPermissionController::addGroup' => 'write',
        'ProjectPermissionController::removeGroup' => 'write',
        'ProjectPermissionController::changeGroupRole' => 'write',
        'ProjectPredefinedContentController::show' => 'read',
        'ProjectPredefinedContentController::update' => 'write',
        'ProjectRoleController::show' => 'read',
        'ProjectRoleController::create' => 'read',
        'ProjectRoleController::save' => 'write',
        'ProjectRoleController::edit' => 'read',
        'ProjectRoleController::update' => 'write',
        'ProjectRoleController::confirm' => 'read',
        'ProjectRoleController::remove' => 'write',
        'ProjectRoleRestrictionController::create' => 'read',
        'ProjectRoleRestrictionController::save' => 'write',
        'ProjectRoleRestrictionController::confirm' => 'read',
        'ProjectRoleRestrictionController::remove' => 'write',
        'ProjectStatusController::confirmEnable' => 'read',
        'ProjectStatusController::enable' => 'write',
        'ProjectStatusController::confirmDisable' => 'read',
        'ProjectStatusController::disable' => 'write',
        'ProjectStatusController::confirmRemove' => 'read',
        'ProjectStatusController::remove' => 'write',
        'ProjectTagController::index' => 'read',
        'ProjectTagController::create' => 'read',
        'ProjectTagController::save' => 'write',
        'ProjectTagController::edit' => 'read',
        'ProjectTagController::update' => 'write',
        'ProjectTagController::confirm' => 'read',
        'ProjectTagController::remove' => 'write',
        'ProjectTagController::confirmMakeGlobalTag' => 'read',
        'ProjectTagController::makeGlobalTag' => 'write',
        'ProjectTagController::updateSettings' => 'write',
        'ProjectUserOverviewController::managers' => 'read',
        'ProjectUserOverviewController::members' => 'read',
        'ProjectUserOverviewController::opens' => 'read',
        'ProjectUserOverviewController::closed' => 'read',
        'ProjectUserOverviewController::users' => 'read',
        'ProjectViewController::show' => 'read',
        'ProjectViewController::share' => 'read',
        'ProjectViewController::updateSharing' => 'write', // GET with CSRF
        'ProjectViewController::integrations' => 'read',
        'ProjectViewController::updateIntegrations' => 'write',
        'ProjectViewController::notifications' => 'read',
        'ProjectViewController::updateNotifications' => 'write',
        'ProjectViewController::duplicate' => 'read',
        'ProjectViewController::doDuplication' => 'write',
        'ProjectViewController::importTasks' => 'read',
        'ProjectViewController::doTasksImport' => 'write',
        'SearchController::index' => 'read',
        'SearchController::activity' => 'read',
        'SubtaskController::create' => 'read',
        'SubtaskController::save' => 'write',
        'SubtaskController::edit' => 'read',
        'SubtaskController::update' => 'write',
        'SubtaskController::confirm' => 'read',
        'SubtaskController::remove' => 'write',
        'SubtaskController::movePosition' => 'write',
        'SubtaskConverterController::show' => 'read',
        'SubtaskConverterController::save' => 'write',
        'SubtaskRestrictionController::show' => 'read',
        'SubtaskRestrictionController::save' => 'write',
        'SubtaskStatusController::change' => 'write',    // GET with CSRF
        'SubtaskStatusController::timer' => 'write',     // GET with CSRF
        'SwimlaneController::index' => 'read',
        'SwimlaneController::create' => 'read',
        'SwimlaneController::save' => 'write',
        'SwimlaneController::edit' => 'read',
        'SwimlaneController::update' => 'write',
        'SwimlaneController::confirm' => 'read',
        'SwimlaneController::remove' => 'write',
        'SwimlaneController::disable' => 'write',
        'SwimlaneController::enable' => 'write',
        'SwimlaneController::move' => 'write',
        // Global tags are application settings.
        'TagController::index' => 'blocked',
        'TagController::create' => 'blocked',
        'TagController::save' => 'blocked',
        'TagController::edit' => 'blocked',
        'TagController::update' => 'blocked',
        'TagController::confirm' => 'blocked',
        'TagController::remove' => 'blocked',
        'TaskAjaxController::autocomplete' => 'read',
        'TaskAjaxController::suggest' => 'read',
        'TaskAjaxController::preview' => 'read',         // POST: renders Markdown, stores nothing
        'TaskBulkChangePropertyController::show' => 'read',
        'TaskBulkChangePropertyController::save' => 'write',
        'TaskBulkController::show' => 'read',
        'TaskBulkController::save' => 'write',
        'TaskBulkMoveColumnController::show' => 'read',
        'TaskBulkMoveColumnController::save' => 'write',
        'TaskCreationController::show' => 'read',
        'TaskCreationController::save' => 'write',
        'TaskCreationController::duplicateProjects' => 'write',
        'TaskDuplicationController::duplicate' => 'write',  // GET with CSRF
        'TaskDuplicationController::move' => 'write',       // moves the task on POST
        'TaskDuplicationController::copy' => 'write',       // copies the task on POST
        'TaskExternalLinkController::find' => 'read',
        'TaskExternalLinkController::create' => 'read',
        'TaskExternalLinkController::save' => 'write',
        'TaskExternalLinkController::edit' => 'read',
        'TaskExternalLinkController::update' => 'write',
        'TaskExternalLinkController::confirm' => 'read',
        'TaskExternalLinkController::remove' => 'write',
        'TaskFileController::screenshot' => 'write',        // uploads on POST
        'TaskFileController::create' => 'read',
        'TaskFileController::save' => 'write',
        'TaskFileController::remove' => 'write',
        'TaskFileController::confirm' => 'read',
        'TaskImportController::show' => 'read',
        'TaskImportController::save' => 'write',
        'TaskImportController::template' => 'read',
        'TaskInternalLinkController::create' => 'read',
        'TaskInternalLinkController::save' => 'write',
        'TaskInternalLinkController::edit' => 'read',
        'TaskInternalLinkController::update' => 'write',
        'TaskInternalLinkController::confirm' => 'read',
        'TaskInternalLinkController::remove' => 'write',
        // Sort order and the subtask toggle live in the session only.
        'TaskListController::show' => 'read',
        'TaskMailController::create' => 'read',
        'TaskMailController::send' => 'write',
        'TaskModificationController::assignToMe' => 'write', // GET with CSRF
        'TaskModificationController::start' => 'write',      // GET with CSRF
        'TaskModificationController::edit' => 'read',
        'TaskModificationController::update' => 'write',
        'TaskMovePositionController::show' => 'read',
        'TaskMovePositionController::save' => 'write',
        'TaskPopoverController::screenshot' => 'read',
        'TaskRecurrenceController::edit' => 'read',
        'TaskRecurrenceController::update' => 'write',
        'TaskReorderController::reorderColumn' => 'write',
        'TaskStatusController::close' => 'write',        // GET with confirmation=yes
        'TaskStatusController::open' => 'write',         // GET with confirmation=yes
        'TaskSuppressionController::confirm' => 'read',
        'TaskSuppressionController::remove' => 'write',
        'TaskViewController::readonly' => 'read',
        'TaskViewController::show' => 'read',
        'TaskViewController::analytics' => 'read',
        'TaskViewController::timetracking' => 'read',
        'TaskViewController::transitions' => 'read',
        // TwoFactorController extends UserViewController: its inherited pages are refused too.
        'TwoFactorController::index' => 'blocked',
        'TwoFactorController::show' => 'blocked',
        'TwoFactorController::test' => 'blocked',
        'TwoFactorController::deactivate' => 'blocked',
        'TwoFactorController::check' => 'blocked',
        'TwoFactorController::code' => 'blocked',
        'TwoFactorController::disable' => 'blocked',
        'TwoFactorController::qrcode' => 'blocked',
        'TwoFactorController::profile' => 'blocked',
        'TwoFactorController::timesheet' => 'blocked',
        'TwoFactorController::passwordReset' => 'blocked',
        'TwoFactorController::lastLogin' => 'blocked',
        'TwoFactorController::sessions' => 'blocked',
        'TwoFactorController::removeSession' => 'blocked',
        'TwoFactorController::notifications' => 'blocked',
        'TwoFactorController::integrations' => 'blocked',
        'TwoFactorController::external' => 'blocked',
        'TwoFactorController::share' => 'blocked',
        // User pickers in task and comment forms.
        'UserAjaxController::autocomplete' => 'read',
        'UserAjaxController::mention' => 'read',
        'UserAjaxController::status' => 'read',
        // The switched session carries the target's API token: refused for reading and writing.
        'UserApiAccessController::show' => 'blocked',
        'UserApiAccessController::generate' => 'blocked',
        'UserApiAccessController::remove' => 'blocked',
        'UserCreationController::show' => 'blocked',
        'UserCreationController::save' => 'blocked',
        'UserCredentialController::changePassword' => 'blocked',
        'UserCredentialController::savePassword' => 'blocked',
        'UserCredentialController::changeAuthentication' => 'blocked',
        'UserCredentialController::saveAuthentication' => 'blocked',
        'UserCredentialController::unlock' => 'blocked',
        'UserImportController::show' => 'blocked',
        'UserImportController::save' => 'blocked',
        'UserImportController::template' => 'blocked',
        'UserInviteController::show' => 'blocked',
        'UserInviteController::save' => 'blocked',
        'UserInviteController::signup' => 'blocked',
        'UserInviteController::register' => 'blocked',
        'UserListController::show' => 'blocked',
        'UserListController::search' => 'blocked',
        'UserModificationController::show' => 'blocked',
        'UserModificationController::save' => 'blocked',
        'UserStatusController::confirmRemove' => 'blocked',
        'UserStatusController::remove' => 'blocked',
        'UserStatusController::confirmEnable' => 'blocked',
        'UserStatusController::enable' => 'blocked',
        'UserStatusController::confirmDisable' => 'blocked',
        'UserStatusController::disable' => 'blocked',
        'UserViewController::profile' => 'read',
        'UserViewController::show' => 'read',
        'UserViewController::timesheet' => 'read',
        'UserViewController::passwordReset' => 'blocked',
        'UserViewController::lastLogin' => 'blocked',     // sign-in history with IP addresses
        'UserViewController::sessions' => 'blocked',
        'UserViewController::removeSession' => 'blocked',
        'UserViewController::notifications' => 'blocked', // saves on POST
        'UserViewController::integrations' => 'blocked',  // saves on POST
        'UserViewController::external' => 'blocked',
        'UserViewController::share' => 'blocked',         // switches public access with ?switch=
        'WebNotificationController::show' => 'read',
        'WebNotificationController::flush' => 'write',      // GET with CSRF
        'WebNotificationController::remove' => 'write',     // GET with CSRF
        'WebNotificationController::redirect' => 'write',   // GET: marks the notification read
    ];

    /** Steward's own controllers: leaving works, nothing else does (a nested start is refused by policy too). */
    public const STEWARD = [
        'StewardSwitchController::back' => 'write',
        'StewardSwitchController::confirm' => 'blocked',
        'StewardSwitchController::start' => 'blocked',
        'StewardSettingsController::show' => 'blocked',
        'StewardSettingsController::save' => 'blocked',
        'StewardAuditController::index' => 'blocked',
        'StewardBrandingController::show' => 'blocked',
        'StewardBrandingController::save' => 'blocked',
        'StewardBrandingController::image' => 'read',
    ];

    /**
     * Plain view pages of the suite's plugins, registered only when the controller class exists.
     * plugin => [controller class, ['Controller::action' => level]].
     */
    public const SUITE = [
        'Pulse' => ['Kanboard\Plugin\Pulse\Controller\PulseController', [
            'PulseController::index' => 'read',
            'PulseController::project' => 'read',
        ]],
        'Presence' => ['Kanboard\Plugin\Presence\Controller\SettingsController', [
            'SettingsController::show' => 'read',
        ]],
        'Bigboard' => ['Kanboard\Plugin\Bigboard\Controller\Bigboard', [
            'Bigboard::index' => 'read',
            'Bigboard::select' => 'read',
            'BoardAjaxController::check' => 'read',
            'BoardAjaxController::reload' => 'read',
        ]],
        'TimeReport' => ['Kanboard\Plugin\TimeReport\Controller\TimeReportController', [
            'TimeReportController::index' => 'read',
            'TimeReportController::view' => 'read',
        ]],
        'Agents' => ['Kanboard\Plugin\Agents\Controller\AgentController', [
            'AgentController::index' => 'read',
        ]],
    ];

    /** @var array<string,array<string,string>> lower-case plugin => lower-case 'controller::action' => level */
    private array $plugins = [];

    /** @param array<string,array<string,string>> $plugins plugin => ['Controller::action' => level] */
    public function __construct(array $plugins = [])
    {
        foreach ($plugins as $plugin => $actions) {
            $this->plugins[strtolower((string) $plugin)] = self::lower($actions);
        }
        $this->plugins['steward'] = self::lower(self::STEWARD);
    }

    /** Case-insensitive, like PHP method dispatch and Kanboard's ACL. Unknown pairs are blocked. */
    public function classify(string $plugin, string $controller, string $action): string
    {
        static $core = null;
        $core ??= self::lower(self::CORE);
        $key = strtolower($controller.'::'.$action);
        $rows = $plugin === '' ? $core : ($this->plugins[strtolower($plugin)] ?? []);
        return $rows[$key] ?? self::BLOCKED;
    }

    /** @return array<string,array<string,string>> SUITE entries whose plugin is installed. */
    public static function suite(): array
    {
        $present = [];
        foreach (self::SUITE as $plugin => [$class, $actions]) {
            if (class_exists($class)) {
                $present[$plugin] = $actions;
            }
        }
        return $present;
    }

    private static function lower(array $rows): array
    {
        return array_change_key_case($rows, CASE_LOWER);
    }
}
