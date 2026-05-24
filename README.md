# 🧱 Minecraft Bedrock Add-on Manager

A web-based dashboard for managing add-ons (behaviour packs and resource packs) on your [itzg/minecraft-bedrock-server](https://github.com/itzg/docker-minecraft-bedrock-server) instances, with a public-facing server status page and user authentication.

![PHP](https://img.shields.io/badge/PHP-8.4-blue)
![Symfony](https://img.shields.io/badge/Symfony-7.4-black)
![FrankenPHP](https://img.shields.io/badge/FrankenPHP-1-purple)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-blueviolet)
![License](https://img.shields.io/badge/license-AGPL--v3-blue)

## Features

### Public homepage (`/`)
- 🌐 Public server status page visible to anyone, no login required
- 🖥️ Shows each server's display name, description and image (configured via `mc-server-manager/meta.yaml` inside the server data folder)
- 🟢 Live running status, uptime and online player count per server
- 📦 Lists enabled add-ons (name, type, version) per server
- 👤 Shows logged-in user's Xbox gamertag and avatar with role badge (Admin / User / Anonymous)

### Admin dashboard (`/admin`)
- 📋 Overview of all user-installed add-ons per server with enabled/disabled status
- ✅ Enable and disable add-ons with a single click
- ⬆️ Install add-ons by uploading `.mcaddon` or `.mcpack` files directly from the browser
- 🗑️ Remove add-ons with a confirmation dialog
- 🔄 Restart your Minecraft server directly from the dashboard
- 🟦 Live **Loaded** status per add-on — shows whether each pack was actually loaded by the server on its last boot, detected via Docker log streaming
- 📊 Live **CPU%, memory usage, uptime and player count** per server container, updated every 10 seconds
- 🖥️ Host machine **memory usage bar** showing total/used/available RAM
- 💻 **Send commands** to a running server directly from the dashboard, with a pre-configured command list from `commands.txt`
- 🐳 Automatic container detection via Docker API — no manual container name configuration needed
- ⚠️ Dependency validation — warns when a pack has unmet UUID dependencies
- ⚙️ Built-in system packs shown separately in a collapsed section
- 🔒 Version protection — prevents accidental downgrades when reinstalling a pack
- 🔁 Resource pack loaded state inferred from matching behaviour pack name in logs

### Authentication
- 🔐 Username/password login with sessions stored in Redis (survives container restarts)
- 👑 Role-based access: `ROLE_ADMIN` for the dashboard, `ROLE_USER` for the homepage
- 🎮 Xbox gamertag and xuid linked per user account
- 🖼️ User avatar displayed in navbar and homepage header
- 📝 Users configured via `users.yaml` (mounted at runtime, not committed to git)
- 🔑 Sessions persist for 30 days with automatic renewal on each visit

## Screenshot

![Minecraft Bedrock Add-on Manager dashboard](screenshot.png "Minecraft Bedrock Add-on Manager dashboard")

## Prerequisites

- **Docker** installed on your host
- One or more running [itzg/minecraft-bedrock-server](https://github.com/itzg/docker-minecraft-bedrock-server) containers, each with its `/data` folder bind-mounted to a host path, e.g.:
  ```bash
  docker run -d \
    -e EULA=TRUE \
    -p 19132:19132/udp \
    -v /home/user/minecraft-data:/data \
    --name mc-server \
    itzg/minecraft-bedrock-server
  ```

## Installation

### 1. Clone and build

```bash
git clone https://github.com/marinhekman/minecraft-bedrock-server-add-on-manager.git
cd minecraft-bedrock-server-add-on-manager

docker build -t minecraft-bedrock-server-add-on-manager .
```

### 2. Configure environment

```bash
cp .env.example .env
```

Edit `.env`:

```env
# Generate with: openssl rand -hex 32
APP_SECRET=your_random_secret_here

# Use :80 for plain HTTP (recommended for local/LAN use)
SERVER_NAME=:80

# Site title — supports HTML entities, use &nbsp; instead of spaces
APP_TITLE_RAW=MC&nbsp;SERVERS

# Redis URL (used for sessions and live server state)
REDIS_URL=redis://mc-redis:6379

# Docker API URL (mc-docker-api container)
DOCKER_API_URL=http://host.docker.internal:2375
```

### 3. Set up runtime data

Create the config folder and add your users:

```bash
mkdir -p ~/mc-server-manager-data/avatars
cp users.yaml.example ~/mc-server-manager-data/users.yaml
```

Edit `~/mc-server-manager-data/users.yaml`:

```yaml
users:
    yourname:
        # Generate with: docker exec -it mc-server-manager php bin/console security:hash-password
        password: '$2y$13$...'
        gamertag: 'YourXboxGamertag'
        xuid: '2535123456789'
        roles: ['ROLE_ADMIN']
```

Place user avatars (96×96px PNG) in `~/mc-server-manager-data/avatars/yourname.png`.

A default avatar fallback is built into the image at `public/images/avatar_default.png`.

### 4. Optionally configure per-server metadata

Inside each `minecraft-data` folder, create:

```
minecraft-data/
    mc-server-manager/
        meta.yaml
        image.png     ← 512×512px recommended
```

`meta.yaml` format:

```yaml
display_name: "My Creative World"
description: "Our main survival world for the gang."
```

### 4b. Configure global voting and resource limits

```bash
cp meta.yaml.example ~/mc-server-manager-data/meta.yaml
```

Edit `~/mc-server-manager-data/meta.yaml` to match your server hardware.
See [`meta.yaml.example`](meta.yaml.example) for all available options and resource limit examples.

To override vote settings per server, create `mc-server-manager/meta.yaml`
inside each server data folder. See [`server-meta.yaml.example`](server-meta.yaml.example).

### 5. Run

Use the provided start script which handles all containers and volume mounts:

```bash
bash mc-server-manager-start.sh
```

The script automatically:
- Starts the `mc-docker-api` container (Docker API proxy)
- Starts `mc-redis` for sessions and live state
- Mounts `~/mc-server-manager-data` → `/mc-data/config`
- Mounts `~/minecraft-data` → `/mc-data/server1`, `~/minecraft-data2` → `/mc-data/server2`, etc.
- Mounts avatars folder to the public web directory

The dashboard is available at [http://localhost:8080](http://localhost:8080).

### 6. Create user passwords

```bash
docker exec -it mc-server-manager php bin/console security:hash-password
```

## Folder structure on host

```
~/
    mc-server-manager-data/
        users.yaml              ← user accounts (not in git)
        commands.txt            ← optional command suggestions
        avatars/
            yourname.png        ← user avatars (96×96px)
    minecraft-data/             → mounted as server1
        mc-server-manager/
            meta.yaml           ← display name, description
            image.png           ← server image (512×512px)
        behavior_packs/
        resource_packs/
        worlds/
        server.properties
    minecraft-data2/            → mounted as server2
        ...
```

## How it works

The manager mounts each Minecraft server's `minecraft-data` folder and reads/writes:

- `behavior_packs/*/manifest.json` — discovers installed behaviour packs
- `resource_packs/*/manifest.json` — discovers installed resource packs
- `worlds/Bedrock level/world_behavior_packs.json` — enables/disables behaviour packs
- `worlds/Bedrock level/world_resource_packs.json` — enables/disables resource packs
- `mc-server-manager/meta.yaml` — reads display name and description
- `mc-server-manager/image.png` — reads server image

It connects to the Docker API (via `mc-docker-api` container) to automatically match each mounted data folder to its running container, retrieve port and status information, send restart signals, and stream container logs in real time to determine which packs were loaded and how many players are online.

User-installed packs are stored in folders prefixed with `user_` so they can be reliably distinguished from built-in server packs.

> **Note:** After enabling or disabling add-ons, the Minecraft server must be restarted for changes to take effect.

## Updating

```bash
git pull
docker build --no-cache -t minecraft-bedrock-server-add-on-manager .
docker stop mc-server-manager && docker rm mc-server-manager
bash mc-server-manager-start.sh
```

## Security

Access to `/admin` requires `ROLE_ADMIN`. The public homepage at `/` is accessible anonymously but shows read-only information only.

The Docker API is accessed via the `mc-docker-api` sidecar container rather than a direct socket mount, reducing the attack surface. No containers are created or deleted by this application.

## License

This project is licensed under the **GNU Affero General Public License v3.0 (AGPLv3)**.
You are free to use, modify, and distribute this software, but any modified version
must also be released under the AGPLv3, including when run as a network service.

If you build upon this project, the author kindly requests that you retain a visible credit:

> Based on [Minecraft Bedrock Add-on Manager](https://github.com/marinhekman/minecraft-bedrock-server-add-on-manager) by Marin Hekman.

See the [LICENSE](LICENSE) file for full details.
