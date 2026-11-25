/**
 * Rich-text editor component: <textarea data-component="core/forms/editor" data-options='{…}'>.
 *
 * The vendored Quill 2 (BSD-3-Clause, public/assets/vendor/quill) is loaded on demand from the
 * URLs in the options ({"script": …, "style": …, "css": …}); it works under the enforced CSP
 * (no inline styles or scripts: it only uses the CSSOM). The textarea stays the form value: it is
 * hidden and kept in sync, so the form still posts `name`, and without JavaScript (or when the
 * editor fails to load) the textarea is used as is. The server sanitises the HTML with
 * HTMLPurifier in any case (Modules\Core\Security\HtmlSanitizer), which keeps alignment as the
 * classes text-center / text-right / text-justify this editor writes.
 *
 * With the `images` option (a file-picker field, RichTextEditor::attrs($images)) the toolbar has
 * "Insert image": the picker dialog uploads (signed token, Storage service rules and re-encoding)
 * or chooses an image and asks for its alt text; pasted or dropped images go through the same
 * upload. The server keeps only images of the site itself.
 *
 * "Insert an icon" (D-49-01) opens the Core's icon picker (core/forms/icon-picker.js) and inserts
 * the icon at the cursor as an inline embed written as `<i class="bi bi-<name>" aria-hidden="true"></i>`
 * (deleted like a character). Icons are decorative: their meaning goes in words next to them.
 * The sanitiser keeps on `<i>` only `bi` plus a `bi-<name>` of the vendored list and aria-hidden.
 */
import { t } from 'core/config.js';
import { toast } from 'core/ui/toast.js';
import { pickDialog, uploadFile } from 'core/forms/file-picker.js';
import { pickIcon } from 'core/forms/icon-picker.js';

const FORMATS = ['header', 'bold', 'italic', 'underline', 'strike', 'link', 'list', 'blockquote', 'code-block', 'align', 'icon'];
const ICON_NAME = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const TOOLBAR = [
    [{ header: [2, 3, 4, false] }],
    ['bold', 'italic', 'underline', 'strike'],
    [{ list: 'ordered' }, { list: 'bullet' }, 'blockquote', 'code-block'],
    [{ align: [] }],
    ['link', 'icon', 'clean'],
];
const LABELS = {
    'ql-header': 'editor.heading',
    'ql-bold': 'editor.bold',
    'ql-italic': 'editor.italic',
    'ql-underline': 'editor.underline',
    'ql-strike': 'editor.strike',
    'ql-list[value="ordered"]': 'editor.ordered',
    'ql-list[value="bullet"]': 'editor.bullet',
    'ql-blockquote': 'editor.quote',
    'ql-code-block': 'editor.code',
    'ql-align': 'editor.align',
    'ql-link': 'editor.link',
    'ql-image': 'editor.image',
    'ql-icon': 'editor.icon',
    'ql-clean': 'editor.clean',
};

const loading = new Map();

function load(tag, url) {
    if (!loading.has(url)) {
        loading.set(url, new Promise((resolve, reject) => {
            const element = document.createElement(tag);

            if (tag === 'link') {
                element.rel = 'stylesheet';
                element.href = url;
            } else {
                element.src = url;
                element.async = true;
            }

            element.addEventListener('load', () => resolve());
            element.addEventListener('error', () => reject(new Error('Cannot load ' + url)));
            document.head.appendChild(element);
        }));
    }

    return loading.get(url);
}

let registered = false;

/**
 * Alignment as classes text-center / text-right / text-justify instead of Quill's ql-align-*.
 */
function registerFormats(Quill) {
    if (registered) {
        return;
    }

    registered = true;
    // Quill's own registry (Quill.import), not an ES module import.
    const Parchment = Quill['import']('parchment');
    const Align = new Parchment.ClassAttributor('align', 'text', {
        scope: Parchment.Scope.BLOCK,
        whitelist: ['center', 'right', 'justify'],
    });
    Quill.register({ 'formats/align': Align, 'attributors/class/align': Align }, true);

    // An icon of Bootstrap Icons: an inline embed <i class="bi bi-<name>" aria-hidden="true">.
    const Embed = Quill['import']('blots/embed');

    class IconBlot extends Embed {
        static create(value) {
            const node = super.create(value);
            node.classList.add('bi-' + (ICON_NAME.test(String(value)) ? value : 'question'));
            node.setAttribute('aria-hidden', 'true');

            return node;
        }

        static value(node) {
            const name = Array.from(node.classList).find((item) => item.startsWith('bi-'));

            return name ? name.slice(3) : '';
        }

        html() {
            const name = IconBlot.value(this.domNode);

            return ICON_NAME.test(name) ? '<i class="bi bi-' + name + '" aria-hidden="true"></i>' : '';
        }
    }

    IconBlot.blotName = 'icon';
    IconBlot.tagName = 'I';
    IconBlot.className = 'bi';
    Quill.register({ 'formats/icon': IconBlot }, true);
}

function html(quill) {
    if (quill.getText().trim() === '' && quill.root.querySelector('img, i.bi') === null) {
        return '';
    }

    // getSemanticHTML() writes real <ul>/<ol> and <pre>; it also turns spaces into &nbsp;.
    return quill.getSemanticHTML().replace(/&nbsp;/g, ' ');
}

function label(toolbar) {
    for (const [selector, key] of Object.entries(LABELS)) {
        const [name, value] = selector.split('[');

        for (const control of toolbar.querySelectorAll('.' + name + (value ? '[' + value : ''))) {
            const text = t(key);
            control.setAttribute('title', text);

            // A picker (Quill's span replacing a <select>) has no role: its name goes on the
            // focusable label (role="button"), which axe/screen readers announce.
            const pickerLabel = control.classList.contains('ql-picker') ? control.querySelector('.ql-picker-label') : null;
            (pickerLabel ?? control).setAttribute('aria-label', text);
            if (pickerLabel !== null) control.removeAttribute('aria-label');
        }
    }
}

export async function mount(textarea, options = {}) {
    if (!(textarea instanceof HTMLTextAreaElement) || !options.script || !options.style) {
        return;
    }

    try {
        await Promise.all([load('link', options.style), options.css ? load('link', options.css) : null, load('script', options.script)]);
    } catch (error) {
        console.warn(error);
        textarea.dataset.editor = 'plain';

        return;
    }

    const Quill = window.Quill;

    if (typeof Quill !== 'function') {
        textarea.dataset.editor = 'plain';

        return;
    }

    registerFormats(Quill);

    const wrapper = document.createElement('div');
    wrapper.className = 'kp-editor';
    const area = document.createElement('div');
    area.className = 'kp-editor-content';
    wrapper.appendChild(area);
    textarea.insertAdjacentElement('afterend', wrapper);
    textarea.classList.add('d-none');
    textarea.dataset.editor = 'quill';

    const images = options.images && options.images.token ? options.images : null;
    const toolbar = images ? TOOLBAR.map((group) => (group.includes('link') ? ['link', 'image', 'icon', 'clean'] : group)) : TOOLBAR;
    let quill = null;
    const handlers = {
        icon: async () => {
            const range = quill.getSelection(true);
            const name = await pickIcon({ title: t('editor.icon'), help: t('iconPicker.decorative') });
            const index = range ? range.index : quill.getLength() - 1;

            if (name) {
                quill.insertEmbed(index, 'icon', name, 'user');
                quill.setSelection(index + 1, 0, 'silent');
            } else {
                quill.setSelection(index, 0, 'silent');
            }
        },
    };
    const modules = { toolbar: { container: toolbar, handlers } };

    function insertImage(item, alt, index = null) {
        const range = index !== null ? { index } : quill.getSelection(true) || { index: quill.getLength() };
        quill.updateContents([{ retain: range.index }, { insert: { image: item.url }, attributes: alt ? { alt } : {} }], 'user');
        quill.setSelection(range.index + 1, 0, 'silent');
    }

    if (images) {
        handlers.image = async () => {
            const range = quill.getSelection(true);
            const result = await pickDialog(images, { alt: true });

            if (result !== null) {
                insertImage(result.item, result.alt, range ? range.index : null);
            }
        };
        // Pasted or dropped images: the same signed upload as the dialog (no data: URLs).
        modules.uploader = {
            mimetypes: ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
            handler: async (range, files) => {
                let index = range.index;

                for (const file of files) {
                    try {
                        insertImage(await uploadFile(images, file), '', index);
                        index += 1;
                    } catch (error) {
                        toast(error.message, { type: 'danger' });
                    }
                }
            },
        };
    } else {
        modules.uploader = { handler: () => {} };
    }

    quill = new Quill(area, {
        theme: 'snow',
        formats: images ? [...FORMATS, 'image'] : FORMATS,
        modules,
        placeholder: textarea.getAttribute('placeholder') || '',
    });

    quill.clipboard.dangerouslyPasteHTML(textarea.value || '', 'silent');
    quill.root.setAttribute('role', 'textbox');
    quill.root.setAttribute('aria-multiline', 'true');
    quill.root.setAttribute('lang', textarea.getAttribute('lang') || document.documentElement.lang);

    const labelElement = textarea.id ? document.querySelector('label[for="' + CSS.escape(textarea.id) + '"]') : null;

    if (labelElement !== null) {
        labelElement.id = labelElement.id || textarea.id + '-label';
        quill.root.setAttribute('aria-labelledby', labelElement.id);
        // The label points at the hidden textarea: move the focus to the editor instead.
        labelElement.addEventListener('click', () => quill.focus());
    }

    const toolbarElement = wrapper.querySelector('.ql-toolbar');

    if (toolbarElement !== null) {
        // Quill has no glyph for the custom "icon" button.
        for (const button of toolbarElement.querySelectorAll('button.ql-icon')) {
            const glyph = document.createElement('i');
            glyph.className = 'bi bi-emoji-smile';
            glyph.setAttribute('aria-hidden', 'true');
            button.replaceChildren(glyph);
        }

        label(toolbarElement);

        // Heading picker texts (Quill prints data-label from CSS).
        for (const item of toolbarElement.querySelectorAll('.ql-header .ql-picker-item, .ql-header .ql-picker-label')) {
            const level = item.getAttribute('data-value');
            item.setAttribute('data-label', level ? t('editor.heading' + level) : t('editor.normal'));
            if (item.classList.contains('ql-picker-item')) item.setAttribute('aria-label', item.getAttribute('data-label'));
        }
    }

    // Texts of the link tooltip (Quill prints them from CSS; the editor CSS reads these attributes).
    const tooltip = wrapper.querySelector('.ql-tooltip');

    if (tooltip !== null) {
        tooltip.dataset.visit = t('editor.visit');
        tooltip.dataset.enter = t('editor.enterLink');
        tooltip.dataset.save = t('editor.save');
        tooltip.dataset.edit = t('editor.edit');
        tooltip.dataset.remove = t('editor.remove');
        const input = tooltip.querySelector('input[type="text"]');

        if (input !== null) {
            input.dataset.link = 'https://';
            input.setAttribute('aria-label', t('editor.link'));
        }
    }

    if (textarea.classList.contains('is-invalid')) {
        wrapper.classList.add('is-invalid');
    }

    let initial = html(quill);
    quill.on('text-change', () => {
        const value = html(quill);

        if (value !== initial) {
            initial = value;
            textarea.value = value;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    textarea.form?.addEventListener('submit', () => {
        textarea.value = html(quill);
    });

    return quill;
}
