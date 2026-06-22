#!/bin/bash
set -e
export MSYS_NO_PATHCONV=1

BASE_DIR="$HOME"
SERVERS_ROOT="$BASE_DIR/mc-servers"
mkdir -p "$SERVERS_ROOT"

# ── Find existing server folders (server1, server2, ...) ────────────────────
existing=()
max_num=0
for dir in "$SERVERS_ROOT"/server*; do
    [ -d "$dir" ] || continue
    name=$(basename "$dir")
    if [[ "$name" =~ ^server([0-9]+)$ ]]; then
        existing+=("$name")
        num="${BASH_REMATCH[1]}"
        if [ "$num" -gt "$max_num" ]; then
            max_num="$num"
        fi
    fi
done

# Next available folder name
NEXT="server$((max_num + 1))"

# ── Ask which folder to use ───────────────────────────────────────────────────
echo "Existing server folders in $SERVERS_ROOT:"
if [ ${#existing[@]} -eq 0 ]; then
    echo "  (none)"
else
    for f in "${existing[@]}"; do
        echo "  $f"
    done
fi
echo ""
echo "Enter server folder name to use (serverN), or press Enter to create '$NEXT':"
read -r DATA_FOLDER
if [ -z "$DATA_FOLDER" ]; then
    DATA_FOLDER="$NEXT"
fi

if [[ ! "$DATA_FOLDER" =~ ^server[0-9]+$ ]]; then
    echo "Error: Folder name must match serverN (e.g. server1, server2)."
    exit 1
fi

DATA_PATH="$SERVERS_ROOT/$DATA_FOLDER"

# ── Ask for external port ─────────────────────────────────────────────────────
echo ""
echo "Enter external port (default: 19132):"
read -r PORT
if [ -z "$PORT" ]; then
    PORT=19132
fi

# ── Ask for seed ──────────────────────────────────────────────────────────────
echo ""
echo "Enter world seed (or press Enter to skip):"
read -r SEED

# ── Ask for memory tier ───────────────────────────────────────────────────────
echo ""
echo "Select memory limit:"
echo "  1) Low    - 2.5GB  (lightly used / few players)"
echo "  2) Medium - 3GB    (regular use, some players)"
echo "  3) High   - 3.5GB  (heavy use, many players / lots of chunks)"
echo "Enter choice (default: 2):"
read -r MEM_CHOICE
case "$MEM_CHOICE" in
    1) MEMORY="2560m" ; MEMORY_PROFILE="low"    ;;
    3) MEMORY="3584m" ; MEMORY_PROFILE="high"   ;;
    *) MEMORY="3g"    ; MEMORY_PROFILE="medium" ;;
esac

# ── Derive container name from folder ────────────────────────────────────────
SERVER_NUM="${DATA_FOLDER#server}"
CONTAINER_NAME="mc-server-$SERVER_NUM"

# ── Build docker run command ──────────────────────────────────────────────────
ARGS=(
    -d -it
    --name "$CONTAINER_NAME"
    -e EULA=TRUE
    -e ALLOW_CHEATS=true
    --restart=unless-stopped
    -e GAMEMODE=creative
    --memory="$MEMORY"
    --memory-swap="$MEMORY"
    -e MEMORY_PROFILE="$MEMORY_PROFILE"
    -p "$PORT:19132/udp"
    -v "$DATA_PATH:/data"
)

if [ -n "$SEED" ]; then
    ARGS+=(-e "LEVEL_SEED=$SEED")
fi

ARGS+=(itzg/minecraft-bedrock-server)

# ── Confirm ───────────────────────────────────────────────────────────────────
echo ""
echo "About to run:"
echo "docker run $(printf '%q ' "${ARGS[@]}")"
echo ""
echo "Continue? (y/n)"
read -r CONFIRM
if [ "$CONFIRM" != "y" ]; then
    echo "Aborted."
    exit 0
fi

# ── Execute ───────────────────────────────────────────────────────────────────
docker run "${ARGS[@]}"
echo ""
echo "Server started in $DATA_PATH on port $PORT with memory limit $MEMORY (profile: $MEMORY_PROFILE)."
