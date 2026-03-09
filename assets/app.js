import './stimulus_bootstrap.js';
import * as bootstrap from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

function initPopovers() {
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
        const pop = new bootstrap.Popover(el, {
            trigger: 'click',
            html: true,
            placement: 'top',
        });

        document.addEventListener('mouseup', e => {
            const popoverEl = document.querySelector('.popover');
            if (!el.contains(e.target) && (!popoverEl || !popoverEl.contains(e.target))) {
                setTimeout(() => {
                    const selection = window.getSelection();
                    if (!selection || selection.isCollapsed) {
                        pop.hide();
                    }
                }, 10);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initPopovers);
document.addEventListener('turbo:load', initPopovers);

// ── Status polling ────────────────────────────────────────────────────────────

function updateStatusBadges(card, loadedUuids) {
    card.querySelectorAll('tr[data-uuid]').forEach(row => {
        const uuid  = row.dataset.uuid;
        const badge = row.querySelector('.status-badge');
        if (!badge) return;

        const isDisabled = badge.classList.contains('bg-secondary');
        if (isDisabled) return; // never change disabled badges via polling

        if (loadedUuids.includes(uuid)) {
            badge.className = 'badge bg-info status-badge';
            badge.textContent = '✅ Loaded';
        } else {
            badge.className = 'badge bg-success status-badge';
            badge.textContent = 'Enabled';
        }
    });
}

function allEnabledAreLoaded(card, loadedUuids) {
    let result = true;
    card.querySelectorAll('tr[data-uuid]').forEach(row => {
        const badge = row.querySelector('.status-badge');
        if (!badge || badge.classList.contains('bg-secondary')) return;
        if (!loadedUuids.includes(row.dataset.uuid)) result = false;
    });
    return result;
}

function resetToEnabled(card) {
    card.querySelectorAll('tr[data-uuid] .status-badge').forEach(badge => {
        if (badge.classList.contains('bg-secondary')) return;
        badge.className = 'badge bg-success status-badge';
        badge.textContent = 'Enabled';
    });
}

function pollStatus(card) {
    const url = card.dataset.statusUrl;
    if (!url) return;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const loadedUuids = data.loadedUuids || [];
            const startedAt   = String(data.startedAt);

            // Detect restart — startedAt changed since last poll
            if (card.dataset.lastStartedAt && card.dataset.lastStartedAt !== startedAt) {
                resetToEnabled(card);
                card.dataset.pollDone = 'false';
            }
            card.dataset.lastStartedAt = startedAt;

            // Server not running yet — keep polling
            if (!data.running) {
                card.dataset.pollDone = 'false';
                return;
            }

            // No loaded uuids yet — server still starting up, keep polling
            if (loadedUuids.length === 0) {
                card.dataset.pollDone = 'false';
                return;
            }

            updateStatusBadges(card, loadedUuids);

            // Stop polling once all enabled packs are confirmed loaded
            if (allEnabledAreLoaded(card, loadedUuids)) {
                card.dataset.pollDone = 'true';
            }
        })
        .catch(() => {
            card.dataset.pollDone = 'false';
        });
}

let pollingInterval = null;

function initPolling() {
    const cards = document.querySelectorAll('.card[data-status-url]');
    if (cards.length === 0) return;

    // Clear any existing interval from previous Turbo navigation
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
    }

    // Immediate first poll
    cards.forEach(pollStatus);

    // Then every 10 seconds
    pollingInterval = setInterval(() => {
        cards.forEach(card => {
            if (card.dataset.pollDone !== 'true') {
                pollStatus(card);
            }
        });
    }, 10000);
}

// Fire on initial page load
document.addEventListener('DOMContentLoaded', initPolling);

// Fire on Turbo Drive navigation (replaces DOMContentLoaded for subsequent visits)
document.addEventListener('turbo:load', initPolling);
