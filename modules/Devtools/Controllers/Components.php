<?php

declare(strict_types=1);

namespace Modules\Devtools\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use Modules\Core\Controllers\BackendController;
use Modules\Core\Theme\ThemeNotFoundException;

/**
 * Devtools › Component gallery (`backend/devtools/components`, `devtools.view`; session 53): the
 * active theme's components and helpers with sample data, in light and dark mode, with the PHP
 * call of each; `?theme=<slug>` previews another installed theme for this request only (the
 * theme preview mechanism: `service('theme')->force()`), never changing the active theme.
 */
final class Components extends BackendController
{
    public function index(): string
    {
        $theme  = service('theme');
        $slug   = $this->request->getGet('theme');
        $themes = [];

        foreach ($theme->registry()->slugs() as $s) {
            $themes[$s] = $theme->registry()->get($s)->name;
        }

        if (is_string($slug) && $slug !== '') {
            if (! isset($themes[$slug])) {
                throw PageNotFoundException::forPageNotFound();
            }

            try {
                $theme->force('backend', $slug);
            } catch (ThemeNotFoundException) {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        $this->breadcrumb(lang('Devtools.title'), backend_url('devtools'));
        $this->breadcrumb(lang('Devtools.components.title'));

        return $this->render('Modules\Devtools\Views\components', [
            'title'   => lang('Devtools.components.title'),
            'icon'    => 'bi bi-grid-1x2',
            'themes'  => $themes,
            'current' => is_string($slug) && $slug !== '' ? $slug : '',
        ]);
    }
}
