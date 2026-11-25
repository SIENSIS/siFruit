/**
 * Copy button: `data-component="core/ui/copy"` with `data-copy-target="<id of an element>"`
 * copies that element's text (a `<textarea>`'s value) to the clipboard and shows a toast with
 * `data-options='{"copied": "…"}'`. Used by System information ("Copy report for support").
 */
import { toast } from 'core/ui/toast.js';

export function mount(button, options) {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget || '');

        if (!target) {
            return;
        }

        const text = 'value' in target ? String(target.value) : String(target.textContent || '');

        try {
            await navigator.clipboard.writeText(text);
            toast(String(options.copied || ''), { type: 'success' });
        } catch (error) {
            // No clipboard (plain HTTP, permission): select the text so it can be copied by hand.
            if (typeof target.select === 'function') {
                target.removeAttribute('hidden');
                target.select();
            }
        }
    });
}
