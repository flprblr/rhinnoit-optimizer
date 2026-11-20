#!/usr/bin/env bash
#
# Runs every check and, only if they all pass, packages the zip.
#
# Fail-fast on purpose: nothing gets packaged out of a red tree.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

step() {
	printf '\n\033[1m==> %s\033[0m\n' "$1"
}

# Fail with a sentence rather than a bare "command not found" from the runner.
if [ ! -x vendor/bin/phpcs ]; then
	echo "Dev dependencies are missing. Run 'composer install' first." >&2
	exit 1
fi

if [ -f package.json ] && [ ! -d node_modules ]; then
	echo "node_modules is missing. Run 'npm install' first." >&2
	exit 1
fi

step 'phpcs'
composer lint

step 'phpstan'
composer analyse

step 'phpunit'
composer test

if [ -f package.json ]; then
	step 'eslint'
	npm run lint:js

	step 'stylelint'
	npm run lint:css

	step 'build'
	npm run build
fi

step 'package'
bin/build-zip.sh
