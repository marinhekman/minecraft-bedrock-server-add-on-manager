#!/bin/bash
# Usage: bash reset-passwords.sh

USERS_YAML="$HOME/mc-server-manager-data/users.yaml"
CONTAINER="mc-server-manager"

# Read usernames from users.yaml
USERS=$(grep -E "^    [a-zA-Z]" "$USERS_YAML" | sed 's/://g' | tr -d ' ')

echo "Resetting passwords for all users..."
echo ""

for USERNAME in $USERS; do
    # Generate a random 10-char password
    PASSWORD=$(openssl rand -base64 8 | tr -dc 'a-zA-Z0-9' | head -c 10)

    # Hash it using Symfony
    HASH=$(docker exec -i "$CONTAINER" php bin/console security:hash-password --no-interaction "$PASSWORD" 2>/dev/null | grep "Password hash" | grep -oP '\$2y\$[^\s]+')

    if [ -z "$HASH" ]; then
        echo "❌ Failed to hash password for $USERNAME"
        continue
    fi

    # Replace the password line in users.yaml
    # Matches: "        password: '...anything...'"
    sed -i "/^    $USERNAME:/,/^    [a-zA-Z]/{s|password: '.*'|password: '$HASH'|}" "$USERS_YAML"

    echo "✅ $USERNAME: $PASSWORD"
done

echo ""
echo "Done. users.yaml updated."
