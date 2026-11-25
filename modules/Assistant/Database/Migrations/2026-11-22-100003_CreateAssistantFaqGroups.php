<?php

declare(strict_types=1);

namespace Modules\Assistant\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The groups that may read an FAQ whose audience is `groups` (like `news_groups`).
 */
final class CreateAssistantFaqGroups extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'faq_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'group_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['faq_id', 'group_id']);
        $this->forge->createTable('assistant_faq_groups');
    }

    public function down(): void
    {
        $this->forge->dropTable('assistant_faq_groups', true);
    }
}
