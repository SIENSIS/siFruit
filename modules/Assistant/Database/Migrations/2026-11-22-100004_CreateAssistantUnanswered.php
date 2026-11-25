<?php

declare(strict_types=1);

namespace Modules\Assistant\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Questions the assistant could not answer (D-51-02), kept only while `Assistant.logUnanswered` is
 * on: the question with emails and long numbers masked, its language, the day (no time) and how
 * many times it was asked that day. No IP, user, session or anything that identifies the visitor.
 * Deleted after `Assistant.unansweredDays` days (PurgeUnanswered).
 */
final class CreateAssistantUnanswered extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'locale'   => ['type' => 'VARCHAR', 'constraint' => 10],
            'question' => ['type' => 'VARCHAR', 'constraint' => 300],
            'hash'     => ['type' => 'CHAR', 'constraint' => 40],
            'asked_on' => ['type' => 'DATE'],
            'times'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['hash', 'asked_on']);
        $this->forge->addKey('asked_on');
        $this->forge->createTable('assistant_unanswered');
    }

    public function down(): void
    {
        $this->forge->dropTable('assistant_unanswered', true);
    }
}
