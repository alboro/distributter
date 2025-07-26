#!/bin/sh

Fail() {
  echo "ERROR: $@" 1>&2
  exit 1
}

which realpath >/dev/null || Fail "realpath not found"
which php      >/dev/null || Fail "php not found"
which composer      >/dev/null || Fail "composer not found"

cd "$(realpath "$(dirname "$0")"/..)"

echo "Installing production dependencies..."
composer install --prefer-dist --no-interaction --no-dev --optimize-autoloader
composer dump-autoload --optimize --no-dev --classmap-authoritative

echo "Build completed successfully!"
