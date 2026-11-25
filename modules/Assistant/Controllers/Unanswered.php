<?php

declare(strict_types=1);

namespace Modules\Assistant\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Kpcrudy\Crud;
use Modules\Assistant\Search\FaqSearch;
use Modules\Assistant\Services\UnansweredLog;
use Modules\Core\Controllers\BackendController;

/**
 * The unanswered questions, `backend/assistant/unanswered` (`can:assistant-faqs.add`, D-51-02):
 * the anonymised questions kept while `Assistant.logUnanswered` is on, newest first, with how many
 * times each was asked that day; "Create an FAQ" turns one into an inactive FAQ and opens it,
 * "Delete" forgets it. Both are logged in the activity log.
 */
final class Unanswered extends BackendController
{
    public function index(): string
    {
        return $this->render('Modules\Assistant\Views\unanswered', [
            'title'   => lang('Assistant.unanswered.title'),
            'icon'    => 'bi bi-question-circle',
            'rows'    => (new UnansweredLog())->all(),
            'logging' => (bool) setting('Assistant.logUnanswered'),
            'days'    => max(7, (int) setting('Assistant.unansweredDays')),
        ]);
    }

    public function toFaq(int $id): RedirectResponse
    {
        $faq = (new UnansweredLog())->toFaq($id);

        if ($faq === null) {
            return redirect()->to(backend_url('assistant/unanswered'))->with('error', lang('Assistant.unanswered.gone'));
        }

        FaqSearch::sync($faq);
        activity_log('Assistant', 'unanswered_to_faq', ['resource' => 'assistant-faqs', 'record_id' => (string) $faq]);

        return redirect()->to(backend_url('assistant/faqs/edit/' . Crud::maskId($faq, 'assistant_faqs')))->with('message', lang('Assistant.unanswered.created'));
    }

    public function delete(int $id): RedirectResponse
    {
        if ((new UnansweredLog())->delete($id)) {
            activity_log('Assistant', 'unanswered_deleted', ['resource' => 'assistant-faqs', 'record_id' => (string) $id]);
        }

        return redirect()->to(backend_url('assistant/unanswered'))->with('message', lang('Assistant.unanswered.deleted'));
    }
}
