<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\I18n\Time;
use Modules\Auth\Tests\AuthTestCase;
use Modules\Core\System\LogFiles;
use Modules\Core\System\LogRedactor;

/**
 * Settings › System › Error logs (`backend/system/logs`, `system.logs`; D-53-01): superadmins
 * only, re-authentication, the file chosen by its date only (no traversal), bounded reads,
 * level and text filters, masking, escaping, and each opening logged.
 *
 * @internal
 */
final class SystemLogsTest extends AuthTestCase
{
    private const DATE = '2001-02-03';

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = WRITEPATH . 'logs/log-' . self::DATE . '.log';
        file_put_contents($this->file, implode("\n", [
            'INFO - 2001-02-03 10:00:00 --> Started the day',
            'ERROR - 2001-02-03 10:05:00 --> Login failed for ana password=hunter2-secret',
            '[Method: POST, Route: login]',
            '1 SYSTEMPATH/CodeIgniter.php(363): handle()',
            'CRITICAL - 2001-02-03 10:06:00 --> <script>alert(1)</script> Authorization: Bearer abcdef0123456789token',
            'WARNING - 2001-02-03 10:07:00 --> Disk almost full',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink(WRITEPATH . 'logs/log-2001-02-04.log');
        parent::tearDown();
    }

    public function testOnlySuperadminsAndAdminsAreRefused(): void
    {
        $this->createUser('admin@example.com', 'admin');
        $this->login('admin@example.com');
        $this->send('GET', 'backend/system/logs')->assertStatus(403);
        $this->send('GET', 'backend/system/logs/' . self::DATE)->assertStatus(403);

        $_SESSION = [];
        $this->createUser('root@example.com', 'superadmin');
        $this->login('root@example.com');
        $list = $this->send('GET', 'backend/system/logs');
        $list->assertOK();
        $list->assertSee('data-log-date="' . self::DATE . '"');
        $this->assertStringNotContainsString('index.html', (string) $list->getBody());
    }

    public function testReauthenticationIsRequired(): void
    {
        $this->createUser('root@example.com', 'superadmin');
        $this->login('root@example.com');
        Time::setTestNow(Time::now()->addMinutes(16));
        $this->send('GET', 'backend/system/logs')->assertRedirectTo(site_url('auth/reauth'));
        $this->send('GET', 'backend/system/logs/' . self::DATE)->assertRedirectTo(site_url('auth/reauth'));
        Time::setTestNow();
    }

    public function testEntriesAreMaskedEscapedNewestFirstAndTheOpeningIsLogged(): void
    {
        $this->createUser('root@example.com', 'superadmin');
        $this->login('root@example.com');
        $page = $this->send('GET', 'backend/system/logs/' . self::DATE);
        $page->assertOK();
        $body = (string) $page->getBody();

        $this->assertStringNotContainsString('hunter2-secret', $body);
        $this->assertStringNotContainsString('abcdef0123456789token', $body);
        $this->assertStringContainsString('password=' . LogRedactor::MASK, $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringContainsString('1 SYSTEMPATH/CodeIgniter.php(363)', $body, 'The trace is shown folded.');
        $this->assertLessThan(strpos($body, 'Started the day'), strpos($body, 'Disk almost full'), 'Newest first.');

        $this->assertSame(1, db_connect()->table('activity_log')->where('action', 'system_log_opened')->where('summary', 'log-' . self::DATE . '.log')->countAllResults());
    }

    public function testLevelAndTextFilters(): void
    {
        $this->createUser('root@example.com', 'superadmin');
        $this->login('root@example.com');

        $errors = (string) $this->send('GET', 'backend/system/logs/' . self::DATE, ['level' => 'error'])->getBody();
        $this->assertStringContainsString('Login failed', $errors);
        $this->assertStringNotContainsString('Disk almost full', $errors);

        $text = (string) $this->send('GET', 'backend/system/logs/' . self::DATE, ['q' => 'DISK'])->getBody();
        $this->assertStringContainsString('Disk almost full', $text);
        $this->assertStringNotContainsString('Login failed', $text);

        // Searching for a masked secret finds nothing (the filter sees the masked text).
        $secret = (string) $this->send('GET', 'backend/system/logs/' . self::DATE, ['q' => 'hunter2'])->getBody();
        $this->assertStringNotContainsString('Login failed', $secret);

        // An unknown level is ignored.
        $all = (string) $this->send('GET', 'backend/system/logs/' . self::DATE, ['level' => 'nope'])->getBody();
        $this->assertStringContainsString('Disk almost full', $all);
    }

    public function testTheFileIsChosenByItsDateOnly(): void
    {
        $this->createUser('root@example.com', 'superadmin');
        $this->login('root@example.com');

        foreach (['2001-02-30', '..%2F..%2F.env', 'log-2001-02-03.log', '2001-02-03.log', '%2Fetc%2Fpasswd', '20010203', '2099-01-01'] as $date) {
            $this->assertNotFound('GET', 'backend/system/logs/' . $date);
        }

        $logs = new LogFiles();
        $this->assertNull($logs->path('../../.env'));
        $this->assertNull($logs->path('/etc/passwd'));
        $this->assertNull($logs->path('2001-02-03/../../x'));
        $this->assertNotNull($logs->path(self::DATE));

        // A symbolic link with a valid name is not a log file.
        if (function_exists('symlink') && @symlink(ROOTPATH . 'composer.json', WRITEPATH . 'logs/log-2001-02-04.log')) {
            $this->assertNull($logs->path('2001-02-04'));
            $this->assertNotContains('2001-02-04', array_column($logs->files(), 'date'));
        }
    }

    public function testALargeFileIsReadInBoundedChunks(): void
    {
        $dir = sys_get_temp_dir() . '/kp-logs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $handle = fopen($dir . '/log-2001-02-03.log', 'wb');
        $this->assertIsResource($handle);

        for ($i = 0; $i < 3000; $i++) {
            fwrite($handle, sprintf("ERROR - 2001-02-03 10:%02d:%02d --> Entry number %05d %s\n", intdiv($i, 60) % 60, $i % 60, $i, str_repeat('x', 60)));
        }

        fclose($handle);
        $logs = new LogFiles($dir, new LogRedactor([]), 8192);

        $first = $logs->read('2001-02-03');
        $this->assertIsArray($first);
        $this->assertLessThanOrEqual(8192, $first['bytesRead']);
        $this->assertStringContainsString('Entry number 02999', $first['entries'][0]['message'], 'Newest first.');
        $this->assertNotNull($first['older']);

        $second = $logs->read('2001-02-03', $first['older']);
        $this->assertIsArray($second);
        $this->assertLessThanOrEqual(8192, $second['bytesRead']);
        $lastOfFirst  = (int) substr((string) end($first['entries'])['message'], 13, 5);
        $firstOfSecond = (int) substr($second['entries'][0]['message'], 13, 5);
        $this->assertSame($lastOfFirst - 1, $firstOfSecond, 'The next page continues without a gap.');

        // Walking every page reads every entry exactly once.
        $seen   = 0;
        $before = null;

        do {
            $page = $logs->read('2001-02-03', $before);
            $this->assertIsArray($page);
            $seen += count($page['entries']);
            $before = $page['older'];
        } while ($before !== null);

        $this->assertSame(3000, $seen);

        unlink($dir . '/log-2001-02-03.log');
        rmdir($dir);
    }

    public function testTheRedactor(): void
    {
        $redactor = new LogRedactor(['SuperSecretEncKey42', 'dbPassw0rd!']);
        $cases    = [
            'password=hunter2 user=ana'                         => ['hunter2'],
            '{"token":"abc123def456","name":"Joan"}'            => ['abc123def456'],
            "array('password' => 'p@ss word', 'user' => 'x')"   => ['p@ss word'],
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig' => ['eyJhbGciOiJIUzI1NiJ9'],
            'Cookie: ci_session=0123456789abcdef; theme=dark'    => ['0123456789abcdef'],
            'key SuperSecretEncKey42 leaked'                    => ['SuperSecretEncKey42'],
            'mysqli: access denied (using dbPassw0rd!)'         => ['dbPassw0rd!'],
            'GET /x?sig=AbCdEfGh1234567890abcdefghIJKL&page=2'  => ['AbCdEfGh1234567890abcdefghIJKL'],
            'api_key: 12345678'                                 => ['12345678'],
            'X-Api-Key: Zm9vYmFyYmF6'                           => ['Zm9vYmFyYmF6'],
        ];

        foreach ($cases as $line => $hidden) {
            $out = $redactor->redact($line);
            $this->assertStringContainsString(LogRedactor::MASK, $out, $line);

            foreach ($hidden as $secret) {
                $this->assertStringNotContainsString($secret, $out, $line);
            }
        }

        $this->assertSame('user=ana logged in', $redactor->redact('user=ana logged in'), 'Ordinary text is kept.');
        $this->assertStringContainsString('page=2', $redactor->redact('GET /x?sig=AbCdEfGh1234567890abcdefghIJKL&page=2'));

        // The configured secrets of the environment are masked literally.
        $_ENV['FIXTURE_API_TOKEN'] = 'env-token-value-123';
        $this->assertStringNotContainsString('env-token-value-123', (new LogRedactor())->redact('sent env-token-value-123 to x'));
        unset($_ENV['FIXTURE_API_TOKEN']);
    }

    public function testTheSystemScreenShowsTodaysErrorsToSuperadmins(): void
    {
        $this->createUser('root@example.com', 'superadmin');
        $this->login('root@example.com');
        $page = (string) $this->send('GET', 'backend/system')->getBody();
        $this->assertStringContainsString('data-system-log-errors=', $page);
    }
}
