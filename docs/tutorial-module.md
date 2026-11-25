# Tutorial module

The `Tutorial` module (called `Tasks` until session 52, D-52-01) is a small manager of **to-do tasks**: projects, to-do tasks, assignees, due
dates and labels. It holds the finished code of every [tutorial](tutorials/README.md), so each
feature of the skeleton has a small, working example. Its own notes, with a
table "tutorial → files", are in `modules/Tutorial/CLAUDE.md`.

> Wording (D-00-56): "to-do task" is a record of this module; "scheduled task" is a job of the
> scheduler. The module's `Tasks/` folder holds its **scheduled tasks**, as in every module.

## Trying it

The module is installed **disabled** (`$enabledByDefault = false`, D-32-01):

```bash
php spark modules:enable Tutorial        # or Modules › Enable
php spark modules:demo Auth           # demo users (if not loaded yet)
php spark modules:demo Tutorial          # two projects, eight to-do tasks, a request
```

Then: `backend/tutorial` (board), `backend/tutorial/tasks`, `backend/tutorial/projects`,
`backend/tutorial/requests`, the dashboard widgets, the calendar (`backend/calendar`, "To-do task due
dates" and "Project deadlines"), the public page `tutorial`, the form `tutorial/request`, and the API
(`backend/api/docs`).

## Coming from the `Tasks` module (before session 52)

The module was called `Tasks` (permissions `tasks-*`, back-office URLs under `tasks/`, tables `tasks`,
`task_*`) up to version 0.2.0. An installation that enabled it runs `php spark modules:uninstall
Tasks` **before** updating, then `php spark modules:enable Tutorial` (and `modules:demo
Tutorial`); its data is not migrated. If the step was forgotten, `modules:sync` and the Modules
screen say what to do ([`updates.md`](updates.md#before-updating-steps-a-release-asks-for)).

## What it contains

| Piece | Where |
|---|---|
| Version | 1.0.0 ([`CHANGELOG.md`](../modules/Tutorial/CHANGELOG.md); a new module in session 52, the versions 0.1.0–0.2.0 were the `Tasks` module) |
| Tables | `tutorial_projects`, `tutorial_project_translations`, `tutorial_tasks`, `tutorial_task_assignees`, `tutorial_task_attachments`, `tutorial_task_requests` (one migration each, the final schema; tutorial 13 shows how `tutorial_tasks.started_at` would be added in a new version) |
| CRUDs (kpcrudy) | `tutorial-tasks` (`backend/tutorial/tasks`), `tutorial-projects` (`backend/tutorial/projects`), `tutorial-requests` (`backend/tutorial/requests`) |
| Board | `tutorial-board.view`: `backend/tutorial`, `GET backend/tutorial/board/data`, `POST backend/tutorial/board/{code}/move`; `Assets/js/board.js` |
| Widgets | `tutorial.pending` (server, `tutorial-board.view`), `tutorial.activity` (asynchronous SVG chart, `tutorial-tasks.list`) |
| Scheduled task | `OverdueReminder`: daily at 07:30, `withoutOverlapping()`, once a day per to-do task |
| Notifications | `TaskAssigned` (new assignees), `TaskOverdue` (reminder), `TaskRequested` (reviewers) |
| Calendars | `tutorial.due` (push: due dates of open to-do tasks, assignees and creator), `tutorial.projects` (pull provider: project deadlines, `tutorial-projects.list`, hidden by default) |
| Public site | `tutorial`, `tutorial/project/{slug}` (public to-do tasks only), menu link type `tutorial.project`, form `tutorial/request` |
| Files | attachments on the private disk, `modules/tutorial`, `AttachmentPolicy` (`FileAccessPolicy`) |
| API (kpapy) | `api/v1/tutorial/tasks` (`tutorial-tasks`, operation `complete`), `api/v1/tutorial/projects` (`tutorial-projects`) |
| Settings | `Tutorial.reminderGraceDays` (0–30), `Tutorial.publicRequests` (on) |
| Child theme | `themes/tutorial-demo` (child of `default`): colour, logo, CSS and an override of the public page |

## Permissions

`tutorial-<resource>.<operation>`, generated from the manifest:

- `tutorial-tasks.*`: `list`, `show`, `add`, `edit`, `delete`, `export`, `trash`, `restore`,
  `purge`, `complete` (operation and bulk action), `estimates` (see and write the estimate);
- `tutorial-projects.*`: `list`, `show`, `add`, `edit`, `delete`, `export`;
- `tutorial-board.view`;
- `tutorial-requests.*`: `list`, `show`, `delete`, `export`, `accept`, `reject`.

Default grants: `admin` everything; `editor` to-do tasks list/show/add/edit/complete, projects
list/show and the board; `user` and `superadmin` the board.

## Rules

- **Who sees a to-do task on the board and in the widget:** with `tutorial-tasks.list`, every one;
  otherwise those assigned to the user. **Who moves it:** `tutorial-tasks.edit` or an assignee; a
  to-do task the user cannot see answers 404, one they see but may not move 403 (logged as
  `move_refused`).
- **Public:** only `visibility = public`, not in the trash, in an active project or none.
- **Attachments:** files of a public to-do task are public; of a private one, for
  `tutorial-tasks.show`, its creator and its assignees; a file not attached yet only for its
  uploader. Refused is a 404.
- **Assignees:** new assignees are notified (never the user who made the change); a user who loses
  the board (`privilegesChanged`) is removed from the open to-do tasks.
- **Request form:** always a captcha (Auth's local captcha, image at `tutorial/request/captcha`),
  `ratelimit:forms`, validation, a `TaskRequested` notification to `tutorial-requests.list`, a
  confirmation email in the visitor's language (Core `MailQueue`), and 404 while
  `Tutorial.publicRequests` is off. Accepting a request creates a private to-do task.

## Tests

`vendor/bin/phpunit modules/Tutorial`: `TutorialModuleTest` (manifest, CRUD permissions with forged
POSTs, `complete`, hidden estimate, `afterWrite`, translated projects, migrations up/down/up),
`BoardTest`, `WidgetsAndReminderTest`, `PublicSiteTest`, `AttachmentsTest`,
`CalendarAndEventsTest`, `TutorialApiTest`, `ChildThemeTest`.
