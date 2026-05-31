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

    const votes      = data.votes;
    const serverData = data.server;
    const isRunning  = serverData && serverData.running;

    if (!votes) {
        console.warn(`[WS] No votes data for server: ${serverName}`);
        return;
    }

    // Update running/stopped badge
    if (serverData) {
        const runningBadge = card.querySelector('.card-header .badge.bg-success, .card-header .badge.bg-danger');
        if (runningBadge) {
            if (isRunning) {
                runningBadge.className   = 'badge bg-success';
                runningBadge.textContent = '● Running';
            } else {
                runningBadge.className   = 'badge bg-danger';
                runningBadge.textContent = '● Stopped';
            }
        }

        const uptimeBadge  = card.querySelector('.stat-uptime');
        const playersBadge = card.querySelector('.stat-players');
        if (uptimeBadge)  uptimeBadge.style.display  = isRunning ? '' : 'none';
        if (playersBadge) playersBadge.style.display = isRunning ? '' : 'none';

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
        const n = votes.count;
        countText.textContent = `${n} vote${n !== 1 ? 's' : ''}`;
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

    if (blocked && !isRunning && votes.count > 0) {
        const messages = {
            players:   '👥 Another server has players online. This server will start automatically once they leave.',
            resources: '⚠️ Lack of resources — waiting for servers to stop.',
        };
        const alertClass = blocked === 'resources' ? 'alert-warning' : 'alert-info';
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
                    <span>🔴 <strong>Stopping in <span class="stop-countdown-seconds">–</span>s</strong></span>
                    <small class="text-muted">Freeing resources for voted server</small>
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

    if (countdownUntil && !isRunning) {
        if (!countdownBlock) {
            const block = document.createElement('div');
            block.className = 'alert alert-success py-2 mb-3 countdown-block';
            block.dataset.countdownUntil = countdownUntil;
            block.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span>🚀 <strong>Starting in <span class="countdown-seconds">–</span>s</strong></span>
                    <small class="text-muted">Retract your vote to cancel</small>
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
            const label    = userHasVoted ? '✓ Voted — click to retract' : 'Vote to start';
            const btnClass = userHasVoted ? 'btn-success' : 'btn-outline-primary';
            voteAction.innerHTML = `<form method="post" action="/server/${serverName}/vote" class="vote-form">
                <input type="hidden" name="_token" value="${csrfToken}">
                <button type="submit" class="btn btn-sm ${btnClass}">${label}</button>
            </form>`;
        } else {
            voteAction.innerHTML = `<span class="text-muted small">Log in to vote</span>`;
        }
    }

    // Update vote count on card for reordering
    card.dataset.voteCount = votes.count;
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

function initWebSocket() {
    const wsUrl = window.wsUrl || 'ws://host.docker.internal:8082';
    console.log(`[WS] Connecting to: ${wsUrl}`);

    const ws = new WebSocket(wsUrl);

    ws.addEventListener('open', () => {
        console.log('[WS] Connection opened');
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
            reorderCards();
        }

        if (msg.type === 'server_update') {
            console.log(`[WS] Processing server_update for: ${msg.server}`);
            applyServerUpdate(msg.server, msg.data);
            reorderCards();
        }
    });

    ws.addEventListener('error', e => {
        console.error('[WS] Connection error:', e);
    });

    ws.addEventListener('close', e => {
        console.warn(`[WS] Connection closed (code=${e.code}), reconnecting in 3s...`);
        setTimeout(initWebSocket, 3000);
    });

    if (window.myGamertag) {
        setInterval(() => {
            if (ws.readyState === WebSocket.OPEN) {
                console.log(`[WS] Sending heartbeat for: ${window.myGamertag}`);
                ws.send(JSON.stringify({ type: 'heartbeat', gamertag: window.myGamertag }));
            }
        }, 30000);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    console.log('[APP] DOMContentLoaded — myGamertag:', window.myGamertag, '— wsUrl:', window.wsUrl);
    initWebSocket();
});
