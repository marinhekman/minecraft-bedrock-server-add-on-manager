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

// ── Grace period countdown ────────────────────────────────────────────────────

function updateGraceCountdowns() {
    document.querySelectorAll('.grace-countdown-block').forEach(block => {
        const graceUntil = parseInt(block.dataset.graceUntil);
        if (!graceUntil) return;

        const now       = Math.floor(Date.now() / 1000);
        const remaining = graceUntil - now;

        const secsEl    = block.querySelector('.grace-seconds');
        const progressEl = block.querySelector('.grace-progress');

        if (remaining <= 0) {
            block.style.display = 'none';
            return;
        }

        if (secsEl) secsEl.textContent = remaining;

        // We don't know total grace duration from JS, so infer from data attr on card
        const card      = block.closest('.card');
        const totalGrace = card ? parseInt(card.dataset.totalGrace || 60) : 60;
        const pct       = Math.max(0, Math.min(100, (remaining / totalGrace) * 100));
        if (progressEl) progressEl.style.width = pct + '%';
    });
}

function initGraceCountdowns() {
    if (document.querySelector('.grace-countdown-block')) {
        setInterval(updateGraceCountdowns, 1000);
        updateGraceCountdowns();
    }
}

document.addEventListener('DOMContentLoaded', initGraceCountdowns);
document.addEventListener('turbo:load', initGraceCountdowns);

// ── WebSocket — vote state + server updates ───────────────────────────────────

function applyServerUpdate(serverName, data) {
    const card = document.querySelector(`.card[data-server="${serverName}"]`);
    if (!card) return;

    const votes = data.votes;
    if (!votes) return;

    // Update vote count text
    const countText = card.querySelector('.vote-count-text');
    if (countText) {
        countText.textContent = `${votes.count} / ${votes.threshold} votes`;
    }

    // Update voter avatars
    const avatarContainer = card.querySelector('.vote-avatars');
    if (avatarContainer) {
        avatarContainer.innerHTML = '';
        const displayVoters = votes.voters.slice(0, 5);
        displayVoters.forEach((voter, i) => {
            const img = document.createElement('img');
            img.src   = voter.avatarPath;
            img.alt   = voter.gamertag;
            img.title = voter.gamertag;
            img.className = 'rounded-circle border border-white';
            img.style.cssText = `width:32px;height:32px;object-fit:cover;margin-left:${i === 0 ? '0' : '-8px'}`;
            avatarContainer.appendChild(img);
        });
        if (votes.voters.length > 5) {
            const more = document.createElement('span');
            more.className   = 'badge bg-secondary ms-1 align-self-center';
            more.textContent = `+${votes.voters.length - 5} more`;
            avatarContainer.appendChild(more);
        }
    }

    // Update vote button
    const voteSection = card.querySelector('.vote-section');
    if (voteSection) {
        const btnContainer = voteSection.querySelector('.d-flex.align-items-center.justify-content-between > :last-child');
        const serverData   = data.server;
        const isRunning    = serverData && serverData.running;
        const myGamertag   = window.myGamertag;
        const userHasVoted = myGamertag && votes.voters.some(v => v.gamertag === myGamertag);

        if (isRunning) {
            if (btnContainer) btnContainer.outerHTML = `<span class="text-success small">Server is running ✅</span>`;
        } else if (votes.cooldown) {
            if (btnContainer) btnContainer.outerHTML = `<button class="btn btn-sm btn-secondary" disabled>⏳ Starting soon...</button>`;
        } else if (myGamertag) {
            const label = userHasVoted
                ? '✓ Voted — click to retract'
                : 'Vote to start';
            const btnClass = userHasVoted ? 'btn-success' : 'btn-outline-primary';
            if (btnContainer) btnContainer.outerHTML = `
                <form method="post" action="/server/${serverName}/vote" class="vote-form">
                    <button type="submit" class="btn btn-sm ${btnClass}">${label}</button>
                </form>`;
        }
    }

    // Update grace countdown block
    card.dataset.graceUntil = data.graceUntil ?? '';
    const graceBlock = card.querySelector('.grace-countdown-block');
    if (data.graceUntil) {
        if (!graceBlock) {
            // Grace started — inject the block before vote section
            const voteSection = card.querySelector('.vote-section');
            if (voteSection) {
                const block = document.createElement('div');
                block.className = 'alert alert-info py-2 grace-countdown-block';
                block.dataset.graceUntil = data.graceUntil;
                block.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span>⏱ <strong>Server stopping in <span class="grace-seconds">–</span>s</strong></span>
                        <small class="text-muted">Waiting for players to reconnect...</small>
                    </div>
                    <div class="progress" style="height:6px">
                        <div class="progress-bar bg-info grace-progress" role="progressbar" style="width:100%"></div>
                    </div>`;
                voteSection.before(block);
                updateGraceCountdowns();
            }
        } else {
            graceBlock.dataset.graceUntil = data.graceUntil;
            graceBlock.style.display = '';
        }
    } else if (graceBlock) {
        graceBlock.remove();
    }

    // Update blocking callout
    updateBlockingCallout(card, votes.blockingReason, votes.blockingDetail);
}

function updateBlockingCallout(card, reason, detail) {
    // Remove existing callout
    const existing = card.querySelector('.blocking-callout');
    if (existing) existing.remove();

    if (!reason) return;

    const alertClass = reason === 'resources' ? 'alert-warning' : 'alert-info';
    const icons      = { players: '⏳', grace: '⏳', resources: '⚠️' };
    const titles     = {
        players:   'Waiting for players to leave',
        grace:     'Waiting for server to stop',
        resources: 'Insufficient resources',
    };

    const div = document.createElement('div');
    div.className = `alert ${alertClass} py-2 mb-3 blocking-callout`;
    div.innerHTML = `${icons[reason]} <strong>${titles[reason]}</strong><br><small>${detail}</small>`;

    // Insert before vote section
    const voteSection = card.querySelector('.vote-section');
    if (voteSection) voteSection.before(div);
}

function reorderCards() {
    const container = document.getElementById('server-cards');
    if (!container) return;

    // Build vote count map from WS data or fall back to data attr
    const cards = Array.from(container.querySelectorAll('.card[data-server]'));

    cards.sort((a, b) => {
        const aVotes = parseInt(a.dataset.voteCount || '0');
        const bVotes = parseInt(b.dataset.voteCount || '0');
        if (bVotes !== aVotes) return bVotes - aVotes;
        return (a.dataset.server || '').localeCompare(b.dataset.server || '');
    });

    // Re-append in sorted order and update medals
    const medals = ['👑', '🥈', '🥉'];
    const medalClasses = ['text-warning fw-bold', 'text-secondary fw-bold', 'text-danger fw-bold'];

    cards.forEach((card, i) => {
        container.appendChild(card);
        const medalEl = card.querySelector('.card-header span[title^="Position"]');
        if (medalEl) {
            medalEl.textContent = i < 3 ? medals[i] : `#${i + 1}`;
            medalEl.className   = i < 3 ? medalClasses[i] : 'text-muted';
            medalEl.title       = `Position #${i + 1}`;
        }
    });
}

function initWebSocket() {
    const host = window.location.hostname;
    const ws   = new WebSocket(`ws://${host}:8082`);

    ws.addEventListener('message', e => {
        const msg = JSON.parse(e.data);

        if (msg.type === 'init') {
            // Apply initial state to all cards
            Object.entries(msg.servers || {}).forEach(([name, data]) => {
                applyServerUpdate(name, data);
                const card = document.querySelector(`.card[data-server="${name}"]`);
                if (card && data.votes) {
                    card.dataset.voteCount = data.votes.count;
                }
            });
            reorderCards();
        }

        if (msg.type === 'server_update') {
            const card = document.querySelector(`.card[data-server="${msg.server}"]`);
            if (card && msg.data.votes) {
                card.dataset.voteCount = msg.data.votes.count;
            }
            applyServerUpdate(msg.server, msg.data);
            reorderCards();
        }
    });

    ws.addEventListener('close', () => {
        // Reconnect after 3 seconds
        setTimeout(initWebSocket, 3000);
    });

    // Send heartbeat every 30s if logged in
    if (window.myGamertag) {
        setInterval(() => {
            if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'heartbeat', gamertag: window.myGamertag }));
            }
        }, 30000);
    }
}

document.addEventListener('DOMContentLoaded', initWebSocket);
document.addEventListener('turbo:load', initWebSocket);
