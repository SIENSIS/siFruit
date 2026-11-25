<?php

declare(strict_types=1);

namespace Modules\Assistant\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Assistant\Search\FaqSearch;
use Modules\Assistant\Services\Faqs as FaqRepository;
use Modules\Core\Content\Audience;
use Modules\Core\Content\ContentTranslations;
use Modules\Core\Controllers\BackendController;
use Modules\Core\Frontend\RichTextEditor;
use Modules\Core\Security\HtmlSanitizer;

/**
 * Back office of the frequently asked questions, `backend/assistant/faqs` (resource
 * `assistant-faqs`, D-51-01): per site language the question, the answer (the Core's rich-text
 * editor, sanitised with HTMLPurifier), other phrasings and keywords and the label of the link;
 * the audience (everyone, signed-in users, groups), an optional link (an internal path or an
 * `https://` address, checked like menu items), active flag and order. Every write refreshes the
 * FAQ in the site search (`FaqSearch::sync()`).
 */
final class Faqs extends BackendController
{
    public function index(): ResponseInterface|string
    {
        $this->sanitizePostedAnswers();

        $crud = $this->crud('assistant-faqs');

        $crud->setTable('assistant_faqs')
            ->setSubject(lang('Assistant.faq'), lang('Assistant.faqs'))
            ->unsetShow()
            ->unsetTrash()
            ->unsetPurge()
            ->setTranslations('assistant_faq_translations', 'faq_id', 'locale', FaqRepository::TEXTS, ContentTranslations::locales(), ContentTranslations::crudOptions([
                'types' => ['question' => 'text', 'answer' => 'textarea', 'keywords' => 'textarea', 'link_label' => 'text'],
                'rules' => [
                    'question'   => 'required|max_length[255]',
                    'answer'     => 'permit_empty|max_length[20000]',
                    'keywords'   => 'permit_empty|max_length[1000]',
                    'link_label' => 'permit_empty|max_length[120]',
                ],
                'attrs' => ['answer' => RichTextEditor::attrs()],
            ]))
            ->setRelationNN('groups', 'assistant_faq_groups', 'groups', 'faq_id', 'group_id', 'title', ['order' => 'title'])
            ->fieldType('visibility', 'select', ['options' => self::visibilities()])
            ->fieldType('active', 'boolean')
            ->fieldType('position', 'number')
            ->callbackColumn('visibility', static fn ($value): string => esc(self::visibilities()[(string) $value] ?? (string) $value))
            ->callbackExport('visibility', static fn ($value): string => (string) $value)
            ->columns(['question', 'visibility', 'position', 'active'])
            ->fields(['question', 'answer', 'keywords', 'link', 'link_label', 'visibility', 'groups', 'position', 'active'])
            ->exportColumns(['question', 'visibility', 'link', 'position', 'active'])
            ->searchFields(['question'])
            ->filters(['visibility', 'active', 'groups'])
            ->setRules([
                'visibility' => 'required|in_list[' . implode(',', Audience::VISIBILITIES) . ']',
                'link'       => 'permit_empty|max_length[500]',
                'position'   => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[9999]',
            ])
            ->setDefault('visibility', 'public')
            ->setDefault('active', 1)
            ->setDefault('position', 0)
            ->setHelp('keywords', lang('Assistant.help.keywords'))
            ->setHelp('link', lang('Assistant.help.link'))
            ->setHelp('visibility', lang('Assistant.help.visibility'))
            ->setHelp('groups', lang('Assistant.help.groups'))
            ->setHelp('active', lang('Assistant.help.active'))
            ->displayAs([
                'question'   => lang('Assistant.fields.question'),
                'answer'     => lang('Assistant.fields.answer'),
                'keywords'   => lang('Assistant.fields.keywords'),
                'link'       => lang('Assistant.fields.link'),
                'link_label' => lang('Assistant.fields.linkLabel'),
                'visibility' => lang('Assistant.fields.visibility'),
                'groups'     => lang('Assistant.fields.groups'),
                'position'   => lang('Assistant.fields.position'),
                'active'     => lang('Assistant.fields.active'),
            ])
            ->beforeInsert(static fn (array $data): array|string => self::clean($data))
            ->beforeUpdate(static fn (array $data): array|string => self::clean($data))
            ->afterDelete(static function (string $id): void {
                // Portable clean-up (foreign keys are not enforced on every database).
                db_connect()->table('assistant_faq_translations')->where('faq_id', (int) $id)->delete();
                db_connect()->table('assistant_faq_groups')->where('faq_id', (int) $id)->delete();
            })
            // The site search (D-51-01): every write of the CRUD.
            ->afterWrite(static function (string $operation, string $id): void {
                FaqSearch::sync((int) $id);
            })
            ->defaultOrder('position', 'asc');

        return $this->renderCrud($crud);
    }

    /**
     * @return array<string, string>
     */
    public static function visibilities(): array
    {
        $options = [];

        foreach (Audience::VISIBILITIES as $visibility) {
            $options[$visibility] = lang('Assistant.visibilities.' . $visibility);
        }

        return $options;
    }

    /**
     * Checks the link and normalises the empty one.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|string
     */
    private static function clean(array $data): array|string
    {
        if (array_key_exists('link', $data)) {
            $link  = trim((string) ($data['link'] ?? ''));
            $error = FaqRepository::linkError($link);

            if ($error !== null) {
                return $error;
            }

            $data['link'] = $link === '' ? null : $link;
        }

        return $data;
    }

    /**
     * Sanitises the posted answers (`kp_tr[<locale>][answer]`) with HTMLPurifier before kpcrudy
     * reads the form: its hooks only receive the main table's data (D-17-02).
     */
    private function sanitizePostedAnswers(): void
    {
        $post = $this->request->getPost();

        if (! is_array($post['kp_tr'] ?? null)) {
            return;
        }

        foreach ($post['kp_tr'] as $locale => $fields) {
            if (is_array($fields) && array_key_exists('answer', $fields)) {
                $post['kp_tr'][$locale]['answer'] = is_string($fields['answer']) ? HtmlSanitizer::clean($fields['answer']) : '';
            }
        }

        $this->request->setGlobal('post', $post);
    }
}
