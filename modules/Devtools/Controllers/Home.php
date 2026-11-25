<?php

declare(strict_types=1);

namespace Modules\Devtools\Controllers;

use Modules\Core\Controllers\BackendController;

/**
 * `backend/devtools` (`devtools.view`): the development tools and the locks that keep them out
 * of production (docs/devtools.md).
 */
final class Home extends BackendController
{
    public function index(): string
    {
        return $this->render('Modules\Devtools\Views\index', [
            'title' => lang('Devtools.title'),
            'icon'  => 'bi bi-tools',
            'tools' => self::tools(),
        ]);
    }

    /**
     * Every tool (the cards of the home and the local navigation of every screen).
     *
     * @return list<array{key: string, url: string, icon: string, title: string, text: string}>
     */
    public static function tools(): array
    {
        $tools = [];

        foreach ([
            'generator'  => ['devtools/generator', 'bi bi-magic'],
            'packages'   => ['devtools/packages', 'bi bi-box-seam'],
            'recipes'    => ['devtools/recipes', 'bi bi-journal-code'],
            'inspector'  => ['devtools/modules', 'bi bi-search'],
            'routes'     => ['devtools/routes', 'bi bi-signpost-split'],
            'mail'       => ['devtools/mail', 'bi bi-envelope-paper'],
            'components' => ['devtools/components', 'bi bi-grid-1x2'],
            'checks'     => ['devtools/checks', 'bi bi-check2-all'],
            'phpinfo'    => ['devtools/phpinfo', 'bi bi-filetype-php'],
        ] as $key => [$path, $icon]) {
            $tools[] = ['key' => $key, 'url' => backend_url($path), 'icon' => $icon, 'title' => lang('Devtools.' . $key . '.title'), 'text' => lang('Devtools.' . $key . '.intro')];
        }

        return $tools;
    }
}
