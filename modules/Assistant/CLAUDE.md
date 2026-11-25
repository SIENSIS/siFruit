# Assistant module

> Read this file first when you resume work on this module, and keep it current (root `CLAUDE.md`,
> "Developing a module").

**Purpose:** the classic chat bubble of the public site, without any external service (session
51; D-51-01, D-51-02, D-51-03, D-51-04). Visitors ask a question; the assistant answers from the
frequently asked questions kept here, otherwise suggests site search results (session 50),
otherwise offers the contact form (session 48). Disabled by default; the bubble is off until
`Assistant.enabled`. Full description: `docs/assistant.md`.

## Resources and permissions

- `assistant-faqs` (crud − show, import, trash, restore, purge): `backend/assistant/faqs`.
  Holders of `assistant-faqs.add` also see `backend/assistant/unanswered` (create an FAQ from a
  question, delete it).
- Grants: admin `assistant-faqs.*`; editor `assistant-faqs.{list,add,edit}`.
- Menu "Assistant" (sidebar, 46) with "Unanswered questions" under it (route `assistant-unanswered`).
- Settings (Settings › Assistant): `enabled` (off), `greeting` (translated), `results` (3),
  `sources` (multiselect, `Settings/SourceOptions.php`; empty = every source but the FAQs),
  `offerContact` (on), `logUnanswered` (off), `unansweredDays` (90, min 7).
- Search source `assistant.faqs` (`Search/FaqSearch.php`). Task `Tasks/PurgeUnanswered.php` (03:50).
- Public: `POST assistant/ask` (`Controllers/Ask.php`), `GET faq` (`Controllers/PublicFaq.php`: the list, or a 301 to the page of `Assistant.faqPage`, D-51-03).
- Section type `assistant.faqs` (`Sections/FaqListSection.php`, view `Views/sections/faqs.php`, shared list `Views/partials/list.php`, thumbnail `Assets/img/sections/faqs.svg`); setting `faqPage` (`Services/FaqPage.php`, `Settings/FaqPageOptions.php`, link type `pages.page`, no dependency on Pages).
- Tables: `assistant_faqs`, `assistant_faq_translations`, `assistant_faq_groups`,
  `assistant_unanswered` (one migration each). No API, widgets, notifications, CSP sources or
  outbound hosts.

## Main pieces

- `Services/Faqs.php` (who reads an FAQ: active + the Core's `Audience`; texts with the
  content fallback; links), `Services/Answerer.php` (FAQ → search results → not found; thresholds
  `MIN_SHARE` 0.6, `MIN_WEIGHT` 1.5), `Services/UnansweredLog.php` (anonymise, record, purge,
  to FAQ), `Services/Bubble.php` + `Views/bubble.php` (added through the Core event
  `themeFloating` in `Config/Events.php`).
- `Controllers/Faqs.php` (kpcrudy; answers sanitised before kpcrudy reads them; link checked;
  `afterWrite` → `FaqSearch::sync()`), `Controllers/Unanswered.php` + `Views/unanswered.php`,
  `Controllers/PublicFaq.php` + `Views/faq.php`.
- Front end: `Assets/js/bubble.js` (`data-component="assistant/bubble"`), `Assets/css/bubble.css`.
- Demo: `Database/Seeds/AssistantDemoSeeder.php` (five FAQs in ca/es/en, one private).
- Texts: `Language/{ca,es,en}/Assistant.php` (same keys).

## Decisions

D-51-01 and D-51-02 decided by the owner and applied; D-51-03 decided and applied (the FAQs on a
page the editor chooses: section `assistant.faqs` + `Assistant.faqPage`, `faq` as fallback and
redirect; the Core event `themeFloating`); D-51-04 accepted (how it was built). See `docs/decisions.md`.

## Unused files (session 55)

`Storage\FaqFileReferences` (manifest `$fileReferences`, D-55-01, `docs/storage.md` "Unused files") gives
the files the module uses: images and links in FAQ answers and the FAQ links (no folder of its own). Keep it complete when the module starts storing a file
somewhere else: a file it forgets can be listed (and deleted) as unused.

## Open tasks

- A link-type picker for the FAQ link (today an internal path or an `https://` address).

## How to test

```bash
vendor/bin/phpunit modules/Assistant     # AnswerTest, AskEndpointTest, AssistantModuleTest
```

By hand: `php spark modules:enable Assistant`, `php spark modules:demo Assistant`, Settings ›
Assistant › "Show the chat bubble…", then ask on the public site ("A quina hora obriu?",
"organització", "Teniu un drac de companyia?"); `backend/assistant/faqs`,
`backend/assistant/unanswered`, `/faq`. E2E: `tests/e2e/assistant.spec.mjs`.
