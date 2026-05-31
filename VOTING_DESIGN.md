# Voting System Design — Minecraft Bedrock Add-on Manager

This document describes the voting system implementation. It reflects the current codebase as of the latest implementation.

---

## Overview

Players visit the homepage and vote for which stopped server they want to start next. The server with the most active votes (strictly more than any other stopped server) triggers a 15-second countdown. After the countdown, if all conditions are still met, the server starts automatically.

If resources are insufficient, the system auto-stops the highest-profile empty running server first, then re-evaluates.

---

## Vote model

- One vote per user, stored in a Redis hash: `votes → { gamertag: serverName }`
- Voting for a server you already voted for **retracts** your vote (toggle)
- Voting for a different server **moves** your vote
- Votes persist in Redis indefinitely — they are not removed when a server starts or stops
- **Active votes** = votes from users with an active heartbeat key in Redis
- Inactive votes are remembered: if a user returns, their vote becomes active again automatically

### Heartbeat

- Sent by the browser via WebSocket every 30 seconds while the page is open
- Also sent immediately on WebSocket connect, and on every page load via `HomeController`
- Redis key: `heartbeat:{gamertag}`, TTL = `heartbeat_ttl` (default 120s, configurable in `meta.yaml`)
- When heartbeat expires, the vote stops counting but is not removed

---

## Trigger conditions for auto-start

All of the following must be true for `checkAndTrigger()` to return a candidate:

1. At least one stopped server has ≥ 1 active vote
2. That server has **strictly more** active votes than any other stopped server (ties block)
3. No running server has players
4. No vote cooldown active on the candidate
5. `ResourceBudgetChecker::canStart()` returns true for the candidate's memory profile

If condition 5 fails but all others pass, `getServersToAutoStop()` is evaluated.

---

## Countdown flow

```
Vote cast / server stops / player leaves
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
                │           ├── Players joined during countdown? → abort
                │           └── stopContainer() → evaluateCountdown() (re-triggers start)
                └── NO → show "lack of resources" blocking message
```

---

## Auto-stop logic

`getServersToAutoStop()` finds the minimum set of running empty servers to stop:

1. Find the vote leader (same rules as `checkAndTrigger`)
2. If already startable — return empty (no stop needed)
3. Collect running servers with 0 players, sorted **highest profile first** (most resources freed)
4. Simulate stopping them one by one using `canStartWithProfiles()`
5. Return the minimal set needed, or empty if impossible (busy servers block)

Only servers with 0 players are candidates. If all running servers have players, the blocking message shows and nothing is stopped.

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

**Example:** slot set `{medium: 2}` with a `low` server running:
- `low` running → takes a `medium` slot (lowest available ≥ low)
- `low` candidate → takes the second `medium` slot ✅

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

---

## UI states

### Stopped server card

| State | Vote section right side | Below card body |
|---|---|---|
| No votes | "Vote to start" button | — |
| Has votes, can start | "Vote to start" / "✓ Voted" | — |
| Has votes, players blocking | "Vote to start" / "✓ Voted" | 👥 info callout |
| Has votes, resources blocking | "Vote to start" / "✓ Voted" | ⚠️ warning callout |
| In start countdown | "Vote to start" / "✓ Voted" | 🚀 green countdown bar |

### Running server card

| State | Vote section right side | Top of card body |
|---|---|---|
| Normal | "Vote to start" / "✓ Voted" | — |
| In stop countdown | "Vote to start" / "✓ Voted" | 🔴 red countdown bar |

Vote button always visible regardless of running state. Retracting is always possible.

### Card ordering

Cards sorted by active vote count descending. Ties **preserve existing DOM order** — a card only moves when it strictly outranks what is above it. Medals update live: 👑 gold (1st), 🥈 silver (2nd), 🥉 bronze (3rd), #N for the rest.

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
| `VoteManager` | Vote casting, active vote queries, `checkAndTrigger`, `getServersToAutoStop` |
| `ResourceBudgetChecker` | Slot-based resource checking, simulation via `canStartWithProfiles` |
| `MinecraftMonitor` | Owns ReactPHP timers, `evaluateCountdown`, `evaluateAutoStop`, fires start/stop |
| `WebSocketServer` | Broadcasts vote state, countdown timestamps, blocking reason per server |
| `RedisClient` | All Redis access including vote, heartbeat, cooldown, countdown keys |
| `TestStateSeeder` | Seeds Redis for dev scenarios and unit tests |
