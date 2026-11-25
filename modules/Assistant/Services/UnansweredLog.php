<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use CodeIgniter\I18n\Time;

/**
 * The unanswered questions (D-51-02; docs/assistant.md "Privacy"): kept only while
 * `Assistant.logUnanswered` is on, without IP, user, session or a time finer than the day, with
 * email addresses and numbers of six or more digits masked; the same question on the same day
 * counts once more (`times`). Deleted after `Assistant.unansweredDays` days (minimum 7) by the
 * PurgeUnanswered task. Shown to holders of `assistant-faqs.add`, who turn them into FAQs.
 */
final class UnansweredLog
{
    public const MASK_EMAIL  = '[email]';
    public const MASK_NUMBER = '[######]';

    public function record(string $question, string $locale): void
    {
        $text = self::anonymise($question);

        if ($text === '') {
            return;
        }

        $db    = db_connect();
        $today = Time::today()->toDateString();
        $hash  = sha1($locale . '|' . mb_strtolower($text, 'UTF-8'));
        $row   = $db->table('assistant_unanswered')->select('id')->where('hash', $hash)->where('asked_on', $today)->get(1)->getRowArray();

        if (is_array($row)) {
            $db->table('assistant_unanswered')->where('id', (int) $row['id'])->set('times', 'times + 1', false)->update();

            return;
        }

        $db->table('assistant_unanswered')->insert([
            'locale'   => mb_substr($locale, 0, 10),
            'question' => $text,
            'hash'     => $hash,
            'asked_on' => $today,
            'times'    => 1,
        ]);
    }

    /**
     * The question with emails and numbers of 6+ digits (spaces, dots and dashes between digits
     * allowed: phone numbers, ids) masked, whitespace collapsed, at most 300 characters.
     */
    public static function anonymise(string $question): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $question) ?? '';
        $text = preg_replace('/[^\s@<>()]+@[^\s@<>()]+\.[^\s@<>()]+/u', self::MASK_EMAIL, $text) ?? '';
        $text = preg_replace_callback('/\+?\d[\d\s.\-\/]*\d/u', static function (array $m): string {
            return strlen(preg_replace('/\D/', '', $m[0]) ?? '') >= 6 ? self::MASK_NUMBER : $m[0];
        }, $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, 300, 'UTF-8');
    }

    /**
     * Deletes the questions asked more than `$days` days ago. Returns how many rows.
     */
    public function purge(int $days): int
    {
        $limit = Time::today()->subDays(max(7, $days))->toDateString();
        db_connect()->table('assistant_unanswered')->where('asked_on <', $limit)->delete();

        return db_connect()->affectedRows();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(int $limit = 200): array
    {
        return db_connect()->table('assistant_unanswered')->orderBy('asked_on', 'desc')->orderBy('times', 'desc')->orderBy('id', 'desc')->limit($limit)->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = db_connect()->table('assistant_unanswered')->where('id', $id)->get(1)->getRowArray();

        return is_array($row) ? $row : null;
    }

    public function delete(int $id): bool
    {
        db_connect()->table('assistant_unanswered')->where('id', $id)->delete();

        return db_connect()->affectedRows() > 0;
    }

    /**
     * Turns a question into a new, inactive FAQ (its text in the question's language, also in the
     * site's default language when that one differs, since it is required) and forgets the
     * question. Returns the FAQ id.
     */
    public function toFaq(int $id): ?int
    {
        $row = $this->find($id);

        if ($row === null) {
            return null;
        }

        $db  = db_connect();
        $now = Time::now()->toDateTimeString();
        $db->transStart();
        $db->table('assistant_faqs')->insert(['visibility' => 'public', 'link' => null, 'active' => 0, 'position' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $faq     = (int) $db->insertID();
        $locales = array_unique([(string) $row['locale'], \Modules\Core\Content\ContentTranslations::defaultLocale()]);

        foreach ($locales as $locale) {
            if (in_array($locale, \Modules\Core\Content\ContentTranslations::codes(), true)) {
                $db->table('assistant_faq_translations')->insert(['faq_id' => $faq, 'locale' => $locale, 'question' => mb_substr((string) $row['question'], 0, 255), 'answer' => '', 'keywords' => null, 'link_label' => null]);
            }
        }

        $db->table('assistant_unanswered')->where('id', $id)->delete();
        $db->transComplete();

        return $faq;
    }
}
