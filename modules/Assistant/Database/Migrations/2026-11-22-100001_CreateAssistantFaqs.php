<?php

declare(strict_types=1);

namespace Modules\Assistant\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Frequently asked questions of the chat assistant (D-51-01): audience (`public`, `private` =
 * signed-in users, `groups` = the groups of `assistant_faq_groups`), an optional link (an internal
 * path or an `https://` address, checked like menu items), active flag and order. The texts are
 * in `assistant_faq_translations`.
 */
final class CreateAssistantFaqs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'visibility' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'public'],
            'link'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'position'   => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['active', 'position']);
        $this->forge->createTable('assistant_faqs');
    }

    public function down(): void
    {
        $this->forge->dropTable('assistant_faqs', true);
    }
}
