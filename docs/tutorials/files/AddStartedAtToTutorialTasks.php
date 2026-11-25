<?php

declare(strict_types=1);

namespace Modules\Tutorial\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tutorial 13 ("Releasing a new version of a module"): the migration a new version of the
 * Tutorial module would ship to add `tutorial_tasks.started_at` to a released table. Released
 * migrations are never edited, so a schema change comes in a new migration:
 *
 * - `tutorial_tasks.started_at`: when the to-do task left `todo` (`TaskRepository::statusDates()`);
 * - existing rows are filled in once: to-do tasks already `doing` or `done` take their
 *   `updated_at` (the best date known), the others stay empty.
 *
 * `down()` drops the column again (rolling back the version, `modules:uninstall`).
 *
 * The module itself ships with the column already (its 1.0.0 migration creates the final schema,
 * session 52), so this file lives with the tutorial, not in `Database/Migrations/`; the test
 * `testTheVersionMigrationAddsStartedAtFillsItAndGoesBack` runs it against the real table.
 */
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
