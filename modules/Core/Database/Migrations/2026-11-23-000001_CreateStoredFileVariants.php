<?php

declare(strict_types=1);

namespace Modules\Core\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The responsive copies of public images (D-54-01, docs/storage.md): one row per width, generated
 * on upload (or by `storage:optimize`) beside the file on the same disk
 * (`<folder>/<hex>-w400.webp`), deleted with it.
 */
final class CreateStoredFileVariants extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'stored_file_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'width'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'height'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'format'         => ['type' => 'VARCHAR', 'constraint' => 16],
            'path'           => ['type' => 'VARCHAR', 'constraint' => 500],
            'size'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'created_at'     => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['stored_file_id', 'width'], 'stored_file_variants_file_width');
        $this->forge->createTable('stored_file_variants');
    }

    public function down(): void
    {
        $this->forge->dropTable('stored_file_variants', true);
    }
}
