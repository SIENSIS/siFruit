# System information and error logs

Session 53, D-53-01. Two screens in *Configuració › Sistema* (`group.system`) for production
support, so nobody needs SSH to answer "which version is this?" or "what was that error?".

| Screen | URL (under the back-office prefix) | Permission | Filters |
|---|---|---|---|
| System information | `backend/system` (route `system`) | `system.info` (superadmin, admin) | `can:system.info` |
| Download the report | `backend/system/report.txt` | `system.info` | `can:system.info` (logged) |
| Refresh | `POST backend/system/refresh` | `system.info` | `can:system.info`, `ratelimit:forms` |
| Error logs | `backend/system/logs` (route `system-logs`) | `system.logs` (granted to nobody: superadmins only) | `can:system.logs`, `reauth`, `ratelimit:widgets` |
| One log file | `backend/system/logs/<YYYY-MM-DD>` | `system.logs` | same |
| Unused files | `backend/system/unused-files` (route `system-unused-files`) | `system.files` (superadmin, admin; D-55-01) | `can:system.files` |
| Scan now | `POST backend/system/unused-files/scan` | `system.files` | `can:system.files`, `ratelimit:forms` |
| Delete | `POST backend/system/unused-files/delete` | `system.files` | `can:system.files`, `reauth`, `ratelimit:forms` |

The Core resource `system` (no CRUD operations, extras `info`, `logs` and `files`) generates the
permissions; `system.logs` can be granted in the permission matrix when an administrator needs it.

## System information

### One report, two outputs

`Modules\Core\System\SystemReport::build()` computes the report once; `php spark about` prints
it as tables, `php spark about --plain` prints its plain text, and the screen shows the same
sections (a test compares them). The report is made of `ReportSection`s (key, title, column
headers, rows, an optional link) of `ReportRow`s (cells, a status `ok` / `warning` / `error` or
none, a short note explaining a problem).

| Section | Rows |
|---|---|
| `application` | name, Core version, CodeIgniter, PHP and SAPI, environment (warning when not `production`), base URL, back-office prefix, installed at, `.env`, `encryption.key` and `app.maskKey` (**set / not set** only), time zone, languages, cache handler, `writable/` writable |
| `php` | the extensions of `composer.json` (`require` `ext-*`: missing = error; `suggest` `ext-*`: missing = warning; `Modules\Core\System\PhpRequirements`), `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, OPcache (and its memory), `display_errors` (warning when on in production) |
| `storage` | free disk space of `writable/` (warning under 1 GB), sizes of `writable/logs`, `cache`, `backups`, `uploads`, `storage` (at most 20,000 files counted per folder; links not followed); session 54 (D-54-01, from `stored_files`, cached 2 minutes): the stored files per disk and per module folder (`public:modules/news`…), the image variants, **Images to optimise** (images above `Core.imageMaxDimension` and public images without variants: **ok** when none; **warning** "the scheduled task will process them" with its next run; **warning** "run `php spark storage:optimize` or enable the task" when *Optimise stored images* is inactive or has not run for 14 days) and the files the optimiser could not process (warning, with their paths) |
| `database` | driver, version, database name (a SQLite file relative to the project), tables, pending migrations per module (warning: run `modules:sync`) |
| `jobs` | last `schedule:run` (the newest `task_runs` row of the cron or the poor man's cron; **warning when an active scheduled task is more than 60 minutes overdue**), scheduled tasks whose last run failed, e-mails and push notifications waiting and failed |
| `assets` | the published copies (`AssetPublisher::check()`, what `assets:publish --check` checks, nothing written) |
| `modules` | name, version, installed version, enabled, development only; a link to Modules |
| `backup` (Backup module) | the last successful backup and its age (warning when scheduled backups are on and it is older than 8 days) |

Nothing secret is ever shown: no key values, no environment variables, no `$_SERVER`, no path
outside the project root. The folder sizes and the assets check are cached for five minutes
(`SystemReport::CACHE_KEY`); **Refresh** drops that cache. Raw `phpinfo()` is never shown in
production (it prints environment variables and sometimes secrets): it is only in Devtools.

The screen marks warnings and errors with `ui_badge()`, shows a summary, and with `system.logs`
the number of `error`/`critical` entries of today's log (a link to Error logs). **Copy report
for support** copies the plain text (the same as `php spark about --plain`, shown in a folded
text area) and **Download** saves it as `system-report-YYYY-MM-DD.txt`; the download is written
to the activity log (`Core` / `system_report_downloaded`). Opening the screen is not logged.

### Adding rows from a module (`systemReport`)

A module adds its own section, or rows to a Core section, from the Core event `systemReport`,
never by changing the Core (like `securityChecks`):

```php
// modules/Library/Config/Events.php
Events::on(\Modules\Core\System\SystemReport::EVENT, static function (\Modules\Core\System\SystemReport $report): void {
    if ((new \Modules\Core\Registry\ModuleState())->isEnabled('Library')) {
        $report->section('library', lang('Library.system.title'))
            ->add(lang('Library.system.loans'), (string) $open, $late > 0 ? ReportRow::WARNING : ReportRow::OK, lang('Library.system.late'));
    }
});
```

Rows are plain text (escaped on the screen); never put a secret, a personal datum or a path
outside the project in them. Keep the listener cheap: it runs on every visit and every `about`.

## Error logs (read only)

- Lists the files of `writable/logs` newest first (date, name, size). Only regular files directly
  in the folder whose names match CodeIgniter's pattern `log-YYYY-MM-DD.log` exist for it; a file
  is opened **by its date** (`backend/system/logs/2026-11-22`), never by a path from the request
  (a valid calendar date, no links, `realpath` inside the folder; `Modules\Core\System\LogFiles`).
- Shows the entries newest first (date, level, message, the first 30 trace lines folded), 50 per
  page, filterable by PSR-3 level and by text. A page reads at most 256 KB **backwards from a
  byte offset** (`?before=<offset>`), so a 100 MB log never loads into memory; "Older entries"
  continues where the page stopped.
- **No download and no delete** (D-53-01).
- Every message and trace line goes through `Modules\Core\System\LogRedactor` before it is shown
  (and before the text filter compares, so searching cannot reveal a masked value), then it is
  escaped. It masks with `[masked]`:
  - the literal values of the configuration's secrets: `encryption.key` (with and without its
    `hex2bin:`/`base64:` prefix), `app.maskKey`, the database passwords, `email.SMTPPass`, and every
    environment variable whose name looks like a secret;
  - `Bearer` / `Basic` / `Token` / `Digest` credentials;
  - the values of keys named like `password`, `pass`, `pwd`, `secret`, `token`, `key`, `api_key`,
    `authorization`, `cookie`, `session_id`, `signature`, `credential` (`key=value`, `key: value`,
    `"key":"value"`, `'key' => 'value'`);
  - long random-looking strings (24+ letters and digits) after `=`.
- Opening a file is written to the activity log (`Core` / `system_log_opened`, the file name).
- The screen needs re-authentication (`reauth`) and is rate limited (`widgets`: 120 a minute).

## Unused files

Session 55, D-55-01 (the rules are in [`storage.md`](storage.md), "Unused files"). The screen
lists the files of the module folders that no module uses and that are older than the grace
period, from the last scan (cached one hour; **Scan now** scans again):

- the grace period, the date of the last scan and whether the automatic deletion is on (and after
  how many days), with a reminder to make a backup first (a link to Backups for holders of
  `backup.list` while the Backup module is enabled);
- the folders **not checked** (no enabled module declares them, or their module is disabled),
  with their file count and size: never listed, never deleted;
- the files, 50 per page, filterable by module: a preview of public images (`ui_image()`, which
  loads the 400 px variant; private files show an icon, never their contents), the original name
  and path, the module and folder, the size with the variants, the upload date; a checkbox per
  row, **Delete selected** and **Delete all listed** (every file of the scan with the module
  filter, at most 1,000 per click).

Deleting needs re-authentication, CSRF and a confirmation (`data-confirm`; `core/ui/unused-files`
adds "select all" and puts the count and size of the selection in the confirmation). The server
checks every posted id again (forged ids, files outside the module spaces, files used since the
scan, files younger than the grace period, garbage are skipped and counted) and the flash says
"deleted N (size), skipped M". One activity entry per deletion. Without JavaScript the form works
as is.

System information shows a row **Unused files** in the Storage section (count and size of the
cached scan, scanning once when there is none; a warning when there are some, with the age and the
automatic deletion state in the note) and **Folders not checked**; holders of `system.files` get
a link to the screen under the section. **Refresh** drops the cached scan too.

## Code

| Class | Role |
|---|---|
| `Modules\Core\System\SystemReport` | the report: sections, `build()`, `plain()`, `counts()`, `refresh()`, event `systemReport` |
| `Modules\Core\System\ReportSection`, `ReportRow` | a section and a row |
| `Modules\Core\System\SystemEnvironment` | what the report reads from PHP and the server (faked in tests) |
| `Modules\Core\System\PhpRequirements` | required and recommended extensions from `composer.json` |
| `Modules\Core\System\LogFiles` | the log files, bounded reads, filters |
| `Modules\Core\System\LogRedactor` | masking |
| `Modules\Core\Controllers\System`, `SystemLogs` | the screens; views `modules/Core/Views/system/{index,logs,log}.php` |
| `Modules\Core\Commands\About` | `php spark about [--plain]` |
| `Modules\Backup\Services\BackupSystemReport` | the Backup section |

Tests: `modules/Core/Tests/SystemInfoTest.php`, `SystemLogsTest.php`.
