# Tutorial module

> Read this file first when you resume work on this module, and keep it current. Project rules:
> root `CLAUDE.md`; steps: `docs/creating-a-module.md`; run `/check-module Tutorial` before calling
> it done.

**Purpose:** a small manager of to-do tasks (projects, to-do tasks, assignees, due dates, labels)
that holds **the finished code of every tutorial** in `docs/tutorials/` (index: `docs/tutorials/README.md`).
It is installed **disabled** (`$enabledByDefault = false`, D-32-01): enable it in Modules (or
`php spark modules:enable Tutorial`) and load `php spark modules:demo Tutorial`. Full description:
`docs/tutorial-module.md`.

**Name (D-52-01):** called `Tasks` up to session 52 (versions 0.1.0–0.2.0); renamed `Tutorial` so the
name `Tasks` stays free and nobody mistakes it for the Core's scheduled tasks. The example inside
is still a to-do list: resources `tasks`, `projects`, `board`, `requests`.

**Wording (D-00-56):** "to-do task" for this module's records, "scheduled task" for the
scheduler's jobs. Never just "task" in texts or docs. The folder `Tasks/` holds the module's
**scheduled tasks** (`Modules\Tutorial\Tasks\…`).

## Resources and permissions

- `tutorial-tasks` (crud − `import`; extras `complete` (operation and bulk action), `estimates`
  (see/write `estimate_hours`)): every to-do task, `backend/tutorial/tasks`.
- `tutorial-projects` (crud − `import`, trash): projects (translated name/description), `backend/tutorial/projects`.
- `tutorial-board` (page: `view`): the board `backend/tutorial` (+ `board/data`, `board/{code}/move`).
- `tutorial-requests` (crud − `add`, `edit`, `import`, trash; extras `accept`, `reject`): requests
  from the public form, `backend/tutorial/requests`.
- API (same resources, same permissions): `api/v1/tutorial/tasks` (`tutorial-tasks`, operation
  `complete`), `api/v1/tutorial/projects` (`tutorial-projects`).
- Grants: admin everything; editor tasks list/show/add/edit/complete, projects list/show, board;
  user and superadmin board.
- Settings `Tutorial.reminderGraceDays` (0–30), `Tutorial.publicRequests` (bool). Menu "To-do tasks"
  (board) with "All to-do tasks", "Projects", "Requests". Widgets `tutorial.pending`,
  `tutorial.activity`. Scheduled task `Tasks/OverdueReminder.php`. Notifications `TaskAssigned`,
  `TaskOverdue`, `TaskRequested`. Link type `tutorial.project`. Calendars `tutorial.due` (push) and
  `tutorial.projects` (pull, `tutorial-projects.list`, hidden by default).
- Version 1.0.0 (`CHANGELOG.md`). Tables (one migration each, the final schema): `tutorial_projects`,
  `tutorial_project_translations`, `tutorial_tasks` (with `started_at`), `tutorial_task_assignees`,
  `tutorial_task_attachments`, `tutorial_task_requests`. Files: `modules/tutorial` (private disk).

## Where each tutorial lives

| Tutorial | Files |
|---|---|
| 1 Module + CRUD with relations, custom operation | `Config/Module.php`, `Database/Migrations/*`, `Controllers/Tasks.php` (`setRelationNN` assignees, project select, filters, `addOperation('complete')`), `Language/*` |
| 2 Translated field, multiselect, hideFields, afterWrite, bulk action | `Controllers/Projects.php` (`setTranslations`), `Controllers/Tasks.php` (`labels` multiselect, `hideFields(['estimate_hours'])`, `afterWrite`, `addBulkAction('complete')`) |
| 3 View with JavaScript | `Controllers/Board.php`, `Views/board/*`, `Assets/js/board.js`, `Assets/css/tutorial.css` |
| 4 Widgets | `Widgets/MyPendingTasks.php` (server), `Widgets/TaskActivityChart.php` (async), `Views/widgets/*` |
| 5 Public page + menu link | `Controllers/PublicTasks.php`, `Views/public/index.php`, `Menu/ProjectLink.php` |
| 6 Talking to other modules | `Notifications/TaskAssigned.php` + `Services/Assignees.php`, `Calendar/DueDates.php` (push), `Calendar/ProjectDeadlines.php` (pull), `Config/Events.php` (`privilegesChanged`) |
| 7 Scheduled task | `Tasks/OverdueReminder.php`, `Notifications/TaskOverdue.php` |
| 8 Setting | `$settings` in the manifest; read in `OverdueReminder`, `PublicTasks`, `RequestForm` |
| 9 Child theme | `themes/tutorial-demo/` (child of `default`, overrides `Tutorial/public/index.php`) |
| 10 Uploads | `Storage/AttachmentUploads.php` (picker, private disk), `Storage/AttachmentPolicy.php` (`FileAccessPolicy`, registered in `Config/Events.php`) |
| 11 JSON API | `Controllers/Api/Tasks.php`, `Controllers/Api/Projects.php`, `$api`, `Config/Routes.php` |
| 12 Public form with email | `Controllers/RequestForm.php`, `Views/public/request.php`, `Services/TaskRequests.php`, `Views/emails/request_received.php`, `Controllers/Requests.php` |
| 13 Releasing a version | `docs/tutorials/files/AddStartedAtToTutorialTasks.php` (the example migration of a new version: `tutorial_tasks.started_at`, filled in for started to-do tasks; the module's own migration already has the column), `TaskRepository::statusDates()`, `$version`, `CHANGELOG.md` |
| 14 Tests | `Tests/*` |

## Main pieces

- `Services/TaskRepository.php`: every read and the shared rules (who sees a to-do task on the
  board: `tutorial-tasks.list` or assignee; who moves it: `tutorial-tasks.edit` or assignee; what is
  public), `setStatus()` and `statusDates()` (keep `started_at`/`completed_at` and the calendar in step).
- `Services/Assignees.php` (notify new assignees, drop users without board access),
  `Services/TaskRequests.php` (public form: store, notify, confirmation email through the Core's
  `MailQueue` in the visitor's language; accept/reject).
- The public form always asks for Auth's captcha (its image at `tutorial/request/captcha`, so it
  works while `Auth.captcha` is off) and has `ratelimit:forms`.

## Decisions

D-32-01 (`$enabledByDefault` manifest key, decided by the owner as built), D-32-02 … D-32-05 (accepted;
`docs/decisions.md`); D-00-56 (naming), D-00-32 (`themes/tutorial-demo`, `tasks-demo` until session 52); D-52-01 (the rename to `Tutorial`), D-52-02 … (session 52, `docs/decisions.md`).

Session 37 (D-37-01): translated texts use the site's languages and require its default language through `Modules\Core\Content\ContentTranslations` (`locales()`, `crudOptions()`, `defaultText()`…); never write a language code (`tests/unit/I18n/ContentLanguagesTest.php`). Tests `Tests/ContentLocalesTest.php`, `Tests/ContentLocalesApiTest.php` (`Modules\Core\Tests\ContentLocalesTrait`).

## Unused files (session 55)

`Storage\AttachmentReferences` (manifest `$fileReferences`, D-55-01, `docs/storage.md` "Unused files") gives
the files the module uses: task attachments and descriptions; folder `modules/tutorial`. Keep it complete when the module starts storing a file
somewhere else: a file it forgets can be listed (and deleted) as unused.

## Open tasks

- A change to a tutorial's code must update its text in `docs/tutorials/` (excerpts are copied
  from the code; `tests/unit/Docs/TutorialsTest.php` checks the files and test names they cite).
- Demo data removal leaves the published calendar events of the demo to-do tasks until
  `php spark calendar:resync Tutorial`.

## How to test

```bash
vendor/bin/phpunit modules/Tutorial      # base Tests/TutorialTestCase.php (+ TutorialFixtures)
php spark permissions:check
```

By hand: `php spark modules:enable Tutorial`, `php spark modules:demo Auth`, `php spark modules:demo
Tutorial`, then `backend/tutorial` (board), `backend/tutorial/tasks`, `tutorial` (public page),
`tutorial/request` (form), `backend/api/docs` (API documentation page).
