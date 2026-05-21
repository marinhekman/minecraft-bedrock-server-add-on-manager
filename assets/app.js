import './stimulus_bootstrap.js';
import * as bootstrap from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

// ── Bootstrap popovers ────────────────────────────────────────────────────────

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

// ── Host stats ────────────────────────────────────────────────────────────────

function updateHostStats() {
    fetch('/host/stats')
        .then(r => r.json())
        .then(data => {
            const text = document.getElementById('hostMemText');
            const bar  = document.getElementById('hostMemBar');
            if (!text || !bar || data.error) return;
            text.textContent = `${data.usedMb} MB / ${data.totalMb} MB (${data.usedPercent}%)`;
            bar.style.width  = data.usedPercent + '%';
            bar.className    = 'progress-bar ' + (
                data.usedPercent > 90 ? 'bg-danger' :
                data.usedPercent > 70 ? 'bg-warning' : 'bg-info'
            );
        })
        .catch(() => {});
}

let hostStatsInterval = null;

function initHostStats() {
    if (!document.getElementById('hostStatsCard')) return;
    if (hostStatsInterval) clearInterval(hostStatsInterval);
    updateHostStats();
    hostStatsInterval = setInterval(updateHostStats, 10000);
}

document.addEventListener('DOMContentLoaded', initHostStats);
document.addEventListener('turbo:load', initHostStats);

// ── Command list (from server-side text file) ─────────────────────────────────

let cachedCommands = [];

function loadCommands() {
    fetch('/commands')
        .then(r => r.json())
        .then(commands => {
            cachedCommands = commands;
            document.querySelectorAll('[data-command-form]').forEach(form => {
                renderCommandList(form.dataset.commandForm);
            });
        })
        .catch(() => {});
}

function renderCommandList(serverName) {
    const datalist = document.getElementById(`commandHistory${serverName}`);
    if (!datalist) return;
    datalist.innerHTML = '';
    cachedCommands.forEach(cmd => {
        const opt = document.createElement('option');
        opt.value = cmd;
        datalist.appendChild(opt);
    });
}

function initCommandForms() {
    document.querySelectorAll('[data-command-form]').forEach(form => {
        renderCommandList(form.dataset.commandForm);
    });
}

document.addEventListener('DOMContentLoaded', () => { loadCommands(); initCommandForms(); });
document.addEventListener('turbo:load', () => { loadCommands(); initCommandForms(); });

// ── Uptime formatter ──────────────────────────────────────────────────────────

function formatUptime(startedAt) {
    if (!startedAt) return '–';
    const secs = Math.floor(Date.now() / 1000) - startedAt;
    const d = Math.floor(secs / 86400);
    const h = Math.floor((secs % 86400) / 3600);
    const m = Math.floor((secs % 3600) / 60);
    if (d > 0) return `${d}d ${h}h ${m}m`;
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

function startUptimeTicker() {
    setInterval(() => {
        document.querySelectorAll('.card[data-status-url]').forEach(card => {
            const startedAt = card.dataset.lastStartedAt;
            const badge = card.querySelector('.stat-uptime');
            if (badge && startedAt) {
                badge.textContent = `UP ${formatUptime(parseInt(startedAt))}`;
            }
        });
    }, 1000);
}

document.addEventListener('DOMContentLoaded', startUptimeTicker);
document.addEventListener('turbo:load', startUptimeTicker);

// ── Status polling ────────────────────────────────────────────────────────────

function updateStatusBadges(card, loadedUuids) {
    card.querySelectorAll('tr[data-uuid]').forEach(row => {
        const uuid  = row.dataset.uuid;
        const badge = row.querySelector('.status-badge');
        if (!badge) return;
        if (badge.classList.contains('bg-secondary')) return;
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

            // Detect restart
            if (card.dataset.lastStartedAt && card.dataset.lastStartedAt !== startedAt) {
                resetToEnabled(card);
                card.dataset.pollDone = 'false';
            }
            card.dataset.lastStartedAt = startedAt;

            // Update stats badges
            const cpuBadge = card.querySelector('.stat-cpu');
            const memBadge = card.querySelector('.stat-mem');
            if (cpuBadge && memBadge) {
                if (data.stats) {
                    cpuBadge.textContent = `CPU ${data.stats.cpu}%`;
                    memBadge.textContent = `MEM ${data.stats.memUsageMb}MB / ${data.stats.memLimitMb}MB (${data.stats.memPercent}%)`;
                } else {
                    cpuBadge.textContent = 'CPU –';
                    memBadge.textContent = 'MEM –';
                }
            }
            const playersBadge = card.querySelector('.stat-players');
            if (playersBadge) {
                playersBadge.textContent = `👥 ${data.playerCount ?? 0}`;
            }

            if (!data.running || loadedUuids.length === 0) {
                card.dataset.pollDone = 'false';
                return;
            }

            updateStatusBadges(card, loadedUuids);

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

    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
    }

    cards.forEach(pollStatus);

    pollingInterval = setInterval(() => {
        cards.forEach(card => {
            if (card.dataset.pollDone !== 'true') {
                pollStatus(card);
            }
        });
    }, 10000);
}

document.addEventListener('DOMContentLoaded', initPolling);
document.addEventListener('turbo:load', initPolling);
