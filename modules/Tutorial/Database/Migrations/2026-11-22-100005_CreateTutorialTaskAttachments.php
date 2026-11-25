<?php

declare(strict_types=1);

namespace Modules\Tutorial\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Table `tutorial_task_attachments` (tutorial 10): which to-do task each attached file belongs to, kept in
 * step with `tutorial_tasks.attachments` after every write. The `FileAccessPolicy` of the folder
 * `modules/tutorial` reads it to decide who may download a private file.
 */
final class CreateTutorialTaskAttachments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'task_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'file_key'   => ['type' => 'VARCHAR', 'constraint' => 32],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['task_id', 'file_key']);
        $this->forge->addKey('file_key');
        $this->forge->addForeignKey('task_id', 'tutorial_tasks', 'id', '', 'CASCADE');
        $this->forge->createTable('tutorial_task_attachments');
    }

    public function down(): void
    {
        $this->forge->dropTable('tutorial_task_attachments', true);
    }
}
