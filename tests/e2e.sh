#!/bin/sh
# The extension inside a real FreshRSS: core's install CLI on SQLite, the
# extension linked into it, PHP's built-in server in front, and curl driving the
# controller the way a browser does — with the session cookie and the CSRF token
# core insists on. Everything tests/*.php leave out runs here: dispatching,
# auth, the redirects and notifications after a change, the JSON endpoint's
# status codes, and what the page loads for a visitor who is not logged in.
#
#   sh tests/e2e.sh
#   PHP='php -n' sh tests/e2e.sh     # a php.ini that gets in the way locally
#
# Needs php, curl and the .freshrss-core checkout. Core is copied into a
# temporary directory first: linking the extension into the checkout itself
# would put this repository under the path PHPStan scans as core.
# shellcheck disable=SC2016 # the single-quoted PHP is PHP's to expand
set -u
root=$(cd "$(dirname "$0")/.." && pwd)
php=${PHP:-php}
port=${CLICKHISTORY_E2E_PORT:-18765}
base="http://127.0.0.1:$port/i/"
user=tester

[ -f "$root/.freshrss-core/p/i/index.php" ] || {
	echo 'FreshRSS core is missing: git clone --depth 1 --branch 1.29.0 https://github.com/FreshRSS/FreshRSS .freshrss-core'
	exit 1
}

work=$(mktemp -d)
server=
cleanup() {
	[ -z "$server" ] || kill "$server" 2>/dev/null
	chmod -R u+w "$work" 2>/dev/null
	rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 1' INT TERM

core="$work/core"
cp -R "$root/.freshrss-core" "$core"
rm -rf "$core/.git"
ln -s "$root" "$core/extensions/xExtension-ClickHistory"
db="$core/data/users/$user/db.sqlite"

# http_auth rather than a password form: core takes the user from a Remote-User
# header when the request comes from a trusted source (127.0.0.0/8 by default),
# so a request without that header is a visitor who is not logged in — which is
# what scenario 8 needs, with anonymous reading allowed.
(
	cd "$core" &&
	$php cli/do-install.php --default-user "$user" --auth-type http_auth --allow-anonymous \
		--db-type sqlite --environment development --base-url "http://127.0.0.1:$port/" &&
	$php cli/create-user.php --user "$user" --no-default-feeds
) > "$work/install.log" 2>&1 || { cat "$work/install.log"; exit 1; }

# The extension switched on for the user, and two entries to click, one of them
# with what core stores for "&" in a title, a link, a feed and a category name:
# the HTML entity.
$php -r '
	[, $config, $db] = $argv;
	$conf = require $config;
	$conf["extensions_enabled"] = ["Click History" => true];
	file_put_contents($config, "<?php\nreturn " . var_export($conf, true) . ";\n");
	$pdo = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$pdo->exec("INSERT INTO category (id, name) VALUES (2, \"News &amp; Views\")");
	$pdo->exec("INSERT INTO feed (id, url, category, name) VALUES (1, \"https://example.org/feed\", 2, \"Feed &amp; Co\")");
	$entry = $pdo->prepare("INSERT INTO entry (id, guid, title, link, date, id_feed) VALUES (?, ?, ?, ?, 0, 1)");
	$entry->execute(["1760000000000001", "a", "Tom &amp; Jerry", "https://example.org/a?x=1&amp;y=2"]);
	$entry->execute(["1760000000000002", "b", "Second", "https://example.org/b"]);
' "$core/data/users/$user/config.php" "$db" || exit 1

# Something already answering on the port would be taken for this server.
if curl -s -o /dev/null "http://127.0.0.1:$port/"; then
	echo "port $port is already in use: set CLICKHISTORY_E2E_PORT"
	exit 1
fi
$php -S "127.0.0.1:$port" -t "$core/p" > "$work/server.log" 2>&1 &
server=$!
i=0
until curl -s -o /dev/null "$base"; do
	i=$((i + 1))
	[ "$i" -lt 50 ] || { echo 'the server did not come up'; cat "$work/server.log"; exit 1; }
	sleep 0.1
done

failures=0
# $2 is one or more results of contains/lacks run together: all of them "ok".
check() {
	if [ -n "$2" ] && [ -z "$(printf '%s' "$2" | sed 's/ok//g')" ]; then
		echo "ok   $1"
	else
		echo "FAIL $1"
		failures=$((failures + 1))
	fi
}
# The session travels as a fixed cookie rather than through a cookie jar: core
# 1.29.0 deletes a "legacy" session cookie on the script's directory with every
# request (lib/Minz/Session.php, init()), and curl files the session cookie under
# exactly that path — /i/ — where a browser, following RFC 6265, uses /i. Kept
# in a jar, the session would be gone after the first answer.
sid=$(curl -s -o /dev/null -w '%header{set-cookie}' -H "Remote-User: $user" "$base" | sed -n 's/^FreshRSS=\([^;]*\).*/\1/p')
[ -n "$sid" ] || { echo 'no session cookie from core'; exit 1; }
# As the logged-in user, in that session: $1 = query string, rest = curl args.
get() {
	q=$1
	shift
	curl -s -b "FreshRSS=$sid" -H "Remote-User: $user" "$@" "$base?$q"
}
post() {
	q=$1
	shift
	get "$q" --data-urlencode "_csrf=$csrf" "$@"
}
# Status code and Location of a POST, without following it.
redirect() {
	post "$@" -o /dev/null -w '%{http_code} %{redirect_url}'
}
# The hidden fields of the row form for action $2 and entry $3 on page $1, as a
# urlencoded body without the token, which post() adds.
form() {
	printf '%s' "$1" | $php -r '
		[, $action, $id] = $argv;
		$doc = new DOMDocument();
		@$doc->loadHTML(stream_get_contents(STDIN));
		foreach ($doc->getElementsByTagName("form") as $form) {
			$fields = [];
			foreach ($form->getElementsByTagName("input") as $input) {
				$fields[$input->getAttribute("name")] = $input->getAttribute("value");
			}
			if (str_ends_with($form->getAttribute("action"), "a=" . $action) && ($fields["id"] ?? null) === $id) {
				unset($fields["_csrf"]);
				echo http_build_query($fields);
				exit;
			}
		}' "$2" "$3"
}
contains() {
	case $1 in *"$2"*) echo ok ;; *) echo no ;; esac
}
lacks() {
	case $1 in *"$2"*) echo no ;; *) echo ok ;; esac
}

id1=1760000000000001
id2=1760000000000002

# 1 — the page, through core's dispatcher and auth.
page=$(get 'c=clickhistory&a=index')
check 'the history page answers' "$(contains "$page" '<h1>Click history</h1>')"
csrf=$(printf '%s' "$page" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -n 1)
[ -n "$csrf" ] || { echo 'no CSRF token on the page'; exit 1; }

# 2 — the endpoint the click listener posts to.
check 'a click is recorded' "$(contains "$(post 'c=clickhistory&a=record' --data "id=$id1" -w ' %{http_code}')" '{"ok":true} 200')"
post 'c=clickhistory&a=record' --data "id=$id2" -o /dev/null
check 'a non-numeric id is a 400' "$(contains "$(post 'c=clickhistory&a=record' --data 'id=abc' -w ' %{http_code}')" '"bad_id"} 400')"
check 'an unknown id is a 404' "$(contains "$(post 'c=clickhistory&a=record' --data 'id=42' -w ' %{http_code}')" '"unknown_entry"} 404')"
check 'a GET is a 405' "$(contains "$(get 'c=clickhistory&a=record' -w ' %{http_code}')" '"method_not_allowed"} 405')"
page=$(get 'c=clickhistory&a=index')
# Core hands out title, link and names HTML-encoded; stored decoded, they are
# encoded exactly once on the way out.
check 'a recorded title is encoded once' "$(contains "$page" '>Tom &amp; Jerry</a>')"
check 'a recorded link is encoded once' "$(contains "$page" 'href="https://example.org/a?x=1&amp;y=2"')"
check 'the feed and the category are encoded once' "$(contains "$page" '>Feed &amp; Co</td>')$(contains "$page" '>News &amp; Views</td>')"
check 'nothing is encoded twice' "$(lacks "$page" '&amp;amp;')"

# 3 — a rating keeps the page's filter and grouping, and drops the page number
# because a filter is active.
check 'a rating comes back to the filtered, grouped list' "$(contains "$(redirect 'c=clickhistory&a=rate' \
	--data "id=$id1&rate=good&status=unrated&group=category&page=2")" '302 ')"
loc=$(redirect 'c=clickhistory&a=rate' --data "id=$id2&rate=good&status=unrated&group=category&page=2")
check 'the redirect after a rating carries the filter and the grouping, not the page' \
	"$(contains "$loc" 'status=unrated')$(contains "$loc" 'group=category')$(lacks "$loc" 'page=')"

# 4 — a deletion keeps both too, sent the way the page's own form sends it.
page=$(get 'c=clickhistory&a=index&status=good&group=category')
fields=$(form "$page" delete "$id2")
check 'the delete form carries the filter and the grouping' \
	"$(contains "$fields" 'status=good')$(contains "$fields" 'group=category')"
loc=$(redirect 'c=clickhistory&a=delete' --data "$fields")
check 'the redirect after a deletion carries the filter and the grouping' \
	"$(contains "$loc" 'status=good')$(contains "$loc" 'group=category')"

# 7 — the downloads, while there is something in them.
csv=$(get 'c=clickhistory&a=export&format=csv' -D "$work/headers")
check 'the CSV download is text/csv' "$(contains "$(cat "$work/headers")" 'Content-Type: text/csv')"
check 'the CSV starts with a BOM and the header row' "$(contains "$(printf '%s' "$csv" | head -c 11 | od -An -c | tr -d ' \n')" '357273277id_entry')"
json=$(get 'c=clickhistory&a=export&format=json')
check 'the JSON download parses, with the one row left' "$(printf '%s' "$json" | $php -r '
	$data = json_decode(stream_get_contents(STDIN), true);
	echo is_array($data) && ($data["count"] ?? null) === 1 && ($data["entries"][0]["title"] ?? null) === "Tom & Jerry" ? "ok" : "no";')"

# 5 — a database that refuses to write: said out loud, not swallowed.
chmod 444 "$db"
page=$(post 'c=clickhistory&a=rate' --data "id=$id1&rate=dropped" -L)
check 'a rating the database refused says so' "$(contains "$page" 'The change could not be saved.')"
chmod 644 "$db"
# Reading still works on a read-only file, so the export needs a table that is
# gone and cannot be created again. On SQLite core's prefix is empty.
sql() {
	$php -r '(new PDO("sqlite:" . $argv[1]))->exec($argv[2]) !== false || exit(1);' "$db" "$1" || exit 1
}
sql 'ALTER TABLE click_history RENAME TO click_history_away'
chmod 444 "$db"
page=$(get 'c=clickhistory&a=export&format=json' -L -D "$work/headers")
check 'an export the database refused says so instead of downloading' \
	"$(contains "$page" 'nothing was downloaded')$(lacks "$(cat "$work/headers")" 'attachment')"
chmod 644 "$db"
sql 'ALTER TABLE click_history_away RENAME TO click_history'

# 6 — the whole history.
page=$(post 'c=clickhistory&a=clear' -L)
check 'clearing the history says it did' "$(contains "$page" 'The whole history has been deleted.')"

# 8 — the click listener is for the user whose clicks it records. With anonymous
# reading allowed, a visitor without a login sees the reading view too, and must
# not get a script that posts to an endpoint they cannot use.
# The URL as core's ext.php serves it, slashes percent-encoded.
script='f=xExtension-ClickHistory%2Fstatic%2Fscript.js'
anon=$(curl -s "$base?c=index")
check 'a visitor who is not logged in gets the reading view' "$(contains "$anon" 'id="stream"')"
check '... without the click listener' "$(lacks "$anon" "$script")"
check 'the logged-in user gets the click listener' "$(contains "$(get 'c=index')" "$script")"

# 9 — what PHP said about this repository's files while all of the above ran:
# the built-in server's own output, and core's logs. The paths come either way,
# through the link in core's extension directory or resolved to this checkout.
noise=$(cat "$work/server.log" "$core"/data/users/*/log*.txt 2>/dev/null |
	grep -E '(Deprecated|Warning|Notice|Fatal error|Parse error)' |
	grep -F -e "$root/" -e 'extensions/xExtension-ClickHistory/')
check 'PHP reported nothing about this extension' "$(lacks "$noise" 'xExtension-ClickHistory')"
[ -z "$noise" ] || printf '%s\n' "$noise"

echo
[ "$failures" -eq 0 ] && echo 'all checks passed' || echo "$failures check(s) failed"
[ "$failures" -eq 0 ]
