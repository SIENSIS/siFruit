<?php

declare(strict_types=1);

namespace Modules\Core\System;

/**
 * What the System information report reads from PHP and the server (extensions, `ini` values,
 * OPcache, disk space, the clock). One class so the tests can fake a missing extension, a full
 * disk or a cron that has not run (docs/system.md); it never changes anything.
 */
class SystemEnvironment
{
    public function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function ini(string $name): string
    {
        $value = ini_get($name);

        return $value === false ? '' : (string) $value;
    }

    /**
     * OPcache's state, or null when the extension is missing or disabled for this SAPI.
     *
     * @return array{enabled: bool, used: int, free: int}|null
     */
    public function opcache(): ?array
    {
        if (! function_exists('opcache_get_status')) {
            return null;
        }

        $status = @opcache_get_status(false);

        if (! is_array($status)) {
            return ['enabled' => false, 'used' => 0, 'free' => 0];
        }

        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];

        return [
            'enabled' => (bool) ($status['opcache_enabled'] ?? false),
            'used'    => (int) ($memory['used_memory'] ?? 0),
            'free'    => (int) ($memory['free_memory'] ?? 0),
        ];
    }

    public function diskFreeSpace(string $path): ?float
    {
        $free = @disk_free_space($path);

        return $free === false ? null : (float) $free;
    }

    public function diskTotalSpace(string $path): ?float
    {
        $total = @disk_total_space($path);

        return $total === false ? null : (float) $total;
    }

    public function now(): int
    {
        return \CodeIgniter\I18n\Time::now('UTC')->getTimestamp();
    }

    public function environment(): string
    {
        return ENVIRONMENT;
    }
}
