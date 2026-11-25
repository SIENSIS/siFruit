<?php

declare(strict_types=1);

namespace Modules\Core\Install;

use Modules\Core\Push\WebPush;
use Modules\Core\Security\MaskKey;

/**
 * The secrets the installer writes into `.env`.
 */
final class KeyGenerator
{
    /** Why the Web Push keys could not be generated ('' when they were). */
    public string $pushKeysError = '';

    /**
     * @param (\Closure(): array<string, string>)|null $pushKeyFactory the `push.vapid*` keys (default WebPush::newKeys())
     */
    public function __construct(private readonly ?\Closure $pushKeyFactory = null)
    {
    }

    /**
     * CodeIgniter's encryption key (`encryption.key`): 32 random bytes as `hex2bin:<64 hex>`,
     * the format of `php spark key:generate`.
     */
    public function encryptionKey(): string
    {
        return 'hex2bin:' . bin2hex(random_bytes(32));
    }

    /**
     * The shared mask key of kpcrudy and kpapy (`app.maskKey`): 64 random hex characters.
     */
    public function maskKey(): string
    {
        return MaskKey::generate();
    }

    /**
     * The VAPID keys and contact of the Web Push channel (`push.*`, D-44-02).
     *
     * @return array<string, string>
     */
    public function pushKeys(string $adminEmail, string $baseUrl): array
    {
        // Web Push is off by default: a server whose OpenSSL cannot create the keys still installs,
        // and `php spark push:keys` writes them later.
        try {
            $keys = $this->pushKeyFactory !== null ? ($this->pushKeyFactory)() : WebPush::newKeys();
        } catch (\RuntimeException $e) {
            $this->pushKeysError = $e->getMessage();

            return [];
        }

        return $keys + ['push.subject' => WebPush::defaultSubject($adminEmail, $baseUrl)];
    }
}
