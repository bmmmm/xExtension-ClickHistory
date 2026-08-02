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
  it was last opened, one page at a time. Reachable from the clock icon in the
  toolbar and from the header menu.
* Groups by category on request, or stays in plain chronological order.
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

It is an archive, not a to-read list: there is no done state and no workflow.
Entries stay until you delete them, one at a time or all at once.

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

Upgrading from 0.1.0 adds two columns for the category on first use; existing
entries keep their data and show up under *No category*, since the category was
not recorded when they were made.

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
* **Requests are protected against CSRF by FreshRSS itself**: it rejects any
  POST without a valid token before a controller is reached, and extensions are
  not on its exemption list.

## One dependency on core's markup

The clock button in the toolbar is inserted by the extension's own script,
because no hook covers that row — `NavEntries` renders by the paging arrows at
the bottom of the stream, `MenuOtherEntry` only inside the header dropdown. If a
future FreshRSS release renames `.nav_menu` or `.group`, that button quietly
stops appearing. Nothing else breaks: the menu entry needs no markup of core's,
so the page stays reachable through it.

## Development

```sh
# JavaScript tests (click detection and the once-per-load guard)
node --test tests/*.test.js

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

## Translations

English and German are included. Adding a language only means adding an
`i18n/<code>/ext.php` file; CI fails if one of them is missing a key the code
uses.

## Licence

[AGPL-3.0](LICENSE), matching FreshRSS itself.

## Support

If you find this useful, you can [buy me a coffee](https://ko-fi.com/bmabma).
