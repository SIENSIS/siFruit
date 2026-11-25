# Chat assistant (Assistant module)

The classic **chat bubble** on the public site, without any external service (decisions D-51-01,
D-51-02, D-51-03): a floating button opens a small chat panel; the visitor writes a question and
the assistant answers from **frequently asked questions** kept in the back office and from the
**site search** ([`search.md`](search.md)), with links, and offers the **contact form**
([`contact.md`](contact.md)) when nothing fits. No AI, no outbound request, any hosting.

The module is **disabled by default**; once enabled, the bubble stays off until Settings ›
Assistant › "Show the chat bubble on the public site" (`Assistant.enabled`).

## For editors

- **Back office › Assistant** (`backend/assistant/faqs`, resource `assistant-faqs`): the
  questions. Per site language: the question, the answer (the Core's rich-text editor; sanitised
  with HTMLPurifier), **other ways of asking it and keywords** (one per line: they are what makes
  the assistant find the answer) and the text of the link. Common fields: an optional link (an
  address of this site starting with `/`, or an `https://` address, checked like menu items), who
  sees it (everyone, signed-in users, members of some groups), order and active.
- **Assistant › Unanswered questions** (`backend/assistant/unanswered`, holders of
  `assistant-faqs.add`): only while `Assistant.logUnanswered` is on (see "Privacy"). "Create an
  FAQ" turns one into an **inactive** FAQ (write the answer, then activate it); "Delete" forgets
  it.
- **The FAQ page** (D-51-03): put the section **"Preguntes freqüents"** (`assistant.faqs`) on a page
  of the Pages module — with the address you want in each language (`/preguntes-frequents`,
  `/es/dudas`…), edited in the back office, no code — and choose that page in Settings ›
  Assistant › "Frequently asked questions page" (`Assistant.faqPage`). The site search's FAQ
  results link to `/faq#faq-<id>`, and **`faq`** (with the language prefix) redirects (301) to the
  chosen page in the visitor's language (the browser keeps the anchor). Without a chosen page (or
  with it unpublished, or Pages disabled) `faq` lists the FAQs itself. The section and the page
  show only the FAQs the reader may read, each with an anchor `#faq-<id>`.

| Permission | Admin | Editor |
|---|---|---|
| `assistant-faqs.list`, `.add`, `.edit` | ✓ | ✓ |
| `assistant-faqs.delete`, `.export` | ✓ | |

## Settings (Settings › Assistant)

| Setting | Default | What |
|---|---|---|
| `Assistant.enabled` | off | The bubble on every public page |
| `Assistant.greeting` | "Hola! En què us puc ajudar?…" | The first message, per site language (`translated`) |
| `Assistant.results` | 3 | Search results suggested when no FAQ fits (1–10) |
| `Assistant.sources` | (empty = all) | The site search sources it may suggest (the FAQs are always used first) |
| `Assistant.offerContact` | on | A link to the contact form when nothing fits (if the site has one) |
| `Assistant.logUnanswered` | **off** | Keep the anonymised unanswered questions (D-51-02) |
| `Assistant.unansweredDays` | 90 | Delete them after this many days (7–365) |
| `Assistant.faqPage` | (none) | The page with the section `assistant.faqs`; `faq` redirects there (D-51-03) |

## How it answers

`Modules\Assistant\Services\Answerer`, always in the visitor's language (the language of the URL:
the bubble posts to `site_url('assistant/ask')`, `/es/assistant/ask` on the Spanish site) and with
the visitor's visibility (the search index filters by audience, so a private page or FAQ never
reaches a guest):

1. **An FAQ** — the best document of the source `assistant.faqs` when it is close enough: it
   matches at least **60 %** of the question's terms (`MIN_SHARE`) and they weigh on average
   **1.5** or more (`MIN_WEIGHT`; question ×5, keywords ×3, answer ×1). So a word that only
   appears in an answer never picks that FAQ; a question or a keyword does. → its answer (HTML,
   sanitised again when shown) and link.
2. **Search results** — otherwise up to `Assistant.results` results of `Assistant.sources` (every
   source but the FAQs when empty): title, excerpt, address, source label.
3. **Not found** — otherwise a sorry message.

The contact form is offered last (results and not found) when `Assistant.offerContact` is on and a
route named `contact` exists whose module is enabled (`Modules\Core\Frontend\ContactLink`). The
question goes through the index's normalisation ([`search.md`](search.md), "Terms"): accents,
capitals and common words do not matter. A question with no useful word gets "write a little more".

## The endpoint

`POST assistant/ask` (`Controllers/Ask.php`), field `q` (form or JSON):

- CSRF (the global filter; the bubble sends the header through `core/http.js`);
- at most **300 characters** (422 otherwise), a string (422 otherwise);
- at most **20 questions per minute per IP address and per session** (`RateLimiter`, name
  `assistantAsk`; 429 with `Retry-After` and a security entry `Assistant.throttled`);
- **404** while `Assistant.enabled` is off or the module is disabled;
- JSON `{type: faq|results|none|empty, message, faq?, results?, contact}`, `Cache-Control:
  private, no-store`, `X-Robots-Tag: noindex`; the question is never echoed;
- a plain form post (no JavaScript) is redirected to the search results (`search?q=…`).

## The bubble

- Printed by the public layout through **`theme_floating()`** (Core event `themeFloating`,
  D-51-03, [`themes.md`](themes.md) "Floating elements"): `Config/Events.php` adds
  `Views/bubble.php` (`Services/Bubble.php`) when the module is enabled and `Assistant.enabled` is
  on. A theme can override the view in `themes/<slug>/Views/overrides/Assistant/bubble.php`.
- The ES module `assistant/bubble` (`Assets/js/bubble.js`, styles `Assets/css/bubble.css` added by
  the component): hidden until it mounts (no JavaScript, no bubble — the search box remains); a
  round button with an accessible name and visible focus; the panel is a non-modal dialog
  (`role="dialog"`, labelled), the conversation an `<ol aria-live="polite">`; Enter sends, Escape
  closes and the focus goes back to the button; the suggested questions (the first four FAQs the
  visitor may read) are buttons, hidden after the first question; phone width: a sheet across the
  bottom; dark mode through Bootstrap's variables; `prefers-reduced-motion` respected; above the
  cookie notice while it is shown; hidden when printing. The open state is remembered in
  `sessionStorage` only.
- Nothing inline (CSP): answers are drawn with DOM methods (`textContent`); only the FAQ answer,
  sanitised on the server with HTMLPurifier, is inserted as HTML.

## Privacy (D-51-02)

- **Nothing is stored by default**: no conversation, no question, no IP address.
- With `Assistant.logUnanswered` on, only the questions **without an answer** are kept
  (`assistant_unanswered`): the text with email addresses → `[email]` and numbers of six or more
  digits (phones, ids; spaces, dots and dashes allowed between digits) → `[######]`, the language,
  the **day** (no time) and how many times it was asked that day. No IP, user, session or cookie.
  The bubble tells visitors ("Questions without an answer are kept anonymously…").
- The scheduled task `PurgeUnanswered` (daily 03:50) deletes the questions older than
  `Assistant.unansweredDays` (never fewer than 7), also after logging was turned off.
- Creating an FAQ from a question and deleting one are written to the activity log.

## Tables

`assistant_faqs` (visibility, link, active, position), `assistant_faq_translations` (question,
answer, keywords, link label per locale), `assistant_faq_groups`, `assistant_unanswered` (one
migration each).

## For developers

- The FAQs are the search source `assistant.faqs` (`Search/FaqSearch.php`): one document per site
  language, `FaqSearch::sync()` after every write of the CRUD and when an unanswered question
  becomes an FAQ; `php spark search:reindex Assistant` rebuilds it.
- Other modules' content reaches the assistant through the site search: declare a search source
  ([`search.md`](search.md)) and it can be chosen in `Assistant.sources`.

## Try it

```bash
php spark modules:enable Assistant
php spark modules:demo Assistant      # five FAQs (one for signed-in users)
```

Then Settings › Assistant › "Show the chat bubble…", open the public site and ask "A quina hora
obriu?", "organització" (search results) or "Teniu un drac de companyia?" (not found, contact).
Tests: `vendor/bin/phpunit modules/Assistant`; e2e `tests/e2e/assistant.spec.mjs`.
