# Tutorial 13 — Releasing a new version of a module

The `Tutorial` module goes from **1.0.0 to 1.1.0**: to-do tasks get a `started_at` date (when they
left "To do"). You will change the schema of a released table with a new migration, fill in the
existing rows, bump the version, write the changelog, build (and sign) the ZIP, install it on a
site that runs 1.0.0, and see how sites learn that a new version exists.

> The module in the repository already has the column: it was renamed from `Tasks` to `Tutorial`
> in session 52 (D-52-01) and restarted at 1.0.0 as a new module, whose migrations create the
> final schema. The migration of this tutorial is kept, finished and tested, next to the tutorials
> (`docs/tutorials/files/AddStartedAtToTutorialTasks.php`); the rest of the code it describes is
> in the module.

Finished code:

| Piece | File |
|---|---|
| Schema change | `docs/tutorials/files/AddStartedAtToTutorialTasks.php` (in a real release it goes in the module's `Database/Migrations/`, dated after the others) |
| The rule | `TaskRepository::statusDates()` in `modules/Tutorial/Services/TaskRepository.php`, used by `Controllers/Tasks.php`, `Controllers/Api/Tasks.php` and `setStatus()` (board, `complete`) |
| Shown in | the details and export of the CRUD, the API (`started_at`), texts `Tutorial.fields.started_at` |
| Version | `public string $version` in `modules/Tutorial/Config/Module.php` (`'1.1.0'` in this tutorial) |
| Changelog | `modules/Tutorial/CHANGELOG.md` |

References: [`installing-modules.md`](../installing-modules.md) ("Packages", "Upgrading") and
[`creating-a-module.md`](../creating-a-module.md#9-packaging-as-a-zip-and-signing-it).

## 1. A new migration, never an edited one

Once a version is released, its migrations have run on other sites: editing them changes nothing
there (they are not run again) and makes fresh installs differ from upgraded ones. A schema
change is always a **new migration**, dated after the others:

```php
final class AddStartedAtToTutorialTasks extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tutorial_tasks', [
            'started_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'reminded_on'],
        ]);

        $this->db->table('tutorial_tasks')
            ->whereIn('status', ['doing', 'done'])
            ->set('started_at', 'COALESCE(updated_at, created_at)', false)
            ->update();
    }

    public function down(): void
    {
        $this->forge->dropColumn('tutorial_tasks', 'started_at');
    }
}
```

- The new column is nullable, so existing rows stay valid.
- **Existing data** is filled in once, in the same migration, with the Query Builder (the third
  argument `false` of `set()` lets the value be a column expression; no values are interpolated).
  To-do tasks already started take their last change, the best date known.
- `down()` undoes `up()` exactly: it is run by `modules:uninstall` and by the up/down/up test.
- Keep the migration about the schema and its data. Code that needs the column comes in the same
  release, never before.

## 2. The code that uses it

The rule "which dates follow a status change" now has two dates, and three places change the
status (the CRUD form, the board and `complete`, the API). Put the rule in one place:

```php
public static function statusDates(string $status, array $old, ?Time $now = null): array
{
    $now ??= Time::now();
    $dates  = [];
    $before = (string) ($old['status'] ?? '');

    if ($status === 'todo') {
        $dates['started_at'] = null;
    } elseif (($old['started_at'] ?? null) === null) {
        $dates['started_at'] = $now->toDateTimeString();
    }

    if ($status !== 'done') {
        $dates['completed_at'] = null;
    } elseif ($before !== 'done') {
        $dates['completed_at'] = $now->toDateTimeString();
    }

    return $dates;
}
```

and call it from each of them, e.g. in the CRUD's `beforeSave()`:

```php
if (array_key_exists('status', $data) && is_string($data['status'])) {
    $data = TaskRepository::statusDates($data['status'], $old) + $data;
}
```

The CRUD shows the column read-only (`fieldType('started_at', 'readonly')`, in `showFields()` and
`exportColumns()`), the API lists it in `fields()` but not in `writeFields()`, and the three
language files get `fields.started_at`. Update the demo seeder so new demo data has the date too.

## 3. The version and the changelog

The manifest's `$version` is a semantic version. 1.1.0 adds a feature without breaking anything
(a major version would be needed for removed fields, renamed permissions or URLs):

```php
public string $version = '1.1.0';
```

`modules/Tutorial/CHANGELOG.md` says what changed, newest first:

```markdown
## 1.1.0

### Added

- `tutorial_tasks.started_at`: when a to-do task left "To do" (…). Migration `AddStartedAtToTutorialTasks`
  (fills it in for to-do tasks already started).
```

On a development site, `php spark modules:sync` sees that the code is newer than the installed
version, runs the new migration and records the version:

```
Modules\Tutorial: 1 migrations run.
Updated Tutorial from 1.0.0 to 1.1.0.
```

`php spark modules:list` shows code version and installed version side by side ("needs sync"
until you run it).

If the new version added a **permission**, remember that `defaultGrants` are applied only when a
module is first installed: say in the changelog which permission administrators must grant in
the matrix (or rely on a wildcard grant such as `tutorial-tasks.*`).

## 4. Build the ZIP

On a development copy, one command builds, validates and (optionally) signs the package — the
same checks the installer will run, plus `Tutorial-1.1.0.zip.sha256`
([`devtools.md`](../devtools.md#building-and-signing-packages); also Devtools › Packages):

```bash
php spark modules:package Tutorial                          # writable/devtools/packages/Tutorial-1.1.0.zip
php spark modules:package Tutorial --sign=acme.key          # + Tutorial-1.1.0.zip.minisig (step 5)
```

By hand, a package is a ZIP with **one top folder** named like the module. Build it from a clean
checkout of the module, without dot files:

```bash
cd modules
zip -r Tutorial-1.1.0.zip Tutorial -x '*/.*'
unzip -l Tutorial-1.1.0.zip | head        # every entry starts with Tutorial/
```

Only the extensions of `packages.extensions` are accepted (PHP, JSON, Markdown, CSS, JS, images,
fonts…): `CLAUDE.md` and `CHANGELOG.md` travel with the module; `.gitignore`, `.htaccess` or a
`phtml` file would refuse the package. The manifest must use literal values: it is read without
being run.

## 5. Sign it

Sites that set `packages.requireSignature = true` (recommended in production; `security:check`
warns otherwise) accept only packages signed by a publisher they trust:

```bash
minisign -G -p acme.pub -s acme.key                                  # once; keep acme.key secret
minisign -S -s acme.key -m Tutorial-1.1.0.zip -t "Tutorial 1.1.0"          # writes Tutorial-1.1.0.zip.minisig
```

`php spark modules:package Tutorial --sign=acme.key` does the same and checks the signature
(the password is asked without echo). Publish `Tutorial-1.1.0.zip` with `Tutorial-1.1.0.zip.minisig` next to it. The site adds the public key
to `$publicKeys` in `app/Config/Packages.php`
([`installing-modules.md`, "Signatures"](../installing-modules.md#signatures)).

## 6. Install it on a site running 1.0.0

```bash
php spark modules:install Tutorial-1.1.0.zip
```

The installer validates the package (size, zip-slip and zip-bomb checks, extensions, the manifest
read without running it, every PHP file syntax-checked, the signature), shows what it is about to
install, makes a backup, turns on maintenance mode, swaps the folder (keeping the old one aside
until it succeeds) and runs `modules:sync` in a new process:

```
Package: module Tutorial 1.1.0
  Author:          kpskeletor
  Signed:          no
  Files:           58 (245,827 B)
  Requires:        Core ^1.0, Auth ^0.2, Dashboard ^0.1, Files ^0.1
  …
Running modules:sync…
  Modules\Tutorial: 1 migrations run.
  Updated Tutorial from 1.0.0 to 1.1.0.
Tutorial 1.1.0 installed.
```

The module keeps its enabled state, data, settings and grants. On the demo data of 1.0.0, the
to-do tasks "doing" and "done" now have a `started_at`; the ones "to do" do not.

- A **higher** version is an upgrade; the same version or a lower one needs `--force`; a package
  of another `$author` is refused.
- Without console access, an administrator uploads the ZIP in **Modules** and the operation runs
  from the cron line (`modules:install --pending`); the web server never installs code.
- On a production site, never pass `--no-backup`.

## 7. The update notice

Sites learn about new versions from the **Updates** module when the manifest says where releases
are published:

```php
public ?string $repository = 'github:acme/tutorial-module';
```

Each release is a tag (`v1.1.0` or `1.1.0`) with the ZIP (and its `.minisig`) attached. The daily
scheduled task *Check for updates* (or **Check now**, or `php spark updates:check`) compares the
newest release with the installed version through the `OutboundClient`; administrators
(`updates.view`) see it on the Updates page and its dashboard widget and receive one
*New version available* notification. Installing it is still `modules:install`
([`updates.md`](../updates.md)). The tutorial module itself declares no repository, so it never
calls out.

## Tests

`modules/Tutorial/Tests/TutorialModuleTest.php`:

- `testTheVersionMigrationAddsStartedAtFillsItAndGoesBack`: this tutorial's migration on the real
  table: `down()` drops the column and keeps the rows; `up()` adds it and fills it only for
  started to-do tasks;
- `testStartedAtAndCompletedAtFollowTheStatus`: `statusDates()` for every transition, and the
  CRUD form and `complete` operation using it;
- `testMigrationsGoDownAndUpAgain`: the module's six migrations, down in reverse order and up again.

`modules/Tutorial/Tests/TutorialApiTest.php` `testWritesFiltersIncludesAndTheCompleteOperation` checks
the same dates through the API. The package installer itself (validation, upgrade, rollback) is
tested in `modules/Core/Tests/PackagesTest.php`.

```bash
vendor/bin/phpunit modules/Tutorial/Tests/TutorialModuleTest.php --filter 'Version|StartedAt|Migrations'
```

Next: [tutorial 14](14-writing-tests.md), how the tests of every tutorial are written.
