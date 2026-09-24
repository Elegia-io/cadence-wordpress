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

$PODMAN run --rm --entrypoint php \
  -v "${HERE}:/p:z" -w /p "$PHP_IMAGE" \
  /p/.phpunit.phar --bootstrap tests/bootstrap.php --do-not-cache-result --colors=never "$@" tests

# WHAT THE BUILD SCRIPT WILL AND WILL NOT BUILD, on the HOST rather than in
# the container, because `build-zip.py` is a host script and the php image has
# no python3. This is the only python in the suite and it is here because the
# `Contributors:` guard is the one thing in this repo that can ship a zip under
# somebody else's name. Unconditional for the same reason the boundary check
# below is: a run narrowed by "$@" proves nothing about it.
python3 "${HERE}/tests/build_zip_test.py"

# AND THE BOUNDARY BETWEEN THE TWO LANES, unconditionally -- including after a
# run narrowed by "$@", since the check measures the whole suite and what a
# filtered run proves about the split is nothing. It runs both lanes itself, in
# under a second. See tests/wpml-boundary.php for what the line is and why it
# falls where it does.
exec $PODMAN run --rm --entrypoint php \
  -v "${HERE}:/p:z" -w /p "$PHP_IMAGE" \
  /p/tests/wpml-boundary.php
