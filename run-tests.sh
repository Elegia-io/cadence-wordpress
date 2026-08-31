#!/usr/bin/env bash
# Run the test suite. There is no PHP on the host, so it runs in a container.
#
# The image is the WP-CLI one because it already carries the PHP extensions a
# WordPress plugin can assume, and PHPUnit is a phar rather than a composer
# dependency: this plugin has NO runtime dependencies and adding a vendor tree
# to acquire a test runner would be the largest thing in the repository.
set -euo pipefail
: "${PODMAN:=sudo -n podman}"
: "${PHP_IMAGE:=docker.io/library/wordpress:cli-php8.3}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
: "${PHPUNIT_PHAR:=${HERE}/.phpunit.phar}"

if [ ! -f "$PHPUNIT_PHAR" ]; then
  echo "run-tests: fetching PHPUnit to $PHPUNIT_PHAR" >&2
  curl -sSfL -o "$PHPUNIT_PHAR" https://phar.phpunit.de/phpunit-11.phar
fi

exec $PODMAN run --rm --entrypoint php \
  -v "${HERE}:/p:z" -w /p "$PHP_IMAGE" \
  /p/.phpunit.phar --bootstrap tests/bootstrap.php --do-not-cache-result --colors=never "$@" tests
