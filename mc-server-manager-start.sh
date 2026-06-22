#!/bin/bash
export MSYS_NO_PATHCONV=1

IMAGE_NAME="minecraft-bedrock-server-add-on-manager"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Run from the repo/script directory so Docker commands can use relative paths.
# This avoids Git Bash absolute path issues like /d/... when MSYS path conversion is disabled.
cd "$SCRIPT_DIR" || {
    echo "Error: Failed to change directory to $SCRIPT_DIR"
    exit 1
}

# Clean-up any existing manager container
docker stop mc-server-manager 2>/dev/null || true
docker rm mc-server-manager 2>/dev/null || true

# Ensure network exists
docker network create mc-net 2>/dev/null || true

# Ensure docker-api is running on the network
if ! docker ps --filter "name=mc-docker-api" --filter "status=running" -q | grep -q .; then
    docker stop mc-docker-api 2>/dev/null || true
    docker rm mc-docker-api 2>/dev/null || true

    # Build local image if not present
    if ! docker image inspect mc-docker-api >/dev/null 2>&1; then
        echo "Building mc-docker-api image..."
        docker build -t mc-docker-api ./api
    fi

    docker run -d \
        --name mc-docker-api \
        --network mc-net \
        --restart unless-stopped \
        -v /var/run/docker.sock:/var/run/docker.sock \
        -p 2375:2375 \
        mc-docker-api
fi

# Ensure Redis is running on the network
if ! docker ps --filter "name=mc-redis" --filter "status=running" -q | grep -q .; then
    docker stop mc-redis 2>/dev/null || true
    docker rm mc-redis 2>/dev/null || true
    docker run -d \
        --name mc-redis \
        --network mc-net \
        --restart unless-stopped \
        redis:7-alpine
fi

# Build volume args array
VOLUMES=()
VOLUMES+=(-v /proc/meminfo:/proc/meminfo:ro)

# Single mount containing all server folders (server1, server2, ...)
SERVERS_ROOT="$HOME/mc-servers"
mkdir -p "$SERVERS_ROOT"
VOLUMES+=(-v "$SERVERS_ROOT:/mc-data")
VOLUMES+=(-v "$HOME/mc-server-manager-data:/mc-data/config:ro")

# Convenience: mount avatars to public folder for web serving
if [ -d "$HOME/mc-server-manager-data/avatars" ]; then
    VOLUMES+=(-v "$HOME/mc-server-manager-data/avatars:/app/public/avatars:ro")
fi

SERVER_COUNT=$(find "$SERVERS_ROOT" -maxdepth 1 -type d -name 'server*' | wc -l | tr -d ' ')
echo "Found $SERVER_COUNT server folder(s) in $SERVERS_ROOT."
echo "Starting mc-server-manager with volumes:"
for v in "${VOLUMES[@]}"; do echo "  $v"; done
echo ""

# Ensure manager image exists locally
if ! docker image inspect "$IMAGE_NAME" >/dev/null 2>&1; then
    echo "Image '$IMAGE_NAME' not found locally. Building it now..."
    if ! docker build -t "$IMAGE_NAME" .; then
        echo "Error: Failed to build '$IMAGE_NAME'."
        exit 1
    fi
fi

if ! CID=$(docker run -d \
  --name mc-server-manager \
  --network mc-net \
  --restart unless-stopped \
  --env-file .env \
  --add-host=host.docker.internal:host-gateway \
  "${VOLUMES[@]}" \
  -p 8080:80 \
  -p 8082:8082 \
  "$IMAGE_NAME"); then
    echo "Error: Failed to start mc-server-manager container."
    exit 1
fi

echo ""
echo "mc-server-manager started ($CID). Checking logs..."
sleep 2
docker logs mc-server-manager --tail 20
