#!/usr/bin/env bash
#
# Builds the installable zip in dist/, honouring .distignore.
#
# Reads the slug and version from the plugin header, so it keeps working after
# bin/new-plugin.sh has run.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MAIN="$(grep -l '^ \* Plugin Name:' ./*.php 2>/dev/null | head -1 || true)"

if [ -z "$MAIN" ]; then
	echo "No plugin header found in $ROOT." >&2
	exit 1
fi

SLUG="$(basename "$MAIN" .php)"
VERSION="$(sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "$MAIN" | head -1)"

if [ -z "$VERSION" ]; then
	echo "No Version found in the plugin header." >&2
	exit 1
fi

# A plugin that ships assets must carry a build that MATCHES src/: the zip has
# no src/, and a stale build/*.asset.php ships the previous version hash too, so
# the old bundle stays cached in every browser that already had it. Rebuild here
# rather than trust whatever is on disk -- composer dist skips every other check
# by design, and this is the one that decides what the shop runs.
if [ -f package.json ]; then
	if [ -d node_modules ]; then
		npm run build
	elif ! compgen -G 'build/*.asset.php' > /dev/null; then
		# A build/ that exists but holds no manifest used to package as a
		# success: the zip installs, Assets finds no manifest, and the plugin
		# enqueues nothing while the release reports fine.
		echo "build/ holds no *.asset.php. Run 'npm install && npm run build' before packaging." >&2
		exit 1
	else
		# Only reached without node_modules. Whatever is on disk ships, and
		# nothing here can tell it apart from what src/ would compile to.
		echo "WARNING: no node_modules, packaging the build/ already on disk unverified." >&2
	fi
fi

# No hidden file belongs in a plugin zip: .git, .github, .distignore,
# .phpunit.cache, .DS_Store and whatever gets added later. This is a rule rather
# than a list, so .distignore never has to be kept in step with .gitignore.
# Add an --include before it in the unlikely case a plugin has to ship one.
EXCLUDES=( --exclude '.*' --exclude '/dist' )

# The .distignore patterns are anchored to the plugin root. Unanchored, rsync
# matches at any depth: a bare "vendor" also ate includes/vendor/, which is
# where a plugin puts the update library the README recommends, and "src" ate
# includes/src/ -- silently, out of a zip that reported success and installed as
# a fatal require. A pattern that really has to match at any depth starts with a
# * and is passed through untouched.
while IFS= read -r pattern || [ -n "$pattern" ]; do
	pattern="${pattern%%$'\r'}"
	[ -z "$pattern" ] && continue
	case "$pattern" in \#*) continue ;; esac
	case "$pattern" in
		\**) EXCLUDES+=( --exclude "$pattern" ) ;;
		*) EXCLUDES+=( --exclude "/${pattern#/}" ) ;;
	esac
done < .distignore

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG" dist
rsync -a "${EXCLUDES[@]}" ./ "$STAGE/$SLUG/"

ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" )

printf '%s\n  %s files, %s\n' "dist/$SLUG-$VERSION.zip" \
	"$(unzip -l "$ZIP" | tail -1 | awk '{print $2}')" \
	"$(du -h "$ZIP" | cut -f1 | tr -d ' ')"
