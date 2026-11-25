<?php

declare(strict_types=1);

namespace Modules\Core\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the image optimiser needs to know about a stored file (D-54-01): `reencoded` = 0 for files
 * stored with `reencode: false` (never resized), and the last failure of `storage:optimize` /
 * `OptimizeImagesTask` (`optimize_failed_at`, `optimize_error`), so a file that cannot be
 * processed is skipped by the next runs instead of being retried in a loop.
 */
final class AddOptimizationToStoredFiles extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('stored_files', [
            'reencoded'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'optimize_failed_at' => ['type' => 'DATETIME', 'null' => true],
            'optimize_error'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('stored_files', ['reencoded', 'optimize_failed_at', 'optimize_error']);
    }
}
