<?php

declare(strict_types=1);

namespace Modules\Devtools\Controllers;

use Modules\Core\Controllers\BackendController;
use Modules\Core\I18n\Locales;
use Modules\Devtools\Mail\MailPreviews;

/**
 * Devtools › E-mail and notification previews (`backend/devtools/mail`, `devtools.view`;
 * session 53): a notification type or a security e-mail rendered with sample data in a language,
 * for each channel. Nothing is sent and nothing is stored (docs/devtools.md).
 */
final class Mail extends BackendController
{
    public function index(): string
    {
        $previews = new MailPreviews();
        $items    = $previews->items();
        $id       = $this->request->getGet('item');
        $locale   = $this->request->getGet('locale');
        $id       = is_string($id) && isset($items[$id]) ? $id : (string) (array_key_first($items) ?? '');
        $locale   = is_string($locale) && Locales::isAvailable($locale) ? $locale : Locales::default();

        $this->breadcrumb(lang('Devtools.title'), backend_url('devtools'));
        $this->breadcrumb(lang('Devtools.mail.title'));

        return $this->render('Modules\Devtools\Views\mail', [
            'title'   => lang('Devtools.mail.title'),
            'icon'    => 'bi bi-envelope-paper',
            'items'   => $items,
            'id'      => $id,
            'locale'  => $locale,
            'locales' => Locales::available(),
            'preview' => $id !== '' ? $previews->render($id, $locale) : null,
        ]);
    }
}
