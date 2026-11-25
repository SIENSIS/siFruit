<?php

declare(strict_types=1);

namespace Modules\Devtools\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BackendController;
use Modules\Core\I18n\LanguageKeys;
use Modules\Devtools\Generator\SparkRunner;

/**
 * Devtools › Checks (`backend/devtools/checks`; session 53): runs `permissions:check`,
 * `csp:check`, `assets:publish --check`, `security:check` (through SparkRunner, new processes)
 * or the language keys check (`Modules\Core\I18n\LanguageKeys`) and shows the output and exit
 * code. They only read, but they run commands: the POST uses the WRITE filters (docs/devtools.md).
 */
final class Checks extends BackendController
{
    /** Check => spark arguments (null: in this process). */
    public const CHECKS = [
        'permissions' => ['permissions:check'],
        'csp'         => ['csp:check'],
        'assets'      => ['assets:publish', '--check'],
        'security'    => ['security:check'],
        'language'    => null,
    ];

    /** For tests only. */
    public static ?SparkRunner $testRunner = null;

    public function index(): string
    {
        $result = session()->getFlashdata('devtools_check');

        $this->breadcrumb(lang('Devtools.title'), backend_url('devtools'));
        $this->breadcrumb(lang('Devtools.checks.title'));

        return $this->render('Modules\Devtools\Views\checks', [
            'title'  => lang('Devtools.checks.title'),
            'icon'   => 'bi bi-check2-all',
            'checks' => array_keys(self::CHECKS),
            'result' => is_array($result) ? $result : null,
        ]);
    }

    public function run(): RedirectResponse
    {
        $check = $this->request->getPost('check');

        if (! is_string($check) || ! array_key_exists($check, self::CHECKS)) {
            return redirect()->to(backend_url('devtools/checks'))->with('error', lang('Devtools.checks.unknown'));
        }

        $arguments = self::CHECKS[$check];

        if ($arguments === null) {
            $problems = (new LanguageKeys())->problems();
            $result   = ['status' => $problems === [] ? 0 : 1, 'output' => $problems === [] ? lang('Devtools.checks.languageOk') : implode("\n", $problems)];
        } else {
            $result = (self::$testRunner ?? new SparkRunner())->run($arguments);
        }

        activity_log('Devtools', 'check_run', ['resource' => 'devtools', 'summary' => $check, 'changes' => ['status' => $result['status']]]);

        return redirect()->to(backend_url('devtools/checks'))->with('devtools_check', [
            'check'   => $check,
            'command' => $arguments === null ? 'LanguageKeys' : 'php spark ' . implode(' ', $arguments),
            'status'  => $result['status'],
            'output'  => mb_substr($result['output'], 0, 100000),
        ]);
    }
}
