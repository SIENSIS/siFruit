<?php

declare(strict_types=1);

namespace Modules\Tutorial\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Table `tutorial_tasks`: one row per to-do task (tutorial 1).
 *
 * - `project_id`: the project (1-N relation); the assignees are in `tutorial_task_assignees` (N-N);
 * - `status`: `todo`, `doing` or `done` (the board's columns, tutorial 3); `completed_at` is set
 *   by the `complete` operation and when a to-do task reaches `done`;
 * - `priority`: `low`, `normal` or `high`;
 * - `labels`: a kpcrudy `multiselect` stored as JSON (tutorial 2);
 * - `estimate_hours`: shown only with `tutorial-tasks.estimates` (`hideFields()`, tutorial 2);
 * - `visibility`: `private` (the back office) or `public` (the public page, tutorial 5; also the
 *   rule of its attachments, tutorial 10);
 * - `attachments`: up to five file keys of the Storage service (private disk, folder
 *   `modules/tutorial`), comma separated as the file picker writes them; mirrored in
 *   `tutorial_task_attachments` for the `FileAccessPolicy` (tutorial 10);
 * - `reminded_on`: the day the overdue reminder was last sent (tutorial 7: one reminder a day);
 * - `started_at`: when the to-do task left `todo` (`TaskRepository::statusDates()`; tutorial 13
 *   shows how a column like this one is added in a new version).
 */
final class CreateTutorialTasks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'project_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'title'          => ['type' => 'VARCHAR', 'constraint' => 190],
            'description'    => ['type' => 'TEXT', 'null' => true],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'todo'],
            'priority'       => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'normal'],
            'labels'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'due_on'         => ['type' => 'DATE', 'null' => true],
            'estimate_hours' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'visibility'     => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'private'],
            'attachments'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'created_by'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'completed_at'   => ['type' => 'DATETIME', 'null' => true],
            'reminded_on'    => ['type' => 'DATE', 'null' => true],
            'started_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'due_on']);
        $this->forge->addKey('project_id');
        $this->forge->addKey('created_by');
        $this->forge->addForeignKey('project_id', 'tutorial_projects', 'id', '', 'SET NULL');
        $this->forge->addForeignKey('created_by', 'users', 'id', '', 'SET NULL');
        $this->forge->createTable('tutorial_tasks');
    }

    public function down(): void
    {
        $this->forge->dropTable('tutorial_tasks', true);
    }
}
