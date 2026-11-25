<?php

declare(strict_types=1);

namespace Modules\Events\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

/**
 * Columns `view`, `view_mobile` and `scope` of `event_calendar_preferences` (session 57,
 * D-57-01, D-57-02): the last view each user chose in the agenda (screens ≥ lg and phones apart)
 * and whether the "All events (administration)" switch is on (`admin`, only kept while the user
 * holds `events-events.all`).
 */
final class AddViewToEventCalendarPreferences extends Migration
{
    private const COLUMNS = ['view', 'view_mobile', 'scope'];

    public function up(): void
    {
        $this->forge->addColumn('event_calendar_preferences', [
            'view'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'view_mobile' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'scope'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
        ]);
    }

    public function down(): void
    {
        // The table may already be gone (a down() of its creating migration ran first).
        $db = $this->db;

        if (! $db instanceof BaseConnection || ! $db->tableExists('event_calendar_preferences')) {
            return;
        }

        $db->resetDataCache();

        foreach (self::COLUMNS as $column) {
            if ($db->fieldExists($column, 'event_calendar_preferences')) {
                $this->forge->dropColumn('event_calendar_preferences', $column);
            }
        }
    }
}
