# Changelog of the Tutorial module

The module's own versions (`$version` in `Config/Module.php`), newest first. Format:
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). How a version is released:
[`docs/tutorials/13-releasing-a-version.md`](../../docs/tutorials/13-releasing-a-version.md).

## 1.0.0

### Changed

- The tutorial module, called `Tasks` up to session 52 (versions 0.1.0 and 0.2.0), is now the
  module `Tutorial` (D-52-01): namespace `Modules\Tutorial`, permissions `tutorial-*`, URLs
  `backend/tutorial/…`, `tutorial`, `tutorial/request` and `api/v1/tutorial/…`, settings
  `Tutorial.*`, tables `tutorial_*`, calendars, widgets and link type `tutorial.*`, files in
  `modules/tutorial`, child theme `tutorial-demo`. A new module: its migrations create the final
  schema (`tutorial_tasks.started_at` included). An installation that had `Tasks` enabled runs
  `php spark modules:uninstall Tasks` before updating; its data is not migrated.

## Earlier versions (as `Tasks`)

### 0.2.0

#### Added

- `tasks.started_at`: when a to-do task left "To do" (set by the form, the board, the
  `complete` operation and the API; cleared when it goes back to "To do"). Shown in the details,
  the export and the API. Migration `AddStartedAtToTasks` (fills it in for to-do tasks already
  started).

### 0.1.0

#### Added

- First version: projects, to-do tasks, the board, widgets, the overdue reminder, calendars,
  the public page and request form, attachments and the API (tutorials 1–12).
