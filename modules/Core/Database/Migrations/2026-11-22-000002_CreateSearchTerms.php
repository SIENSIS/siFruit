<?php

declare(strict_types=1);

namespace Modules\Core\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The terms of the site search (D-50-01, docs/search.md): one row per document and normalised term
 * with its weight (title ×5, meta title / keywords ×3, summary / meta description ×2, body ×1).
 * Queries look terms up by equality or prefix (`term LIKE 'x%'`), the same on MariaDB and SQLite.
 */
final class CreateSearchTerms extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'document_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'term'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'weight'      => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey(['document_id', 'term']);
        $this->forge->addKey('term');
        $this->forge->createTable('search_terms');
    }

    public function down(): void
    {
        $this->forge->dropTable('search_terms', true);
    }
}
