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
        .then(r => r.json().then(data => ({ ok: r.ok, data })))
        .then(({ ok, data }) => {
            // Memory stats
            const memText = document.getElementById('hostMemText');
            const memBar  = document.getElementById('hostMemBar');
            if (memText && memBar && data.memoryAvailable) {
                memText.textContent = `${data.usedMb} MB / ${data.totalMb} MB (${data.usedPercent}%)`;
                memBar.style.width  = data.usedPercent + '%';
                memBar.className    = 'progress-bar ' + (
                    data.usedPercent > 90 ? 'bg-danger' :
                    data.usedPercent > 70 ? 'bg-warning' : 'bg-info'
                );
            } else if (memText && memBar) {
                memText.textContent = data.memoryError || 'Memory unavailable';
                memBar.style.width  = '0%';
                memBar.className    = 'progress-bar bg-secondary';
            }

            // Disk stats
            const diskText = document.getElementById('hostDiskText');
            const diskBar  = document.getElementById('hostDiskBar');
            const diskWarning = document.getElementById('hostDiskWarning');
            const newServerBtn = document.getElementById('newServerBtn');
            const createServerBtn = document.getElementById('createServerBtn');

            if (diskText && diskBar) {
                diskText.textContent = `${data.diskUsedGb} GB / ${data.diskTotalGb} GB (${data.diskAvailGb} GB free)`;
                diskBar.style.width  = data.diskUsedPercent + '%';
                diskBar.className    = 'progress-bar ' + (
                    data.diskUsedPercent > 90 ? 'bg-danger' :
                    data.diskUsedPercent > 70 ? 'bg-warning' : 'bg-success'
                );

                // Show/hide warning based on absolute free space threshold
                const isAboveThreshold = !data.canCreateServer;
                const minFreeEl = document.getElementById('hostDiskMinFree');
                if (minFreeEl) minFreeEl.textContent = data.minFreeDiskGb;

                if (diskWarning) {
                    diskWarning.classList.toggle('d-none', !isAboveThreshold);
                }
                if (newServerBtn) {
                    newServerBtn.disabled = isAboveThreshold;
                    const disabledTemplate = newServerBtn.dataset.titleDisabledTemplate || 'Less than __GB__ GB free — cannot create new server';
                    const enabledTitle     = newServerBtn.dataset.titleEnabled || 'Create new server';
                    newServerBtn.title = isAboveThreshold
                        ? disabledTemplate.replace('__GB__', data.minFreeDiskGb)
                        : enabledTitle;
                }
                if (createServerBtn) {
                    createServerBtn.disabled = isAboveThreshold;
                }
            }

            if (!ok) {
                console.warn('[host-stats] Endpoint responded with non-OK status', data);
            }
        }, err => {
            const memText = document.getElementById('hostMemText');
            const diskText = document.getElementById('hostDiskText');
            if (memText) memText.textContent = 'Unavailable';
            if (diskText) diskText.textContent = 'Unavailable';
            console.error('[host-stats] Failed to fetch host stats', err);
        });
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

// ── Uptime ticker ─────────────────────────────────────────────────────────────

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
        document.querySelectorAll('.card[data-server]').forEach(card => {
            const startedAt = card.dataset.lastStartedAt;
            const badge     = card.querySelector('.stat-uptime');
            if (badge && startedAt) {
                badge.textContent = `UP ${formatUptime(parseInt(startedAt))}`;
            }
        });
    }, 1000);
}

document.addEventListener('DOMContentLoaded', startUptimeTicker);
document.addEventListener('turbo:load', startUptimeTicker);

// ── Countdown ticker ──────────────────────────────────────────────────────────

function startCountdownTicker() {
    setInterval(() => {
        const ttl = window.countdownTtl || 15;

        // Start countdown bars
        document.querySelectorAll('.countdown-block').forEach(block => {
            const until     = parseInt(block.dataset.countdownUntil);
            const remaining = Math.max(0, until - Math.floor(Date.now() / 1000));

            const secsEl     = block.querySelector('.countdown-seconds');
            const progressEl = block.querySelector('.countdown-progress');

            if (secsEl) secsEl.textContent = remaining;
            if (progressEl) progressEl.style.width = ((remaining / ttl) * 100) + '%';

            if (remaining === 0) block.remove();
        });

        // Stop countdown bars
        document.querySelectorAll('.stop-countdown-block').forEach(block => {
            const until     = parseInt(block.dataset.stopCountdownUntil);
            const remaining = Math.max(0, until - Math.floor(Date.now() / 1000));

            const secsEl     = block.querySelector('.stop-countdown-seconds');
            const progressEl = block.querySelector('.stop-countdown-progress');

            if (secsEl) secsEl.textContent = remaining;
            if (progressEl) progressEl.style.width = ((remaining / ttl) * 100) + '%';

            if (remaining === 0) block.remove();
        });
    }, 1000);
}

document.addEventListener('DOMContentLoaded', startCountdownTicker);
document.addEventListener('turbo:load', startCountdownTicker);

// ── WebSocket — all live updates ──────────────────────────────────────────────

function applyServerUpdate(serverName, data) {
    console.log(`[WS] applyServerUpdate: ${serverName}`, data);

    const card = document.querySelector(`.card[data-server="${serverName}"]`);
    if (!card) {
        console.warn(`[WS] No card found for server: ${serverName}`);
        return;
    }

    const votes      = data.votes ?? { count: 0, voters: [] };
    const serverData = data.server;
    const isRunning  = serverData && serverData.running;

    // Update running/stopped badge
    const isStarting = data.starting ?? false;
    const statusRaw = String(serverData?.containerStatus ?? '').toLowerCase();
    const hasContainerStartupState = ['created', 'restarting', 'starting'].includes(statusRaw);
    const isAwaitingStartup = data.awaitingStartup ?? (!isRunning && (isStarting || hasContainerStartupState));
    if (serverData) {
        const runningBadge = card.querySelector('.server-status-badge')
            || card.querySelector('.card-header .badge.bg-success, .card-header .badge.bg-danger, .card-header .badge.bg-warning');
        if (runningBadge) {
            if (isRunning) {
                runningBadge.className   = 'badge bg-success';
                runningBadge.textContent = window.i18n?.running  ?? '● Running';
            } else if (isAwaitingStartup) {
                runningBadge.className   = 'badge bg-warning text-dark';
                runningBadge.textContent = window.i18n?.awaiting_startup ?? '⏳ Awaiting startup';
            } else {
                runningBadge.className   = 'badge bg-danger';
                runningBadge.textContent = window.i18n?.stopped  ?? '● Stopped';
            }

            if (!runningBadge.classList.contains('server-status-badge')) {
                runningBadge.classList.add('server-status-badge');
            }
        }

        const uptimeBadge   = card.querySelector('.stat-uptime');
        const cpuBadge      = card.querySelector('.stat-cpu');
        const memBadge      = card.querySelector('.stat-mem');
        const playersBadge  = card.querySelector('.stat-players');
        const stopForm      = card.querySelector('.server-stop-form');
        const startBtn      = card.querySelector('.server-start-btn');

        if (uptimeBadge)  uptimeBadge.style.display  = isRunning ? '' : 'none';
        if (cpuBadge)     cpuBadge.style.display     = isRunning ? '' : 'none';
        if (memBadge)     memBadge.style.display     = isRunning ? '' : 'none';
        if (playersBadge) playersBadge.style.display = isRunning ? '' : 'none';
        if (stopForm)     stopForm.style.display     = isRunning ? '' : 'none';

        if (startBtn) {
            const startLabel   = startBtn.dataset.labelStart || '▶ Start server';
            const restartLabel = startBtn.dataset.labelRestart || '🔄 Restart server';

            if (isRunning) {
                startBtn.classList.remove('btn-success');
                startBtn.classList.add('btn-warning');
                startBtn.textContent = restartLabel;
            } else {
                startBtn.classList.remove('btn-warning');
                startBtn.classList.add('btn-success');
                startBtn.textContent = startLabel;
            }
        }

        if (serverData.startedAt) {
            card.dataset.lastStartedAt = serverData.startedAt;
        }
    }

    // Update CPU / memory stats
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

    // Update player count
    const playersBadge = card.querySelector('.stat-players');
    if (playersBadge) {
        playersBadge.textContent = `👥 ${data.playerCount ?? 0}`;
    }

    // Update vote count text
    const countText = card.querySelector('.vote-count-text');
    if (countText) {
        const n        = votes.count;
        const voteWord = n !== 1
            ? (window.i18n?.votes ?? 'votes')
            : (window.i18n?.vote  ?? 'vote');
        countText.textContent = `${n} ${voteWord}`;
    }

    // Update voter avatars
    const avatarContainer = card.querySelector('.vote-avatars');
    if (avatarContainer) {
        avatarContainer.innerHTML = '';
        votes.voters.slice(0, 5).forEach((voter, i) => {
            const img     = document.createElement('img');
            img.src       = voter.avatarPath;
            img.alt       = voter.gamertag;
            img.title     = voter.gamertag;
            img.className = 'rounded-circle border border-white';
            img.style.cssText = `width:32px;height:32px;object-fit:cover;margin-left:${i === 0 ? '0' : '-8px'}`;
            avatarContainer.appendChild(img);
        });
        if (votes.voters.length > 5) {
            const more       = document.createElement('span');
            more.className   = 'badge bg-secondary ms-1 align-self-center';
            more.textContent = `+${votes.voters.length - 5}`;
            avatarContainer.appendChild(more);
        }
    }

    // Update blocking message
    const blocked      = data.blocked ?? null;
    let blockingBlock  = card.querySelector('.blocking-block');
    const voteSection  = card.querySelector('.vote-section');

    if (blocked && !isRunning && !isAwaitingStartup && votes.count > 0) {
        const i18n     = window.i18n || {};
        const messages = {
            players:            i18n.players            ?? '👥 Another server has players online. This server will start automatically once they leave.',
            players_leaving:    i18n.players_leaving    ?? '👥 Players have left — waiting for that server to stop before starting this one.',
            resources:          i18n.resources          ?? '⚠️ Lack of resources — waiting for a server to stop.',
            resources_stopping: i18n.resources_stopping ?? '⚠️ Stopping another server to free up resources...',
        };
        const alertClass = (blocked === 'resources' || blocked === 'resources_stopping') ? 'alert-warning' : 'alert-info';
        const html = `<div class="alert ${alertClass} py-2 mb-3 blocking-block">${messages[blocked] ?? 'Cannot start right now.'}</div>`;

        if (blockingBlock) {
            blockingBlock.outerHTML = html;
        } else if (voteSection) {
            voteSection.insertAdjacentHTML('beforebegin', html);
        }
    } else if (blockingBlock) {
        blockingBlock.remove();
    }

    // Update stop countdown block (shown on running server being auto-stopped)
    const stopCountdownUntil = data.stopCountdownUntil ?? null;
    let stopCountdownBlock   = card.querySelector('.stop-countdown-block');

    if (stopCountdownUntil && isRunning) {
        if (!stopCountdownBlock) {
            const block = document.createElement('div');
            block.className = 'alert alert-danger py-2 mb-0 stop-countdown-block';
            block.dataset.stopCountdownUntil = stopCountdownUntil;
            block.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span><strong>${(window.i18n && window.i18n.stopping_in) || '🔴 Stopping in'} <span class="stop-countdown-seconds">–</span>s</strong></span>
                    <small class="text-muted">${(window.i18n && window.i18n.freeing_resources) || 'Freeing resources for voted server'}</small>
                </div>
                <div class="progress" style="height:6px">
                    <div class="progress-bar bg-danger stop-countdown-progress" role="progressbar" style="width:100%"></div>
                </div>`;
            // Insert at top of card body
            const cardBody = card.querySelector('.card-body');
            if (cardBody) cardBody.prepend(block);
        } else {
            stopCountdownBlock.dataset.stopCountdownUntil = stopCountdownUntil;
        }
    } else if (stopCountdownBlock) {
        stopCountdownBlock.remove();
    }

    // Update countdown block
    const countdownUntil = data.countdownUntil ?? null;
    let countdownBlock   = card.querySelector('.countdown-block');

    if (countdownUntil && !isRunning && !isAwaitingStartup) {
        if (!countdownBlock) {
            const block = document.createElement('div');
            block.className = 'alert alert-success py-2 mb-3 countdown-block';
            block.dataset.countdownUntil = countdownUntil;
            block.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span><strong>${(window.i18n && window.i18n.starting_in) || '🚀 Starting in'} <span class="countdown-seconds">–</span>s</strong></span>
                    <small class="text-muted">${(window.i18n && window.i18n.retract_to_cancel) || 'Retract your vote to cancel'}</small>
                </div>
                <div class="progress" style="height:6px">
                    <div class="progress-bar bg-success countdown-progress" role="progressbar" style="width:100%"></div>
                </div>`;
            if (voteSection) voteSection.before(block);
        } else {
            countdownBlock.dataset.countdownUntil = countdownUntil;
        }
    } else if (countdownBlock) {
        countdownBlock.remove();
    }

    // Update pack loaded status badges (dashboard)
    if (data.loadedUuids) {
        card.querySelectorAll('tr[data-uuid]').forEach(row => {
            const badge = row.querySelector('.status-badge');
            if (!badge || badge.classList.contains('bg-secondary')) return;
            if (data.loadedUuids.includes(row.dataset.uuid)) {
                badge.className   = 'badge bg-info status-badge';
                badge.textContent = '✅ Loaded';
            } else {
                badge.className   = 'badge bg-success status-badge';
                badge.textContent = 'Enabled';
            }
        });
    }

    // Update vote action (right side)
    const voteAction = card.querySelector('.vote-action');
    if (voteAction) {
        const myGamertag   = window.myGamertag;
        const userHasVoted = myGamertag && votes.voters.some(v => v.gamertag === myGamertag);
        const csrfToken    = card.dataset.csrfToken ?? '';

        if (myGamertag) {
            const label    = userHasVoted
                ? (window.i18n?.voted_click_retract ?? '✓ Voted — click to retract')
                : (window.i18n?.vote_to_start       ?? 'Vote to start');
            const btnClass = userHasVoted ? 'btn-success' : 'btn-outline-primary';
            voteAction.innerHTML = `<form method="post" action="/server/${serverName}/vote" class="vote-form">
                <input type="hidden" name="_token" value="${csrfToken}">
                <button type="submit" class="btn btn-sm ${btnClass}">${label}</button>
            </form>`;
        } else {
            voteAction.innerHTML = `<span class="text-muted small">${window.i18n?.log_in_to_vote ?? 'Log in to vote'}</span>`;
        }
    }

    // Update vote count on card for reordering
    card.dataset.voteCount = votes.count;
}

let reorderPending = false;

function scheduleReorder() {
    if (reorderPending) return;
    reorderPending = true;
    setTimeout(() => {
        reorderPending = false;
        reorderCards();
    }, 50);
}

function reorderCards() {
    const container = document.getElementById('server-cards');
    if (!container) return;

    const cards = Array.from(container.querySelectorAll('.card[data-server]'));

    cards.sort((a, b) => {
        const aVotes = parseInt(a.dataset.voteCount || '0');
        const bVotes = parseInt(b.dataset.voteCount || '0');
        if (bVotes !== aVotes) return bVotes - aVotes;
        return (a.dataset.server || '').localeCompare(b.dataset.server || '');
    });

    const medals       = ['👑', '🥈', '🥉'];
    const medalClasses = ['text-warning fw-bold', 'text-secondary fw-bold', 'text-danger fw-bold'];
    const bgClasses    = ['card-gold', 'card-silver', 'card-bronze'];

    cards.forEach((card, i) => {
        container.appendChild(card);

        const medalEl = card.querySelector('.card-header span[title^="Position"]');
        if (medalEl) {
            medalEl.textContent = i < 3 ? medals[i] : `#${i + 1}`;
            medalEl.className   = i < 3 ? medalClasses[i] : 'text-muted';
            medalEl.title       = `Position #${i + 1}`;
        }

        card.classList.remove('card-gold', 'card-silver', 'card-bronze');
        if (i < 3) card.classList.add(bgClasses[i]);
    });
}

let activeWebSocket = null;
let heartbeatInterval = null;
let currentWsStatus = 'disconnected';

// ── WebSocket status indicator ────────────────────────────────────────────────

/**
 * Update all WebSocket status dots to reflect live connection state.
 * Values: 'connected' | 'connecting' | 'disconnected'
 */
function setWsStatusDot(status) {
    const dots = Array.from(document.querySelectorAll('[data-ws-status-dot], #ws-status-dot'));
    if (!dots.length) return;
    currentWsStatus = status;
    const map = {
        connected:    { bg: '#198754', title: 'Live: WebSocket connected' },
        connecting:   { bg: '#ffc107', title: 'Live: WebSocket connecting…' },
        disconnected: { bg: '#dc3545', title: 'Live: WebSocket disconnected — polling fallback active' },
    };
    const cfg = map[status] || map.disconnected;
    dots.forEach(dot => {
        dot.style.background = cfg.bg;
        dot.title = cfg.title;
    });
}

// ── Server-state polling fallback ─────────────────────────────────────────────

let statePollInterval = null;
const POLL_INTERVAL_MS = 8000;

async function pollServerStates() {
    try {
        const res = await fetch('/api/server-states');
        if (!res.ok) return;
        const data = await res.json();
        Object.entries(data.servers || {}).forEach(([name, serverData]) => {
            applyServerUpdate(name, serverData);
        });
        scheduleReorder();
    } catch (_) {
        // silent — network errors are handled by the WS reconnect logs
    }
}

function startStatePoll() {
    if (statePollInterval) return;
    // Poll immediately so UI shows updates without delay
    pollServerStates();
    statePollInterval = setInterval(pollServerStates, POLL_INTERVAL_MS);
}

function stopStatePoll() {
    if (statePollInterval) {
        clearInterval(statePollInterval);
        statePollInterval = null;
    }
}

function initWebSocket() {
    if (activeWebSocket && (activeWebSocket.readyState === WebSocket.OPEN || activeWebSocket.readyState === WebSocket.CONNECTING)) {
        return;
    }

    const wsUrl = window.wsUrl || 'ws://host.docker.internal:8082';
    console.log(`[WS] Connecting to: ${wsUrl}`);
    setWsStatusDot('connecting');
    startStatePoll(); // start polling immediately so UI is live even while connecting

    const ws = new WebSocket(wsUrl);
    activeWebSocket = ws;

    ws.addEventListener('open', () => {
        console.log('[WS] Connection opened');
        setWsStatusDot('connected');
        // Do an immediate poll so we have fresh state right away
        pollServerStates();
        if (window.myGamertag) {
            console.log(`[WS] Sending initial heartbeat for: ${window.myGamertag}`);
            ws.send(JSON.stringify({ type: 'heartbeat', gamertag: window.myGamertag }));
        }
    });

    ws.addEventListener('message', e => {
        const msg = JSON.parse(e.data);
        console.log(`[WS] Message received: type=${msg.type}`, msg);

        if (msg.type === 'init') {
            console.log('[WS] Processing init, servers:', Object.keys(msg.servers || {}));
            Object.entries(msg.servers || {}).forEach(([name, data]) => {
                applyServerUpdate(name, data);
            });
            scheduleReorder();
        }

        if (msg.type === 'server_update') {
            console.log(`[WS] Processing server_update for: ${msg.server}`);
            applyServerUpdate(msg.server, msg.data);
            scheduleReorder();
        }
    });

    ws.addEventListener('error', e => {
        console.error('[WS] Connection error:', e);
        setWsStatusDot('disconnected');
    });

    ws.addEventListener('close', e => {
        if (activeWebSocket === ws) {
            activeWebSocket = null;
        }
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
        setWsStatusDot('disconnected');
        console.warn(`[WS] Connection closed (code=${e.code}), reconnecting in 3s...`);
        setTimeout(initWebSocket, 3000);
    });

    if (window.myGamertag) {
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
        }
        heartbeatInterval = setInterval(() => {
            if (ws.readyState === WebSocket.OPEN) {
                console.log(`[WS] Sending heartbeat for: ${window.myGamertag}`);
                ws.send(JSON.stringify({ type: 'heartbeat', gamertag: window.myGamertag }));
            }
        }, 30000);
    }
}

function bootWebSocket() {
    console.log('[APP] DOMContentLoaded — myGamertag:', window.myGamertag, '— wsUrl:', window.wsUrl);
    if (!document.querySelector('.card[data-server]')) {
        return;
    }
    initWebSocket();
}

document.addEventListener('DOMContentLoaded', bootWebSocket);
document.addEventListener('turbo:load', bootWebSocket);

// Re-sync WS status dots on Turbo page updates to prevent greyed-out indicators
document.addEventListener('turbo:load', () => {
    setWsStatusDot(currentWsStatus);
});

