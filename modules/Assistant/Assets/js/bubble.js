// The chat bubble of the public site (Assistant module, D-51-01; docs/assistant.md "The bubble").
//
// Mounted on `[data-component="assistant/bubble"]` (Views/bubble.php). It shows the floating
// button, opens and closes the panel (a non-modal dialog: Escape closes it and the focus goes back
// to the button), sends each question to `POST assistant/ask` (core/http.js: same origin, CSRF
// header) and draws the answer with DOM methods; only the FAQ answer, already sanitised on the
// server with HTMLPurifier, is inserted as HTML. The open state is remembered in sessionStorage
// only. It moves up while the cookie notice is shown, so it never covers it.
import { post } from 'core/http.js';

const STORAGE_KEY = 'kp-assistant-open';

function remember(open) {
    try {
        if (open) {
            window.sessionStorage.setItem(STORAGE_KEY, '1');
        } else {
            window.sessionStorage.removeItem(STORAGE_KEY);
        }
    } catch (error) {
        // Storage may be unavailable (private mode): the panel simply starts closed.
    }
}

function remembered() {
    try {
        return window.sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch (error) {
        return false;
    }
}

function element(tag, className, text) {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined && text !== null) {
        node.textContent = text;
    }

    return node;
}

function link(href, label, className) {
    const anchor = element('a', className, label);
    anchor.href = href;

    return anchor;
}

export function mount(root, options = {}) {
    const texts = options.texts || {};
    const toggle = root.querySelector('[data-assistant-toggle]');
    const toggleLabel = root.querySelector('[data-assistant-toggle-label]');
    const icon = root.querySelector('[data-assistant-icon]');
    const panel = root.querySelector('[data-assistant-panel]');
    const close = root.querySelector('[data-assistant-close]');
    const log = root.querySelector('[data-assistant-log]');
    const scroller = root.querySelector('[data-assistant-scroll]');
    const form = root.querySelector('[data-assistant-form]');
    const input = root.querySelector('[data-assistant-input]');
    const suggestions = root.querySelector('[data-assistant-suggestions]');
    let busy = false;

    if (!toggle || !panel || !log || !form || !input) {
        return;
    }

    if (options.css && !document.querySelector('link[data-assistant-css]')) {
        const sheet = document.createElement('link');
        sheet.rel = 'stylesheet';
        sheet.href = options.css;
        sheet.dataset.assistantCss = '';
        document.head.append(sheet);
    }

    function setOpen(open, focus = true) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        root.classList.toggle('is-open', open);

        if (toggleLabel) {
            toggleLabel.textContent = open ? toggleLabel.dataset.closeLabel : toggleLabel.dataset.openLabel;
        }

        if (icon) {
            icon.className = open ? 'bi bi-x-lg' : 'bi bi-chat-dots-fill';
        }

        remember(open);

        if (focus) {
            (open ? input : toggle).focus();
        }
    }

    function scrollDown() {
        if (scroller) {
            scroller.scrollTop = scroller.scrollHeight;
        }
    }

    function say(who, build) {
        const item = element('li', `assistant-message assistant-${who}`);
        item.append(element('span', 'visually-hidden', `${who === 'you' ? texts.you : texts.bot}: `));
        build(item);
        log.append(item);
        scrollDown();

        return item;
    }

    function contactLink(item, url) {
        if (url) {
            const paragraph = element('p', 'mb-0 mt-2');
            paragraph.append(link(url, texts.contact, 'btn btn-sm btn-outline-primary'));
            item.append(paragraph);
        }
    }

    function draw(answer) {
        say('bot', (item) => {
            if (answer.type === 'faq' && answer.faq) {
                item.append(element('p', 'fw-semibold mb-1', answer.faq.question));

                if (answer.faq.answer) {
                    // Sanitised on the server (HtmlSanitizer); never text typed by the visitor.
                    // Parsed into an inert document (scripts never run there), then moved in.
                    const body = element('div', 'assistant-answer');
                    const parsed = new DOMParser().parseFromString(answer.faq.answer, 'text/html');
                    body.append(...Array.from(parsed.body.childNodes));
                    item.append(body);
                }

                if (answer.faq.link && answer.faq.link.href) {
                    const paragraph = element('p', 'mb-0 mt-1');
                    paragraph.append(link(answer.faq.link.href, answer.faq.link.label || texts.more));
                    item.append(paragraph);
                }

                return;
            }

            if (answer.message) {
                item.append(element('p', 'mb-1', answer.message));
            }

            if (answer.type === 'results' && Array.isArray(answer.results)) {
                const list = element('ul', 'assistant-results list-unstyled mb-0');

                for (const result of answer.results) {
                    const entry = element('li', 'mb-2');
                    entry.append(link(result.url, result.title, 'fw-semibold'));

                    if (result.source) {
                        entry.append(element('span', 'small text-body-secondary ms-1', `· ${result.source}`));
                    }

                    if (result.excerpt) {
                        entry.append(element('div', 'small text-body-secondary', result.excerpt));
                    }

                    list.append(entry);
                }

                item.append(list);
            }

            contactLink(item, answer.contact);
        });
    }

    async function ask(question) {
        const text = question.trim();

        if (text === '' || busy) {
            return;
        }

        busy = true;

        if (suggestions) {
            suggestions.hidden = true; // offered when the panel opens, out of the way afterwards
        }

        say('you', (item) => item.append(document.createTextNode(text)));
        input.value = '';

        const waiting = say('bot', (item) => {
            item.classList.add('assistant-thinking');
            item.append(document.createTextNode(texts.thinking || '…'));
        });

        try {
            const answer = await post(options.askUrl || form.action, { json: { q: text }, silent: true });
            waiting.remove();
            draw(answer && typeof answer === 'object' ? answer : { type: 'none', message: texts.error });
        } catch (error) {
            waiting.remove();
            const message = error && error.data && typeof error.data.message === 'string' ? error.data.message : texts.error;
            say('bot', (item) => item.append(element('p', 'mb-0', message)));
        } finally {
            busy = false;
            input.focus();
        }
    }

    toggle.addEventListener('click', () => setOpen(panel.hidden));
    close?.addEventListener('click', () => setOpen(false));

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            event.preventDefault();
            setOpen(false);
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        ask(input.value);
    });

    suggestions?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-assistant-suggestion]');

        if (button) {
            ask(button.textContent || '');
        }
    });

    // Above the cookie notice while it is shown (it is fixed at the bottom of the page).
    const lift = () => {
        const notice = document.querySelector('[data-cookie-notice]');
        const height = notice && notice.isConnected && notice.offsetParent !== null ? notice.getBoundingClientRect().height : 0;
        root.style.setProperty('--assistant-lift', `${Math.ceil(height)}px`);
    };
    lift();
    new MutationObserver(lift).observe(document.body, { childList: true, subtree: false });
    window.addEventListener('resize', lift);

    root.hidden = false;
    setOpen(remembered(), false);
}
