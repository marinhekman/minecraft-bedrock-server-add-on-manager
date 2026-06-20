#!/bin/bash
# Usage: bash set-passwords.sh
# Prompts for a new password for each user in users.yaml and updates the file.

USERS_YAML="$HOME/mc-server-manager-data/users.yaml"
CONTAINER="mc-server-manager"

if [ ! -f "$USERS_YAML" ]; then
    echo "❌ users.yaml not found at $USERS_YAML"
    exit 1
fi

# Read usernames from users.yaml
USERS=$(grep -E "^    [a-zA-Z]" "$USERS_YAML" | sed 's/://g' | tr -d ' ')

echo "Setting passwords for users in $USERS_YAML"
echo ""

# Track results for summary
declare -A SUMMARY

for USERNAME in $USERS; do
    while true; do
        read -rsp "Enter new password for '$USERNAME' (leave blank to skip): " PASSWORD
        echo ""

        if [ -z "$PASSWORD" ]; then
            echo "⏭️  Skipped $USERNAME"
            echo ""
            SUMMARY[$USERNAME]="skipped"
            break
        fi

        read -rsp "Confirm password for '$USERNAME': " PASSWORD_CONFIRM
        echo ""

        if [ "$PASSWORD" != "$PASSWORD_CONFIRM" ]; then
            echo "❌ Passwords do not match, try again."
            echo ""
            continue
        fi

        HASH=$(docker exec -i "$CONTAINER" php bin/console security:hash-password --no-interaction "$PASSWORD" 2>/dev/null | grep "Password hash" | grep -oP '\$2y\$[^\s]+')

        if [ -z "$HASH" ]; then
            echo "❌ Failed to hash password for $USERNAME"
            echo ""
            SUMMARY[$USERNAME]="failed"
            break
        fi

        sed -i "/^    $USERNAME:/,/^    [a-zA-Z]/{s|password: '.*'|password: '$HASH'|}" "$USERS_YAML"

        echo "✅ Password updated for $USERNAME"
        echo ""
        SUMMARY[$USERNAME]="$PASSWORD"
        break
    done
done

echo "──────────────────────────────────────"
echo "Summary"
echo "──────────────────────────────────────"
for USERNAME in $USERS; do
    VALUE="${SUMMARY[$USERNAME]}"
    if [ "$VALUE" = "skipped" ]; then
        echo "⏭️  $USERNAME — skipped"
    elif [ "$VALUE" = "failed" ]; then
        echo "❌ $USERNAME — failed"
    else
        echo "✅ $USERNAME — $VALUE"
    fi
done
echo "──────────────────────────────────────"
