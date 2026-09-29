#!/bin/sh
# deps:check — what the nightly autobump (ops/scripts/deps-autobump.sh) runs
# after it bumped a dependency, in a fresh clone. Both ecosystems, because a
# bump installs only one; the same linters as the lint job of
# .github/workflows/ci.yml, against FreshRSS core at the ref CI pins.
set -eu
[ -d .freshrss-core ] || git clone -q --depth 1 --branch 1.29.0 https://github.com/FreshRSS/FreshRSS .freshrss-core
pnpm install --frozen-lockfile --silent
composer install --no-interaction --quiet
vendor/bin/phpcs .
# The machine's php.ini may cap memory at 128M (setup-php in CI does not).
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
pnpm run eslint
