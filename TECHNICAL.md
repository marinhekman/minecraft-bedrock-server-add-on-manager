# Technical Implementation — Minecraft Bedrock Add-on Manager

> For general features, installation, folder structure and usage see [README.md](README.md).

This document covers the internal architecture and implementation details intended as AI assistant context.

---

## Stack

- **PHP 8.4** / **Symfony 7.4**
- **FrankenPHP** as the web server, runs via `frankenphp run --config /etc/frankenphp/Caddyfile`
- **Predis** for Redis access
- **ReactPHP** (`react/http`, `react/event-loop`) for async Docker log streaming
- **Ratchet** (custom fork from `BredaUniversityResearch/Ratchet`) for WebSocket server
- **Bootstrap 5** + **Stimulus** + **Turbo** on the frontend
- **Twig** templates

---

## Docker setup

Four containers on shared `mc-net` Docker network. See README for container list and volume mounts.

The app does **not** mount the Docker socket directly — it talks to `mc-docker-api` over HTTP via `DockerClient` using plain curl. URL configured via `DOCKER_API_URL` env var (typically `http://host.docker.internal:2375`).

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
        HomeController.php             ← / (public, passes serverData + meta to template)
        HostStatsController.php        ← /host/stats (JSON RAM stats from /proc/meminfo)
        SecurityController.php         ← /login, /logout
        ServerController.php           ← /server/{name}/command, restart, image
        ServerStatusController.php     ← /server/{name}/status (JSON, polled every 10s)
    Model/
        AddonManifest.php              ← parsed manifest.json data
        AddonPack.php                  ← pack + manifest + enabled + isSystem flags
        AddonType.php                  ← enum: Behaviour, Resource, Script
        ServerInstance.php             ← server state built from Redis data
        ServerMeta.php                 ← display_name, description, imagePath + getDisplayName(fallback)
    Security/
        User.php                       ← UserInterface + PasswordAuthenticatedUserInterface
                                         properties: username, password, gamertag, xuid, roles
                                         getAvatarPath(): /avatars/{username}.png or /images/avatar_default.png
        UserProvider.php               ← loads from /mc-data/config/users.yaml
    Server/
        MinecraftMonitor.php           ← ReactPHP async log streamer + server scanner
        WebSocketServer.php            ← Ratchet MessageComponentInterface
    Service/
        AddonInstaller.php             ← installs .mcaddon/.mcpack files
        AddonScanner.php               ← scans behavior_packs/ and resource_packs/ folders
        DependencyChecker.php          ← checks UUID dependencies between packs
        DockerClient.php               ← synchronous curl HTTP client for Docker API
        ManifestParser.php             ← parses manifest.json files
        RedisClient.php                ← typed Redis wrapper (see key schema below)
        ServerMetaReader.php           ← reads mc-server-manager/meta.yaml per server
        ServerRegistry.php             ← builds ServerInstance objects from Redis data
        WorldPacksManager.php          ← reads/writes world_*_packs.json files
```

---

## Authentication

See README for `users.yaml` format and user setup.

- `src/Security/User.php` — bcrypt passwords, `getAvatarPath()` checks filesystem
- `src/Security/UserProvider.php` — reads `/mc-data/config/users.yaml` on every request (no cache)
- `config/packages/security.yaml` — form_login firewall, `/admin` requires `ROLE_ADMIN`, `/` is public
- Sessions in Redis (`handler_id: '%env(REDIS_URL)%'`), 30-day lifetime, auto-renewed per request

---

## Redis key schema

Managed exclusively via `RedisClient`:

| Key | Type | TTL | Content |
|---|---|---|---|
| `server:{name}` | string (JSON) | 60s | `{name, containerId, containerName, containerStatus, port, startedAt, running}` |
| `players:{name}` | string (int) | 60s | player count |
| `loaded:{name}` | string (JSON) | 60s | array of loaded pack UUIDs |
| `stats:{name}` | string (JSON) | 60s | `{cpu, memUsageMb, memLimitMb, memPercent}` |
| `chat` | list | — | last 100 chat messages (JSON) |
| `votes` | hash | — | `{ gamertag => serverName }` |
| `heartbeat:{gamertag}` | string | 120s | Unix timestamp |

---

## Background process — WebSocketServerCommand

Runs under **Supervisor** inside the container (`/var/log/supervisor/websocket.log`).

`MinecraftMonitor` and `WebSocketServer` are injected by Symfony (no manual `new`). `OutputInterface` is set via `setOutput()` after construction since it's only available at command execution time. Both default to `NullOutput` before `setOutput()` is called.

Starts two things on the same ReactPHP event loop:

### WebSocketServer (Ratchet, port 8082)
- On connect: sends full `init` message with all server states + chat history
- Broadcasts `server_update` on any state change
- Handles incoming `chat` and `heartbeat` messages

### MinecraftMonitor
- **`scan()`** — on startup + every 30s via `addPeriodicTimer`
  - Iterates `/mc-data/server*/` directories (checks for `worlds/` or `server.properties`)
  - Calls `DockerClient` to find matching container by comparing mount paths via `resolveHostPath()`
  - Writes to Redis via `RedisClient::setServer()`
  - If running and `startedAt` changed → `openLogStream()`
  - If stopped → clears loaded UUIDs and player count

- **`openLogStream()`**
  - Calls `buildPackNameIndex()` to build `name → [uuids]` map from both `behavior_packs/` and `resource_packs/` manifest files
  - Tests connectivity with `GET /version` first
  - Opens streaming HTTP: `GET /v1.41/containers/{id}/logs?stdout=1&follow=1&since={startedAt}`
  - Uses `React\Http\Browser::requestStreaming()`
  - Strips 8-byte Docker multiplexed frame headers (non-TTY containers)
  - Buffers incomplete lines per server

- **`refreshStats()`** — every 10s via `addPeriodicTimer`

### Log line regexes
```php
PLAYER_JOIN_REGEX  = '/Player connected:\s+([^,]+),\s+xuid:\s*(\d+)/i'
PLAYER_LEAVE_REGEX = '/Player disconnected:\s+([^,]+),\s+xuid:\s*(\d+)/i'
PACK_STACK_REGEX   = '/Pack Stack\s+-\s+\[\d+\]\s+.+\(id:\s*([a-f0-9\-]+),/i'
```

When a pack UUID is detected, `packNameIndex` is used to also mark any pack with the same `manifest.name` as loaded — this handles resource packs paired with behaviour packs (Bedrock only logs behaviour pack UUIDs).

---

## DockerClient

Plain synchronous curl, no Unix socket. Key methods:
- `listMinecraftContainers()` — filters by `ancestor: itzg/minecraft-bedrock-server`
- `inspectContainer(id)`
- `restartContainer(id)`
- `sendCommand(containerId, command)` — Docker exec API
- `getContainerStats(containerId)` — calculates CPU% and memory from raw stats
- `resolveHostPath(containerPath)` — inspects self container to map container path → host path

---

## Frontend

- **`assets/app.js`** — main JS entry point
- **Polling** (`initPolling`): every 10s fetches `/server/{name}/status`, updates `.stat-cpu`, `.stat-mem`, `.stat-uptime`, `.stat-players` badges and `.status-badge` per pack row. Only activates on cards with `data-status-url` attribute.
- **Uptime ticker**: runs every 1s, reads `data-last-started-at` from card, updates `.stat-uptime`
- **WebSocket**: `ws://host:8082` — `init` and `server_update` messages handled. Note: currently the WebSocket connection is not yet wired in `app.js` — polling is used for live updates on both homepage and admin dashboard.

---

## Routes summary

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/` | public | homepage with server status |
| GET/POST | `/login` | public | form login |
| GET | `/logout` | — | clears session |
| GET | `/admin` | ROLE_ADMIN | full dashboard |
| POST | `/server/{name}/addon/install` | ROLE_ADMIN | |
| POST | `/server/{name}/addon/{uuid}/enable` | ROLE_ADMIN | |
| POST | `/server/{name}/addon/{uuid}/disable` | ROLE_ADMIN | |
| POST | `/server/{name}/addon/{uuid}/delete` | ROLE_ADMIN | |
| POST | `/server/{name}/command` | ROLE_ADMIN | |
| POST | `/server/{name}/restart` | ROLE_ADMIN | |
| GET | `/server/{name}/image` | public | BinaryFileResponse from mc-server-manager/image.png |
| GET | `/server/{name}/status` | public | JSON polled every 10s |
| GET | `/commands` | public | JSON from commands.txt |
| GET | `/host/stats` | public | JSON from /proc/meminfo |
