<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Registry\ModuleState;
use Modules\Core\Storage\UnusedFiles as UnusedFilesService;
use Modules\Core\Storage\UnusedFilesException;
use Modules\Core\System\SystemReport;
use Modules\Core\Tasks\DeleteUnusedFilesTask;

/**
 * Settings › System › Unused files (`backend/system/unused-files`, `system.files`; D-55-01,
 * docs/system.md): the stored files of the module folders that nothing uses (the last scan,
 * cached), "Scan now", the folders that are not checked, and deleting the selected or all
 * listed files after re-authentication, a confirmation and a re-check of every file on the
 * server (forged, used, too new or not checked ids are skipped and counted). Works without
 * JavaScript; `core/unused-files` only adds "select all" and the selection's totals.
 */
final class UnusedFiles extends BackendController
{
    public const PER_PAGE = 50;

    public function index(): string
    {
        $error  = null;
        $report = null;

        try {
            $report = (new UnusedFilesService())->cached();
        } catch (UnusedFilesException $e) {
            $error = $e->getMessage();
        }

        $modules = $report === null ? [] : array_values(array_unique(array_column($report['checked'], 'module')));
        sort($modules);
        $module = $this->moduleFilter($modules);
        $files  = $report === null ? [] : array_values(array_filter($report['files'], static fn (array $file): bool => $module === null || $file['module'] === $module));
        $total  = count($files);
        $bytes  = array_sum(array_map(static fn (array $file): int => $file['size'] + $file['variantsBytes'], $files));
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min($page, $pages);

        $this->breadcrumb(lang('System.unused.title'));

        return $this->render('Modules\Core\Views\system\unused_files', [
            'title'      => lang('System.unused.title'),
            'icon'       => 'bi bi-file-earmark-x',
            'report'     => $report,
            'error'      => $error,
            'modules'    => $modules,
            'module'     => $module,
            'files'      => array_slice($files, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total'      => $total,
            'bytes'      => $bytes,
            'page'       => $page,
            'pages'      => $pages,
            'graceDays'  => UnusedFilesService::graceDays(),
            'auto'       => ['enabled' => DeleteUnusedFilesTask::enabled(), 'days' => DeleteUnusedFilesTask::days()],
            'backupsUrl' => ! in_array('Backup', (new ModuleState())->disabled(), true) && $this->can('backup.list') ? backend_url('backups') : null,
            'baseUrl'    => backend_url('system/unused-files'),
        ]);
    }

    public function scan(): RedirectResponse
    {
        UnusedFilesService::forget();

        try {
            (new UnusedFilesService())->cached(true);
        } catch (UnusedFilesException $e) {
            return redirect()->to(backend_url('system/unused-files'))->with('error', lang('Core.unused.scanFailed', [$e->getMessage()]));
        }

        return redirect()->to(backend_url('system/unused-files'))->with('success', lang('System.unused.scanned'));
    }

    public function delete(): RedirectResponse
    {
        $back    = backend_url('system/unused-files');
        $service = new UnusedFilesService();
        $mode    = $this->request->getPost('mode');
        $module  = $this->request->getPost('module');
        $module  = is_string($module) && preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $module) === 1 ? $module : null;

        try {
            if ($mode === 'all') {
                $report = $service->cached(true);
                $ids    = [];

                foreach ($report['files'] as $file) {
                    if ($module === null || $file['module'] === $module) {
                        $ids[] = (int) $file['id'];
                    }
                }
            } else {
                $posted = $this->request->getPost('ids');
                $ids    = [];

                foreach (is_array($posted) ? $posted : [] as $id) {
                    if (is_string($id) && ctype_digit($id)) {
                        $ids[] = (int) $id;
                    }
                }
            }

            if ($ids === []) {
                return redirect()->to($back)->with('error', lang('System.unused.noneSelected'));
            }

            $result = $service->delete($ids, ['via' => 'screen', 'module' => $module]);
        } catch (UnusedFilesException $e) {
            return redirect()->to($back)->with('error', lang('Core.unused.scanFailed', [$e->getMessage()]));
        }

        $skipped = UnusedFilesService::skippedCount($result);
        $message = lang('Core.unused.deleted', [$result['deleted'], SystemReport::bytes($result['bytes']), $skipped]);

        return redirect()->to($back)->with($result['deleted'] > 0 ? 'success' : 'warning', $message);
    }

    /**
     * The module of the `module` query parameter when it is one of the checked modules.
     *
     * @param list<string> $modules
     */
    private function moduleFilter(array $modules): ?string
    {
        $module = $this->request->getGet('module');

        return is_string($module) && in_array($module, $modules, true) ? $module : null;
    }
}
