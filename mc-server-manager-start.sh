#!/bin/bash
export MSYS_NO_PATHCONV=1

# Clean-up any existing manager container
docker stop mc-server-manager 2>/dev/null || true
docker rm mc-server-manager 2>/dev/null || true

# Ensure network exists
docker network create mc-net 2>/dev/null || true

# Ensure docker-api is running on the network
if ! docker ps --filter "name=mc-docker-api" --filter "status=running" -q | grep -q .; then
    docker stop mc-docker-api 2>/dev/null || true
    docker rm mc-docker-api 2>/dev/null || true
    docker run -d \
        --name mc-docker-api \
        -p 2375:2375 \
        --network mc-net \
        --restart unless-stopped \
        -v /var/run/docker.sock:/var/run/docker.sock \
        docker-hub.mspchallenge.info/cradlewebmaster/docker-api:latest
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

# Add commands.txt if it exists
if [ -f "$HOME/mc-commands.txt" ]; then
    VOLUMES+=(-v "$HOME/mc-commands.txt:/mc-data/commands.txt:ro")
fi

# Add minecraft-data (no number) first
SERVER_COUNT=0
if [ -d "$HOME/minecraft-data" ]; then
    VOLUMES+=(-v "$HOME/minecraft-data:/mc-data/server1")
    SERVER_COUNT=$((SERVER_COUNT + 1))
fi

# Add minecraft-data2, minecraft-data3, etc.
for dir in "$HOME"/minecraft-data[0-9]*; do
    if [ -d "$dir" ]; then
        name=$(basename "$dir")
        num=${name#minecraft-data}
        VOLUMES+=(-v "$dir:/mc-data/server$num")
        SERVER_COUNT=$((SERVER_COUNT + 1))
    fi
done

# Require at least one server folder
if [ "$SERVER_COUNT" -eq 0 ]; then
    echo "Error: No minecraft-data folders found in $HOME."
    echo "Please create at least one folder (e.g. $HOME/minecraft-data) before starting the manager."
    exit 1
fi

echo "Found $SERVER_COUNT server folder(s)."
echo "Starting mc-server-manager with volumes:"
for v in "${VOLUMES[@]}"; do echo "  $v"; done
echo ""

docker run -d \
  --name mc-server-manager \
  --network mc-net \
  --restart unless-stopped \
  --env-file .env \
  "${VOLUMES[@]}" \
  -p 8080:80 \
  minecraft-bedrock-server-add-on-manager

echo ""
echo "mc-server-manager started. Checking logs..."
sleep 2
docker logs mc-server-manager --tail 20
