# Technical Implementation — Minecraft Bedrock Add-on Manager

> For general features, installation, folder structure and usage see [README.md](README.md).

This document covers the internal architecture and implementation details intended as AI assistant context.

---

## Stack

- **PHP 8.4** / **Symfony 7.4**
- **FrankenPHP** as the web server, runs via `frankenphp run --config /etc/frankenphp/Caddyfile`
- **Predis** for Redis access
- **ReactPHP** (`react/http`, `react/event-loop`, `react/socket`) for async Docker log streaming and WebSocket server
- **Ratchet** (custom fork from `BredaUniversityResearch/Ratchet`) for WebSocket protocol handling
- **Bootstrap 5** + **Stimulus** + **Turbo** on the frontend
- **Twig** templates

---

## Docker setup

Four containers on shared `mc-net` Docker network:

| Container | Image | Purpose |
|---|---|---|
| `mc-server-manager` | built locally | PHP/Symfony app + WebSocket server |
| `mc-redis` | `redis:7-alpine` | Sessions, live state, votes |
| `mc-docker-api` | `alpine/socat` | Docker socket TCP proxy |

The app does **not** mount the Docker socket directly — it talks to `mc-docker-api` over HTTP via `DockerClient` using plain curl. URL configured via `DOCKER_API_URL` env var (typically `http://mc-docker-api:2375`).

`composer dump-env prod` + `cache:clear` + `cache:warmup` run in `docker-entrypoint.sh` at container start so runtime env vars from `--env-file` always take effect.

---

## Source structure (`src/`)

```
src/
    Command/
        WebSocketServerCommand.php     ← Symfony console command: app:websocket-server
    Controller/
        AddonController.php            ← /server/{name}/addon/* (install, enable, disable, delete)
        CommandsController.php         ← /commands (JSON list from commands.txt)
        DashboardController.php        ← /admin (ROLE_ADMIN required)
        HomeController.php             ← / (public, passes serverData + vote state to template)
        HostStatsController.php        ← /host/stats (JSON RAM stats from /proc/meminfo)
        SecurityController.php         ← /login, /logout
        ServerController.php           ← /server/{name}/command, restart, stop, image
        ServerStatusController.php     ← /server/{name}/status (JSON, includes vote state)
        VoteController.php             ← /server/{name}/vote (POST, ROLE_USER)
        Test/
            TestStateController.php    ← /test/seed/{scenario} (ROLE_ADMIN, dev scenarios)
    Dev/
        TestStateSeeder.php            ← Seeds Redis for tests and dev scenarios
    Model/
        AddonManifest.php              ← parsed manifest.json data
        AddonPack.php                  ← pack + manifest + enabled + isSystem flags
        AddonType.php                  ← enum: Behaviour, Resource, Script
        GlobalMeta.php                 ← readonly value object: resourceLimits, heartbeatTtl
        ServerInstance.php             ← server state built from Redis data (incl. memoryProfile)
        ServerMeta.php                 ← display_name, description, imagePath, heartbeatTtl override
    Security/
        User.php                       ← UserInterface + PasswordAuthenticatedUserInterface
        UserProvider.php               ← loads from /mc-data/config/users.yaml
    Server/
        MinecraftMonitor.php           ← ReactPHP async log streamer, scanner, countdown manager
        WebSocketServer.php            ← Ratchet MessageComponentInterface
    Service/
        AddonInstaller.php             ← installs .mcaddon/.mcpack files
        AddonScanner.php               ← scans behavior_packs/ and resource_packs/ folders
        DependencyChecker.php          ← checks UUID dependencies between packs
        DockerClient.php               ← synchronous curl HTTP client for Docker API
        GlobalMetaReader.php           ← reads /mc-data/config/meta.yaml
        ManifestParser.php             ← parses manifest.json files
        RedisClient.php                ← typed Redis wrapper (see key schema below)
        ResourceBudgetChecker.php      ← slot-based memory profile resource checker
        ServerMetaReader.php           ← reads mc-server-manager/meta.yaml per server
        ServerRegistry.php             ← builds ServerInstance objects from Redis, falls back to filesystem
        VoteManager.php                ← vote casting, ranking, countdown trigger, auto-stop logic
        WorldPacksManager.php          ← reads/writes world_*_packs.json files
```

---

## Authentication

- `src/Security/User.php` — bcrypt passwords, avatar path resolution by username
- `src/Security/UserProvider.php` — reads `/mc-data/config/users.yaml` on every request (no cache)
- `config/packages/security.yaml` — form_login firewall, `/admin` requires `ROLE_ADMIN`, `/` is public
- Sessions in Redis (`handler_id: '%env(REDIS_URL)%'`), 30-day lifetime, auto-renewed per request

---

## Redis key schema

Managed exclusively via `RedisClient`:

| Key | Type | TTL | Content |
|---|---|---|---|
| `server:{name}` | string (JSON) | 60s | `{name, containerId, containerName, containerStatus, port, startedAt, running, memoryProfile}` |
| `players:{name}` | string (int) | 60s | player count |
| `loaded:{name}` | string (JSON) | 60s | array of loaded pack UUIDs |
| `stats:{name}` | string (JSON) | 60s | `{cpu, memUsageMb, memLimitMb, memPercent}` |
| `chat` | list | — | last 100 chat messages (JSON) |
| `votes` | hash | — | `{ gamertag => serverName }` |
| `heartbeat:{gamertag}` | string | 120s | Unix timestamp of last heartbeat |
| `gamertag_user:{gamertag}` | string | 120s | username (for avatar resolution) |
| `vote_cooldown:{name}` | string | 60s | "1" — prevents re-triggering after auto-start |
| `start_countdown:{name}` | string | 15s | Unix timestamp when countdown began |
| `stop_countdown:{name}` | string | 15s | Unix timestamp when stop countdown began |

---

## Background process — WebSocketServerCommand

Runs under **Supervisor** inside the container (`/var/log/supervisor/websocket.log`).

`MinecraftMonitor` and `WebSocketServer` are injected by Symfony. Both default to `NullOutput` before `setOutput()` is called.

All async components share a **single ReactPHP event loop** (`Loop::get()`). The WebSocket server socket is wired onto this loop via `React\Socket\SocketServer` — this is critical; using `IoServer::factory()` would create a separate loop that never runs.

Starts on the shared loop:

### WebSocketServer (Ratchet, port 8082)
- On connect: sends full `init` message with all server states + chat history
- Broadcasts `server_update` on any state change
- Handles incoming `chat` and `heartbeat` messages
- WebSocket payload per server includes: `server`, `playerCount`, `loadedUuids`, `stats`, `memoryProfile`, `votes` (count + voters), `countdownUntil`, `stopCountdownUntil`, `blocked`

### MinecraftMonitor
- **`scan()`** — on startup + every 30s via `addPeriodicTimer`
  - Clears inspect cache, iterates `/mc-data/server*/` directories
  - Calls `DockerClient` to find matching container by mount path
  - Writes to Redis via `RedisClient::setServer()`
  - If running and `startedAt` changed → `openLogStream()`
  - If stopped → clears loaded UUIDs and player count
  - Calls `evaluateCountdown()` after each scan cycle

- **`openLogStream()`**
  - Builds `packNameIndex` from manifest files
  - Opens streaming HTTP: `GET /v1.41/containers/{id}/logs?stdout=1&follow=1&since={startedAt}`
  - Strips 8-byte Docker multiplexed frame headers
  - Buffers incomplete lines per server

- **`refreshStats()`** — every 10s via `addPeriodicTimer`
  - Skips stopped containers (no meaningful stats)
  - Calls `broadcastServerUpdate` after each stat update

- **`evaluateCountdown()`** — called after scan, player join/leave, server stop
  - Calls `VoteManager::checkAndTrigger()` to find start candidate
  - If candidate found → `startCountdown()` (15s ReactPHP timer)
  - If no candidate → calls `evaluateAutoStop()`
  - `evaluateAutoStop()` calls `VoteManager::getServersToAutoStop()` and starts stop countdowns

- **Countdown timers** — two types:
  - Start countdown: fires `fireCountdown()` → validates via `confirmStart()` → calls `restartContainer()`
  - Stop countdown: fires `fireStopCountdown()` → safety-checks players → calls `stopContainer()` → re-evaluates

### Log line regexes
```php
PLAYER_JOIN_REGEX  = '/Player connected:\s+([^,]+),\s+xuid:\s*(\d+)/i'
PLAYER_LEAVE_REGEX = '/Player disconnected:\s+([^,]+),\s+xuid:\s*(\d+)/i'
PACK_STACK_REGEX   = '/Pack Stack\s+-\s+\[\d+]\s+.+\(id:\s*([a-f0-9-]+),/i'
```

---

## VoteManager

Core vote logic service. No ReactPHP dependency — pure Redis reads/writes.

**`checkAndTrigger(): ?string`** — evaluates whether the vote leader can start:
1. Find stopped server with strictly more active votes than any other stopped server
2. No players on any running server
3. No cooldown on leader
4. `ResourceBudgetChecker::canStart()` passes
Returns server name or null.

**`getServersToAutoStop(): list<string>`** — finds running empty servers to stop for resource freeing:
1. Simulate stopping candidates (highest profile first)
2. For each, check `canStartWithProfiles()` with remaining profiles
3. Return minimal set needed, or empty if not possible

**`confirmStart(string): bool`** — re-validates on timer fire (conditions may have changed during 15s).

---

## ResourceBudgetChecker

Slot-based resource checker. Profiles: `low < medium < high`.

Each `resource_limits` entry in `meta.yaml` is a **slot set** — a pool of named slots. A server occupies the lowest available slot at or above its profile level.

**Algorithm per slot set:**
1. Build flat sorted slot list (low→high)
2. Assign running servers (lowest-fit-first)
3. Try to assign candidate with remaining slots
4. Return true if all assignments succeed

**`canStartWithProfiles(string, array)`** — same as `canStart()` but with explicit running profile list, used for auto-stop simulation.

---

## DockerClient

Plain synchronous curl, no Unix socket. Key methods:
- `listMinecraftContainers()` — filters by `ancestor: itzg/minecraft-bedrock-server`
- `inspectContainer(id)` — cached per scan cycle, cleared by `clearInspectCache()`
- `restartContainer(id)`
- `stopContainer(id)`
- `sendCommand(containerId, command)` — Docker exec API
- `getContainerStats(containerId)` — calculates CPU% and memory from raw stats
- `getMemoryProfile(inspectData)` — extracts `MEMORY_PROFILE` env var, defaults to `medium`
- `resolveHostPath(containerPath)` — inspects self container to map container path → host path

---

## Frontend

- **`assets/app.js`** — main JS entry point
- **WebSocket** (`ws://{host}:8082`) — all live updates pushed from server. `init` on connect, `server_update` on any change. Handles: running/stopped badge, uptime, player count, CPU/memory stats, vote count, voter avatars, vote button state, start countdown bar, stop countdown bar, blocking message, card reordering.
- **Uptime ticker** — runs every 1s, reads `data-last-started-at` from card
- **Countdown tickers** — runs every 1s, updates start and stop countdown progress bars
- **Host stats** — polls `/host/stats` every 10s (host memory, not per-container)
- **Card reordering** — sorts by vote count descending, ties preserve existing DOM order (no movement on tie)
- **Heartbeat** — sent immediately on WS connect, then every 30s while page is open

No server status polling — all updates are WebSocket push.

---

## Routes summary

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/` | public | homepage with server status and voting |
| GET/POST | `/login` | public | form login |
| GET | `/logout` | — | clears session |
| GET | `/admin` | ROLE_ADMIN | full dashboard |
| POST | `/server/{name}/addon/install` | ROLE_ADMIN | |
| POST | `/server/{name}/addon/{uuid}/enable` | ROLE_ADMIN | |
| POST | `/server/{name}/addon/{uuid}/disable` | ROLE_ADMIN | |
| POST | `/server/{name}/addon/{uuid}/delete` | ROLE_ADMIN | |
| POST | `/server/{name}/command` | ROLE_ADMIN | |
| POST | `/server/{name}/restart` | ROLE_ADMIN | |
| POST | `/server/{name}/stop` | ROLE_ADMIN | |
| GET | `/server/{name}/image` | public | BinaryFileResponse from mc-server-manager/image.png |
| GET | `/server/{name}/status` | public | JSON: running state + vote state + stats |
| GET | `/commands` | public | JSON from commands.txt |
| GET | `/host/stats` | public | JSON from /proc/meminfo |
| POST | `/server/{name}/vote` | ROLE_USER | toggle vote/retract |
| GET | `/test/seed/{scenario}` | ROLE_ADMIN | dev scenario seeder |
