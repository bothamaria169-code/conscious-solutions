#!/bin/bash
# Run by .cpanel.yml. Builds a clean copy from the committed files (not the
# working folder, which could be tampered with), then lets the guard publish it.
set -eu
DEPLOYPATH="${DEPLOYPATH:-/home/conscioussol/public_html/}"
GUARD="${GUARD:-/home/conscioussol/cs_guard}"
PHP="${PHP:-/usr/local/bin/php}"
export PATH="$PATH:/usr/local/cpanel/3rdparty/bin:/usr/local/cpanel/3rdparty/lib/path-bin"
cd "$(dirname "$0")"

mkdir -p "$DEPLOYPATH" "$GUARD/allow" "$GUARD/quarantine"
# Apache only needs to see UNLOCKED and allow/; everything else stays private.
chmod 711 "$GUARD" "$GUARD/allow"
chmod 700 "$GUARD/quarantine"

if [ -d "$GUARD/clean.new" ]; then chmod -R u+w "$GUARD/clean.new"; rm -rf "$GUARD/clean.new"; fi
mkdir "$GUARD/clean.new"
chmod 700 "$GUARD/clean.new"
git archive HEAD assets config includes pages signin.php index.php logout.php .htaccess > "$GUARD/clean.tar"
(cd "$GUARD/clean.new" && tar -xf -) < "$GUARD/clean.tar"
rm -f "$GUARD/clean.tar"
test -s "$GUARD/clean.new/signin.php"
test -s "$GUARD/clean.new/.htaccess"
if [ -d "$GUARD/clean" ]; then chmod -R u+w "$GUARD/clean"; rm -rf "$GUARD/clean"; fi
mv "$GUARD/clean.new" "$GUARD/clean"

rm -f "$GUARD/guard.php"
git show HEAD:security/guard.php > "$GUARD/guard.php"
test -s "$GUARD/guard.php"
chmod 500 "$GUARD/guard.php"
printf '%s' "$DEPLOYPATH" > "$GUARD/webroot"

"$PHP" -n "$GUARD/guard.php" --deploy
