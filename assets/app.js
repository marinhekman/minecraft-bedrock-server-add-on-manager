import './stimulus_bootstrap.js';
import * as bootstrap from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

// Copy UUID to clipboard buttons (inside popovers, delegated since popovers are dynamic)
document.addEventListener('click', e => {
    const btn = e.target.closest('.copy-uuid');
    if (!btn) return;

    const uuid = btn.dataset.uuid;
    navigator.clipboard.writeText(uuid).then(() => {
        const original = btn.textContent;
        btn.textContent = '✅';
        setTimeout(() => btn.textContent = original, 1500);
    });
});
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
        const pop = new bootstrap.Popover(el, {
            trigger: 'click',
            html: true,
            placement: 'top',
        });

        // Close popover when clicking outside, but not when selecting text inside it
        document.addEventListener('mouseup', e => {
            const popoverEl = document.querySelector('.popover');
            if (!el.contains(e.target) && (!popoverEl || !popoverEl.contains(e.target))) {
                // Defer check so browser has time to finalize the selection
                setTimeout(() => {
                    const selection = window.getSelection();
                    if (!selection || selection.isCollapsed) {
                        pop.hide();
                    }
                }, 10);
            }
        });
    });
});
