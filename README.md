# Click History

A [FreshRSS](https://freshrss.org) extension that keeps a list of the articles
you actually opened — with the headline, the feed and when you opened them — on
a page of its own.

## Why

FreshRSS knows which articles are *read*, but "read" only means an article
scrolled past in the stream. It has no record of which ones you actually opened.
So the question "what was that article I opened last week?" has nowhere to go:
the browser history has the URL, but not the feed it came from and usually not
the headline either.

This extension records that one gesture and nothing else.

## What it does

* Records a click on an article's own link — the headline in the list, the
  headline of an expanded article, and the link icon in the footer — plus
  middle-clicks and the core's <kbd>go to website</kbd> shortcut.
* Shows the collection under **Click history**: headline, feed, category, when
  it was last opened, one page at a time. Reachable from the **Click history**
  entry in the header menu, next to *Logs* and *About*.
* Groups by category on request, or stays in plain chronological order.
* Lets you say afterwards whether an article was worth it: **Good**, **Dropped**,
  or left **Unrated**. Filter links across the top switch between those states
  and carry a count each, so the unrated pile is visible without going looking
  for it. Pressing the state a row is already in takes it back to unrated.
* Keeps a dropped entry rather than removing it: it only leaves the default view.
  That is on purpose — "8 of 87 from this feed were worth reading" needs the 87.
  Removing an entry is what the delete button is for.
* Downloads the whole history as **JSON** or **CSV** — everything, not just the
  page on screen. Timestamps are written twice, as a Unix value for whatever
  reads the file and as ISO-8601 for whoever opens it.
* Keeps one entry per article. Opening the same article again moves its
  timestamp forward instead of adding a second row; the first time is kept and
  shown as a tooltip on the date.
* **Only the article's own link counts.** Links inside the article text are
  ignored — that is structural, not a heuristic: the extension listens on the
  specific header and footer elements that carry the article link, so a link in
  the body cannot match one of them.
* Entries outlive the article. FreshRSS purges old articles on a schedule; the
  headline, URL, feed name and category are copied into the extension's own
  table at the moment of the click, so the history survives that and stays
  clickable — and stays grouped correctly even after the feed or the whole
  category has been deleted.

It is an archive first: the complete list is what you see by default, and the
ratings sit on top of it rather than gating it. Entries stay until you delete
them, one at a time or all at once — a judgement never removes anything.

## What it cannot record

**Opening a link through the context menu** ("Open link in new tab") leaves no
trace in JavaScript — the browser does it without telling the page. There is no
workaround; it is a browser boundary, not a missing feature. If your history has
gaps and you use the context menu, that is why.

Everything else is covered: left click, middle click, and the keyboard shortcut.

## Installation

Requires **FreshRSS 1.29.0 or newer**. CI analyses the extension against exactly
that release, so this is a checked property rather than a claim.

1. Download this repository and place the `xExtension-ClickHistory` directory
   into the `extensions/` directory of your FreshRSS installation.
2. Enable **Click History** under *Configuration → Extensions*.

Enabling it creates one table (`click_history`, with your installation's usual
prefix). Because this is a *user* extension, each user gets their own — nobody
sees anyone else's history.

Upgrading adds the columns a newer version needs on first use, one probe per
version, and existing entries keep their data: those from before 0.2.0 show up
under *No category*, and everything recorded before 0.3.0 counts as *Unrated* —
in both cases because the information did not exist when the click happened. The
upgrade statements are exercised against SQLite in CI (`tests/schema.php`), since
this path runs exactly once per installation and a broken one would be noticed
only by the data it lost.

There is no machine API. FreshRSS' extension API endpoint (`/api/misc.php`)
checks `systemConf()->extensions_enabled` and therefore cannot see a user
extension at all — offering one would mean making this a *system* extension,
which would move the on/off switch from each user to the administrator. The
export is a plain authenticated download instead.

## Settings

Under *Configuration → Extensions → Click History*:

| Setting | Default | Notes |
|---|---|---|
| Record opened articles | on | While off, nothing is recorded and the browser sends no requests. The history that already exists is kept. |
| Entries per page | 50 | Between 10 and 500. |
| State of a newly opened article | Unrated | What a click leaves behind until you judge it. *Dropped* makes the history a list you promote entries onto instead of one you weed; it still records every click, so the counts stay honest. To record nothing at all, use the first setting — not this one. |

There is deliberately no retention setting: the history is kept until you delete
it. Deleting is manual, per entry or all at once.

**Disabling the extension does not delete anything.** FreshRSS calls an
extension's uninstall step when it is merely *disabled*, so dropping the table
there would mean one accidental click destroys the whole archive. Use the
"delete the whole history" button if that is what you want.

## Databases

| Backend | Status |
|---|---|
| SQLite | Verified end to end |
| MySQL / MariaDB | Written against the core schema, not executed |
| PostgreSQL | Written against the core schema, not executed |

The MySQL and PostgreSQL statements follow FreshRSS' own schema files for those
dialects, but they have not been run — the development installation is SQLite.
If you hit a problem on one of them, that is where to look first.

## Security

A feed decides what an article's link is, so that link is untrusted input.

* **The browser only ever sends an entry id.** The URL, headline and feed name
  are looked up on the server from that id, so a hand-made request cannot put
  arbitrary text into the table that the history page later renders. There is
  deliberately no fallback to a URL supplied by the client.
* **The lookup runs in the logged-in user's own database context**, so an entry
  id belonging to someone else is simply not found.
* **Only `http:` and `https:` entries become links** on the history page.
  Anything else — a `javascript:` link a feed managed to smuggle in — is shown
  as plain text instead.
* **The CSV export cannot carry a spreadsheet formula.** Excel and LibreOffice
  evaluate a cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage
  return, so a headline of `=HYPERLINK("http://…"&A1)` would run the moment the
  downloaded file is opened. The four columns a feed controls — title, URL, feed
  name, category name — get a leading single quote in that case, which is the
  escape both applications understand: the cell stays text and the quote is not
  part of it. Escaping only those four is deliberate; the remaining columns are
  the extension's own id, status and timestamps.
* **Requests are protected against CSRF by FreshRSS itself**: it rejects any
  POST without a valid token before a controller is reached, and extensions are
  not on its exemption list.

## One dependency on core's markup

Detecting a click means recognising the article's own link, and there is no hook
for that: the script matches the three CSS selectors core uses for it
(`a.go_website`, the link icon in the footer row, and the headline of a collapsed
list row). If a future FreshRSS release renames one of them, clicks through that
particular element stop being recorded — silently, since nothing else about the
extension depends on it.

The page itself needs no markup of core's: it is reached through the
`MenuOtherEntry` hook, which is a supported extension point.

## Development

```sh
# JavaScript tests (click detection and the once-per-load guard)
node --test tests/*.test.js

# Schema upgrade and upsert, against real SQLite
php tests/schema.php

# JavaScript style (ESLint, aligned with FreshRSS core's own eslint.config.js)
pnpm install
pnpm run eslint

# PHP style (PHP_CodeSniffer, FreshRSS' own ruleset)
composer install
vendor/bin/phpcs .

# PHP static analysis (PHPStan). Needs FreshRSS core checked out as a sibling
# directory to resolve the Minz_Extension classes this extension extends —
# see phpstan.neon for why, and .github/workflows/ci.yml for how CI does it.
git clone --depth 1 --branch 1.29.0 https://github.com/FreshRSS/FreshRSS .freshrss-core
vendor/bin/phpstan analyse
```

Without PHP installed locally, the same checks run in a container on the exact
version CI uses, which is also the floor the extension claims to support:

```sh
docker run --rm -v "$PWD:/app" -w /app -e COMPOSER_HOME=/tmp/composer \
  composer:2 install --prefer-dist --no-progress --no-interaction

docker run --rm -v "$PWD:/app" -w /app php:8.1-cli sh -c '
  find . \( -path ./vendor -o -path ./node_modules -o -path ./.freshrss-core \) -prune -o \
    \( -name "*.php" -o -name "*.phtml" \) -print0 | xargs -0 -n1 php -l
  vendor/bin/phpcs .
  php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress'
```

## Translations

English and German are included. Adding a language only means adding an
`i18n/<code>/ext.php` file; CI fails if one of them is missing a key the code
uses.

## Licence

[AGPL-3.0](LICENSE), matching FreshRSS itself.

## Support

If you find this useful, you can [buy me a coffee](https://ko-fi.com/bmabma).
