#!/bin/sh
# ══════════════════════════════════════════════════════
# frankenphp/docker-entrypoint.sh
# ══════════════════════════════════════════════════════
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    composer dump-env prod
    php bin/console cache:clear
    php bin/console cache:warmup

	# Display information about the current project
	php bin/console -V

	# Start supervisor (manages websocket server)
	echo "Starting supervisor..."
	rm -f /run/supervisord.sock
	/usr/bin/supervisord -c /etc/supervisord.conf
fi

exec docker-php-entrypoint "$@"
