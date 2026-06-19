# Voting System Design — Minecraft Bedrock Add-on Manager

This document describes the voting system implementation. It reflects the current codebase.

---

## Overview

Players visit the homepage and vote for which stopped server they want to start next. The server with the most active votes (strictly more than any other stopped server) triggers a 15-second countdown. After the countdown, if all conditions are still met, the server starts automatically.

If resources are insufficient, the system auto-stops the highest-profile empty running server first, then re-evaluates. Players on a running server only block a start if their server occupies a slot that is specifically needed — other empty servers can still be stopped freely.

---

## Vote model

- One vote per user, stored in a Redis hash: `votes → { gamertag: serverName }`
- Voting for a server you already voted for **retracts** your vote (toggle)
- Voting for a different server **moves** your vote
- Votes persist in Redis indefinitely — they are not removed when a server starts or stops
- Vote button always visible regardless of server state — retract is always available
- **Active votes** = votes from users with an active heartbeat key in Redis
- Inactive votes are remembered: if a user returns, their vote becomes active again automatically

### Heartbeat

- Sent by the browser via WebSocket every 30 seconds while the page is open
- Also sent immediately on WebSocket connect, and on every page load via `HomeController`
- Redis key: `heartbeat:{gamertag}`, TTL = `heartbeat_ttl` (default 120s, configurable in `meta.yaml`)
- When heartbeat expires, the vote stops counting but is not removed

---

## Trigger conditions for auto-start

`checkAndTrigger()` returns a candidate when:

1. At least one stopped server has ≥ 1 active vote
2. That server has **strictly more** active votes than any other stopped server (ties block)
3. No vote cooldown active on the candidate
4. `ResourceBudgetChecker::canStart()` passes → **start immediately, regardless of players on other servers**
5. If resources blocked → only block if the players condition prevents freeing the needed slot (see below)

**Key rule:** players on a running server only block the start if that server occupies a slot needed by the candidate AND it cannot be stopped (because it has players). If an empty server can be stopped to free the needed slot, players on other servers are irrelevant.

---

## Countdown flow

```
Vote cast / server stops / player leaves / player joins
    ↓
evaluateCountdown()
    ↓
checkAndTrigger() → candidate found?
    ├── YES → startCountdown(candidate) — 15s ReactPHP timer
    │           ↓ (after 15s)
    │         fireCountdown()
    │           ↓
    │         confirmStart() — re-validates all conditions
    │           ├── PASS → restartContainer() → onServerStarted()
    │           └── FAIL → clearCountdown() → evaluateCountdown()
    │
    └── NO → evaluateAutoStop()
                ↓
              getServersToAutoStop() → servers found?
                ├── YES → startStopCountdown(server) — 15s timer per server (simultaneous)
                │           ↓ (after 15s)
                │         fireStopCountdown()
                │           ├── Players joined during countdown? → abort stop
                │           └── stopContainer() → wait 2s → evaluateCountdown()
                └── NO → show blocking message (players or resources)
```

---

## Auto-stop logic

`getServersToAutoStop()` finds the minimum set of running empty servers to stop:

1. Find the vote leader (same rules as `checkAndTrigger`)
2. If already startable → return empty (no stop needed)
3. Collect running servers with **0 players**, sorted **highest profile first** (most resources freed per stop)
4. Simulate stopping them one by one using `canStartWithProfiles()`
5. Return the minimal set needed, or empty if impossible

If no empty servers exist that free enough slots, returns empty — the blocking message is shown.

Multiple servers may be returned and their stop countdowns run **simultaneously**.

---

## Resource budget system

Configured in `~/mc-server-manager-data/meta.yaml`:

```yaml
resource_limits:
    - high: 1
      low: 1
    - medium: 2
```

Each entry is a **slot set** — a pool of named slots. A server occupies the lowest available slot at or above its profile level (`low < medium < high`).

**Assignment algorithm (per slot set):**
1. Build flat sorted slot list (low → high)
2. Assign running servers lowest-fit-first
3. Try to assign the candidate with remaining slots
4. Return true if all assignments succeed

A `low` server can occupy a `medium` or `high` slot if no `low` slot is available. A `high` server can only occupy a `high` slot.

**Example with `{high:1, low:1}` and server1 (high) running, server2 (low) candidate:**
- server1 takes the `high` slot
- server2 takes the `low` slot ✅ → can start alongside server1

**`MEMORY_PROFILE` env var** — set on each Minecraft container at creation time:
```bash
docker run -e MEMORY_PROFILE=low ...
```
Defaults to `medium` if absent.

---

## Redis keys used by voting

| Key | TTL | Content |
|---|---|---|
| `votes` | — | hash `{ gamertag → serverName }` |
| `heartbeat:{gamertag}` | 120s | Unix timestamp of last heartbeat |
| `gamertag_user:{gamertag}` | 120s | username (for avatar resolution) |
| `vote_cooldown:{name}` | 60s | "1" — post-start cooldown |
| `start_countdown:{name}` | 15s | Unix timestamp countdown began |
| `stop_countdown:{name}` | 15s | Unix timestamp stop countdown began |
| `players:{name}` | until 2am | player count — event-driven, expires nightly |

---

## UI states

### Stopped server card

| State | Vote action (right) | Alert above vote section |
|---|---|---|
| No votes | "Vote to start" | — |
| Has votes, no block | "Vote to start" / "✓ Voted" | — |
| Has votes, players blocking needed slot | "Vote to start" / "✓ Voted" | 👥 blue info |
| Players left, stop countdown active | "Vote to start" / "✓ Voted" | 👥 blue info ("waiting for server to stop") |
| Has votes, resources blocked, no stop active | "Vote to start" / "✓ Voted" | ⚠️ yellow warning |
| Resources blocked, stop countdown active | "Vote to start" / "✓ Voted" | ⚠️ yellow warning ("stopping server...") |
| In start countdown | "Vote to start" / "✓ Voted" | 🚀 green countdown bar |

### Running server card

| State | Vote action (right) | Top of card body |
|---|---|---|
| Normal | "Vote to start" / "✓ Voted" | — |
| In stop countdown | "Vote to start" / "✓ Voted" | 🔴 red countdown bar |

Vote button always visible regardless of server state. Retracting is always possible.

### Blocking message values

| `blocked` value | Message shown |
|---|---|
| `players` | "👥 Another server has players online. This server will start automatically once they leave." |
| `players_leaving` | "👥 Players have left — waiting for that server to stop before starting this one." |
| `resources` | "⚠️ Lack of resources — waiting for a server to stop." |
| `resources_stopping` | "⚠️ Stopping another server to free up resources..." |

### Card ordering

Cards sorted by active vote count descending. Ties **preserve existing DOM order** — a card only moves when it strictly outranks what is above it. `scheduleReorder()` debounces 50ms so burst updates all land before reordering. Medals: 👑 gold (1st), 🥈 silver (2nd), 🥉 bronze (3rd), #N for the rest.

---

## Configuration reference

### `~/mc-server-manager-data/meta.yaml` (global)

```yaml
resource_limits:
    - high: 1
      low: 1
    - medium: 2

# Heartbeat TTL in seconds (default: 120)
heartbeat_ttl: 120
```

### `~/minecraft-data/mc-server-manager/meta.yaml` (per server)

```yaml
display_name: "My Creative World"
description: "Our main survival world for the gang."

# Override heartbeat TTL for this server only (optional)
# heartbeat_ttl: 300
```

---

## Dev / test scenarios

Available at `/test/seed/{scenario}` (ROLE_ADMIN only):

| Scenario | Description |
|---|---|
| `two-servers-voting` | Two stopped servers, 3 fake players voting |
| `one-running-one-voted` | One running with players, one stopped with votes |
| `multi-server-ranking` | Three stopped servers with votes distributed across them |
| `anonymous` | Votes present but no heartbeats (all inactive) |
| `reset` | Clears all votes and heartbeats (preserves server/player/stats data) |

---

## Key classes

| Class | Responsibility |
|---|---|
| `VoteManager` | Vote casting, active vote queries, `checkAndTrigger`, `getServersToAutoStop`, `confirmStart`, `onServerStarted`, `onServerAutoStopped` |
| `ResourceBudgetChecker` | Slot-based resource checking, `canStart`, `canStartWithProfiles`, `fitsInSlotSet` |
| `MinecraftMonitor` | Owns ReactPHP timers, `evaluateCountdown`, `evaluateAutoStop`, fires start/stop countdowns |
| `WebSocketServer` | Broadcasts state including `blocked` reason; injects `ResourceBudgetChecker` |
| `RedisClient` | All Redis access; player count uses 2am-expiry TTL; start/stop countdown keys |
| `AddonInstaller` | Handles 4 `.mcaddon` structural cases including `behavior_packs/` container folders |
| `TestStateSeeder` | Seeds Redis for dev scenarios and unit tests |
