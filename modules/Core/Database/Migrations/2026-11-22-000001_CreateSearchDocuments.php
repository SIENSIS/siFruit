<?php

declare(strict_types=1);

namespace Modules\Core\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The documents of the site search (D-50-01, docs/search.md): one row per item of a search source
 * and language, with what the results page shows (title, excerpt, path, date) and what the query
 * filters by (module, audience, groups, publish window). `hash` makes indexing idempotent.
 */
final class CreateSearchDocuments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'module'          => ['type' => 'VARCHAR', 'constraint' => 60],
            'source'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'item_key'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'locale'          => ['type' => 'VARCHAR', 'constraint' => 10],
            'title'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'excerpt'         => ['type' => 'TEXT', 'null' => true],
            'url'             => ['type' => 'VARCHAR', 'constraint' => 500],
            'date'            => ['type' => 'DATETIME', 'null' => true],
            'visibility'      => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'public'],
            'audience_groups' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'publish_from'    => ['type' => 'DATETIME', 'null' => true],
            'publish_until'   => ['type' => 'DATETIME', 'null' => true],
            'hash'            => ['type' => 'CHAR', 'constraint' => 40],
            'indexed_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['source', 'item_key', 'locale']);
        $this->forge->addKey(['locale', 'visibility']);
        $this->forge->addKey('module');
        $this->forge->createTable('search_documents');
    }

    public function down(): void
    {
        $this->forge->dropTable('search_documents', true);
    }
}
