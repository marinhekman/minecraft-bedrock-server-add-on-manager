#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# Display information about the current project
	php bin/console -V
fi

exec docker-php-entrypoint "$@"
