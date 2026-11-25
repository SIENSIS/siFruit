<?php

declare(strict_types=1);

namespace Modules\Devtools\Controllers;

use Modules\Core\Controllers\BackendController;
use Modules\Devtools\System\PhpInfo as Info;

/**
 * Devtools › PHP info (`backend/devtools/phpinfo`, `devtools.view`; session 53): the full
 * `phpinfo()` inside the back-office layout, rebuilt as escaped tables (no inline style or
 * script), with a search box. Development only: never in production (docs/security.md).
 */
final class PhpInfo extends BackendController
{
    public function index(): string
    {
        $this->breadcrumb(lang('Devtools.title'), backend_url('devtools'));
        $this->breadcrumb(lang('Devtools.phpinfo.title'));
        $this->response->setHeader('Cache-Control', 'no-store');

        return $this->render('Modules\Devtools\Views\phpinfo', [
            'title'    => lang('Devtools.phpinfo.title'),
            'icon'     => 'bi bi-filetype-php',
            'sections' => Info::sections(),
        ]);
    }
}
