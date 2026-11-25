<?php

declare(strict_types=1);

namespace Modules\Events\Services;

use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\User;

/**
 * What the back-office agenda remembers for each user (`event_calendar_preferences`, session 57):
 * the last view chosen on screens ≥ lg (`view`) and on phones (`view_mobile`), and the
 * "All events (administration)" switch (`scope`, D-57-02). The hidden calendars of the same row
 * belong to `Calendars` and are never touched here.
 *
 * Only views of `CalendarComponent::VIEWS` are stored; `admin` is stored, and read back, only
 * while the caller says the user holds `events-events.all`.
 */
final class AgendaPreferences
{
    public const SCOPE_PERSONAL = 'personal';
    public const SCOPE_ADMIN    = 'admin';

    private const TABLE = 'event_calendar_preferences';

    /** The stored view for this screen size, or null (then the default: month, or list on phones). */
    public function view(User $user, bool $mobile): ?string
    {
        $value = $this->row($user)[$mobile ? 'view_mobile' : 'view'] ?? null;

        return is_string($value) && in_array($value, CalendarComponent::VIEWS, true) ? $value : null;
    }

    /** Stores the view for this screen size; false (nothing stored) for a view not offered. */
    public function setView(User $user, string $view, bool $mobile): bool
    {
        if (! in_array($view, CalendarComponent::VIEWS, true)) {
            return false;
        }

        $this->write($user, [$mobile ? 'view_mobile' : 'view' => $view]);

        return true;
    }

    /** The switch: `admin` only when it was turned on and the user still holds the permission. */
    public function scope(User $user, bool $mayAdmin): string
    {
        return $mayAdmin && ($this->row($user)['scope'] ?? null) === self::SCOPE_ADMIN ? self::SCOPE_ADMIN : self::SCOPE_PERSONAL;
    }

    /** Stores the switch; `admin` without the permission is stored as `personal`. */
    public function setScope(User $user, string $scope, bool $mayAdmin): string
    {
        $scope = $mayAdmin && $scope === self::SCOPE_ADMIN ? self::SCOPE_ADMIN : self::SCOPE_PERSONAL;
        $this->write($user, ['scope' => $scope]);

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        $row = db_connect()->table(self::TABLE)->where('user_id', (int) $user->id)->get()->getRowArray();

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string, string> $values
     */
    private function write(User $user, array $values): void
    {
        $db     = db_connect();
        $values += ['updated_at' => Time::now()->toDateTimeString()];

        if ($db->table(self::TABLE)->where('user_id', (int) $user->id)->countAllResults() > 0) {
            $db->table(self::TABLE)->where('user_id', (int) $user->id)->update($values);
        } else {
            $db->table(self::TABLE)->insert($values + ['user_id' => (int) $user->id]);
        }
    }
}
