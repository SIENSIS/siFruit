<?php

declare(strict_types=1);

namespace Modules\Assistant\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Assistant\Services\FaqPage;
use Modules\Assistant\Services\Faqs;
use Modules\Core\Controllers\FrontendController;

/**
 * The public page of the frequently asked questions, `faq` (with the language prefix, D-51-03):
 * the FAQs the visitor may read, in their order, each with an anchor `#faq-<id>` (where the site
 * search's results lead). When a page is chosen in `Assistant.faqPage` (a page of the Pages module
 * with the section `assistant.faqs`, its address per language chosen by the editor), it answers a
 * 301 redirect there instead. It does not depend on `Assistant.enabled` (the bubble's switch).
 */
final class PublicFaq extends FrontendController
{
    public function index(): RedirectResponse|string
    {
        // A page chosen for the FAQs (D-51-03): `/faq` sends the visitor there, in their language.
        $page = (new FaqPage())->url();

        if ($page !== null) {
            return redirect()->to($page, 301);
        }

        $user  = auth()->loggedIn() ? auth()->user() : null;
        $items = (new Faqs())->listFor($user, $this->request->getLocale());

        if ($user !== null) {
            $this->response->setHeader('Cache-Control', 'private, no-store');
        }

        $this->meta(['description' => lang('Assistant.page.description'), 'canonical' => site_url('faq')]);

        return $this->render('Modules\Assistant\Views\faq', [
            'title' => lang('Assistant.page.title'),
            'items' => $items,
        ]);
    }
}
