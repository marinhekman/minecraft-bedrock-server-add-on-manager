#!/bin/bash
set -e
export MSYS_NO_PATHCONV=1

BASE_DIR="$HOME"

# ── Find existing minecraft-data folders ─────────────────────────────────────
existing=()
[ -d "$BASE_DIR/minecraft-data" ] && existing+=("minecraft-data")
i=2
while [ -d "$BASE_DIR/minecraft-data$i" ]; do
    existing+=("minecraft-data$i")
    i=$((i + 1))
done

# Next available folder name
if [ ! -d "$BASE_DIR/minecraft-data" ]; then
    NEXT="minecraft-data"
else
    NEXT="minecraft-data$i"
fi

# ── Ask which folder to use ───────────────────────────────────────────────────
echo "Existing minecraft-data folders:"
if [ ${#existing[@]} -eq 0 ]; then
    echo "  (none)"
else
    for f in "${existing[@]}"; do
        echo "  $f"
    done
fi
echo ""
echo "Enter folder name to use (or press Enter to create '$NEXT'):"
read -r DATA_FOLDER
if [ -z "$DATA_FOLDER" ]; then
    DATA_FOLDER="$NEXT"
fi
DATA_PATH="$BASE_DIR/$DATA_FOLDER"

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
    1) MEMORY="2560m" ;;
    3) MEMORY="3584m" ;;
    *) MEMORY="3g" ;;
esac

# ── Derive container name from folder ────────────────────────────────────────
if [ "$DATA_FOLDER" = "minecraft-data" ]; then
    CONTAINER_NAME="mc-server"
else
    CONTAINER_NAME="mc-server-${DATA_FOLDER//minecraft-data/}"
fi

# ── Build docker run command ──────────────────────────────────────────────────
CMD="docker run -d -it \
  --name $CONTAINER_NAME \
  -e EULA=TRUE \
  -e ALLOW_CHEATS=true \
  --restart=unless-stopped \
  -e GAMEMODE=creative \
  --memory=$MEMORY \
  --memory-swap=$MEMORY \
  -p $PORT:19132/udp \
  -v $DATA_PATH:/data"

if [ -n "$SEED" ]; then
    CMD="$CMD \
  -e LEVEL_SEED=\"$SEED\""
fi

CMD="$CMD \
  itzg/minecraft-bedrock-server"

# ── Confirm ───────────────────────────────────────────────────────────────────
echo ""
echo "About to run:"
echo "$CMD"
echo ""
echo "Continue? (y/n)"
read -r CONFIRM
if [ "$CONFIRM" != "y" ]; then
    echo "Aborted."
    exit 0
fi

# ── Execute ───────────────────────────────────────────────────────────────────
ARGS=(
    -d -it
    --name "$CONTAINER_NAME"
    -e EULA=TRUE
    -e ALLOW_CHEATS=true
    --restart=unless-stopped
    -e GAMEMODE=creative
    --memory="$MEMORY"
    --memory-swap="$MEMORY"
    -p "$PORT:19132/udp"
    -v "$DATA_PATH:/data"
)

if [ -n "$SEED" ]; then
    ARGS+=(-e "LEVEL_SEED=$SEED")
fi

ARGS+=(itzg/minecraft-bedrock-server)

docker run "${ARGS[@]}"
echo ""
echo "Server started in $DATA_PATH on port $PORT with memory limit $MEMORY."

