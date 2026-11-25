<?php

declare(strict_types=1);

namespace Modules\Contact\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Table `contact_recipient_users` (D-48-03): the specific users of a recipient (a topic of the
 * form, e.g. "Degree certificates" → the secretary and the head of the office). They are notified
 * of its messages and read them, like the members of the recipient's group (kpcrudy
 * `setRelationNN()`).
 */
final class CreateContactRecipientUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'recipient_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['recipient_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('recipient_id', 'contact_recipients', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('contact_recipient_users');
    }

    public function down(): void
    {
        $this->forge->dropTable('contact_recipient_users', true);
    }
}
