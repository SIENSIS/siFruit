<?php

declare(strict_types=1);

namespace Modules\Assistant\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The texts of an FAQ per site language: the question, the answer (rich text, sanitised), other
 * ways of asking it and keywords (one per line) and the label of its link.
 */
final class CreateAssistantFaqTranslations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'faq_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'locale'     => ['type' => 'VARCHAR', 'constraint' => 10],
            'question'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'answer'     => ['type' => 'MEDIUMTEXT', 'null' => true],
            'keywords'   => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'link_label' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['faq_id', 'locale']);
        $this->forge->createTable('assistant_faq_translations');
    }

    public function down(): void
    {
        $this->forge->dropTable('assistant_faq_translations', true);
    }
}
