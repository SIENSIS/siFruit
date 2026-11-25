<?php

declare(strict_types=1);

namespace Modules\Tutorial\Config;

use Modules\Core\Config\ModuleManifest;

/**
 * Manifest of the Tutorial module: a small manager of to-do tasks (projects, to-do tasks,
 * assignees, due dates, labels) holding the finished code of every tutorial (docs/tutorials/,
 * `modules/Tutorial/CLAUDE.md` maps each tutorial to its files). Installed **disabled**
 * (`$enabledByDefault`, D-32-01): enable it in Modules (and load `php spark modules:demo Tutorial`).
 *
 * Wording (D-00-56): "to-do task" for the records of this module, "scheduled task" for the
 * scheduler's jobs (this module's `Tasks/` folder).
 *
 * Resources (permission names `tutorial-<resource>.<operation>`):
 * - `tasks` (kpcrudy, tutorials 1–2): the to-do tasks; extras `complete` (operation and bulk
 *   action) and `estimates` (see and write the estimate, `hideFields()`);
 * - `projects` (kpcrudy): the projects (translated name and description);
 * - `board` (page, tutorial 3): the board; every group;
 * - `requests` (kpcrudy, tutorial 12): requests sent from the public form; extras `accept`
 *   (creates a to-do task) and `reject`.
 * The API (tutorial 11) reuses `tutorial-tasks` and `tutorial-projects` (same permissions as the CRUDs).
 */
final class Module extends ModuleManifest
{
    public string $name        = 'Tutorial';
    public string $version     = '1.0.0';
    public string $icon        = 'bi bi-kanban';
    public string $description = 'Tutorial.module.description';
    public string $author      = 'kpskeletor';
    public bool $enabledByDefault = false;
    public array $requires     = ['Core' => '^1.0', 'Auth' => '^0.2', 'Dashboard' => '^0.1', 'Files' => '^0.1'];

    public array $resources = [
        'tasks' => [
            'label'  => 'Tutorial.res.tasks',
            'type'   => 'crud',
            'except' => ['import'],
            'extra'  => [
                'complete'  => 'Tutorial.perm.complete',
                'estimates' => 'Tutorial.perm.estimates',
            ],
        ],
        'projects' => [
            'label'  => 'Tutorial.res.projects',
            'type'   => 'crud',
            'except' => ['import', 'trash', 'restore', 'purge'],
        ],
        'board' => [
            'label' => 'Tutorial.res.board',
            'type'  => 'page',
        ],
        'requests' => [
            'label'  => 'Tutorial.res.requests',
            'type'   => 'crud',
            'except' => ['add', 'edit', 'import', 'trash', 'restore', 'purge'],
            'extra'  => [
                'accept' => 'Tutorial.perm.accept',
                'reject' => 'Tutorial.perm.reject',
            ],
        ],
    ];

    public array $defaultGrants = [
        'superadmin' => ['tutorial-board.*'],
        'admin'      => ['tutorial-tasks.*', 'tutorial-projects.*', 'tutorial-board.*', 'tutorial-requests.*'],
        'editor'     => [
            'tutorial-tasks.list', 'tutorial-tasks.show', 'tutorial-tasks.add', 'tutorial-tasks.edit', 'tutorial-tasks.complete',
            'tutorial-projects.list', 'tutorial-projects.show',
            'tutorial-board.*',
        ],
        'user' => ['tutorial-board.*'],
    ];

    public array $menu = [
        ['resource' => 'board', 'label' => 'Tutorial.menu.board', 'icon' => 'bi bi-kanban', 'order' => 40],
        ['resource' => 'tasks', 'label' => 'Tutorial.menu.tasks', 'icon' => 'bi bi-check2-square', 'order' => 1, 'parent' => 'board'],
        ['resource' => 'projects', 'label' => 'Tutorial.menu.projects', 'icon' => 'bi bi-folder2', 'order' => 2, 'parent' => 'board'],
        ['resource' => 'requests', 'label' => 'Tutorial.menu.requests', 'icon' => 'bi bi-inbox', 'order' => 3, 'parent' => 'board'],
    ];

    /** Link type of the public menu editor (tutorial 5). */
    public array $menuLinks = [\Modules\Tutorial\Menu\ProjectLink::class];

    /** Settings (tutorial 8): Settings › Tutorial module; read with `setting('Tutorial.<key>')`. */
    public array $settings = [
        'reminderGraceDays' => [
            'type'    => 'int',
            'default' => 0,
            'rules'   => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[30]',
            'label'   => 'Tutorial.settings.reminderGraceDays',
            'help'    => 'Tutorial.settings.reminderGraceDaysHelp',
            'group'   => 'reminders',
        ],
        'publicRequests' => [
            'type'    => 'bool',
            'default' => true,
            'label'   => 'Tutorial.settings.publicRequests',
            'help'    => 'Tutorial.settings.publicRequestsHelp',
            'group'   => 'public',
        ],
    ];

    /** Dashboard widgets (tutorial 4): one server-side, one asynchronous. */
    public array $widgets = [
        \Modules\Tutorial\Widgets\MyPendingTasks::class,
        \Modules\Tutorial\Widgets\TaskActivityChart::class,
    ];

    /** Scheduled tasks (tutorial 7). */
    public array $tasks = [\Modules\Tutorial\Tasks\OverdueReminder::class];

    /** Notification types (tutorials 6, 7 and 12). */
    public array $notifications = [
        \Modules\Tutorial\Notifications\TaskAssigned::class,
        \Modules\Tutorial\Notifications\TaskOverdue::class,
        \Modules\Tutorial\Notifications\TaskRequested::class,
    ];

    /** Attachments: files of the Storage service in `modules/tutorial` (private disk, tutorial 10). */
    public array $backup = ['files' => ['modules/tutorial']];

    /** @var list<string> Files the module uses, for the unused-files scan (D-55-01, docs/storage.md) */
    public array $fileReferences = [
        \Modules\Tutorial\Storage\AttachmentReferences::class,
    ];

    /** No extra Content-Security-Policy sources. */
    public array $csp = [];

    /** No outbound requests. */
    public array $outboundHosts = [];

    /**
     * Calendars (tutorial 6): `tutorial.due` (push: the due dates of open to-do tasks, seen by
     * their assignees and creator) and `tutorial.projects` (pull: the projects' deadlines, a
     * `CalendarProvider`, for users who may list the projects).
     */
    public array $calendars = [
        'due'      => ['label' => 'Tutorial.cal.due', 'color' => '#fd7e14', 'source' => \Modules\Tutorial\Calendar\DueDates::class],
        'projects' => ['label' => 'Tutorial.cal.projects', 'color' => '#6610f2', 'permission' => 'tutorial-projects.list', 'hidden' => true],
    ];

    public array $calendarProviders = [\Modules\Tutorial\Calendar\ProjectDeadlines::class];

    /** API resources (tutorial 11, kpapy). */
    public array $api = [
        'tasks'    => ['path' => 'tutorial/tasks', 'handler' => '\Modules\Tutorial\Controllers\Api\Tasks::index'],
        'projects' => ['path' => 'tutorial/projects', 'handler' => '\Modules\Tutorial\Controllers\Api\Projects::index'],
    ];
}
