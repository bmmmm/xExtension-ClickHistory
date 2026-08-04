# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each released version has a `v`-prefixed git tag, and CI checks on a tag push that
the tag and the version in `metadata.json` agree.

Entries for 0.3.0 and 0.3.1 were written afterwards, from the git history: this
file was added later than the releases it describes. Versions 0.1.0 and 0.2.0
existed in `metadata.json` during development but were never tagged or released.

## [Unreleased]

### Changed

- The SPDX licence id in `composer.json` and `package.json` is `AGPL-3.0-only`
  instead of the deprecated `AGPL-3.0`, and the README's install step points at
  the latest release rather than the repository — both matching the sibling
  Share via QR Code repo, where these were fixed earlier.

## [0.6.0] - 2026-08-04

### Added

- A **Feed figures** page, reached from the button in the history page's toolbar:
  one row per feed with how often something from it was opened and how those
  clicks were judged — good, dropped, still unrated — plus the share of the
  judged ones that turned out to be worth it. This is what the rating buttons
  were for; until now they were three clicks that led nowhere. A feed nobody has
  rated yet shows a dash rather than 0%, since "nothing judged" is not a ratio,
  and the unrated ones stay out of the denominator so that working through the
  backlog does not move a feed's number on its own.
- `tests/schema.php` runs the aggregation behind that page against the schema on
  all three backends, including the case of a feed that has moved category — the
  category in the table is a copy taken at click time, so such a feed is one row
  per combination rather than one row.

## [0.5.1] - 2026-08-04

### Changed

- The settings page now says where the history page lives and links to it
  directly, and the README states it more prominently. After the toolbar
  button's removal in 0.5.0, the header-menu entry was too easy to miss.

## [0.5.0] - 2026-08-04

### Removed

- The **"State of a newly opened article"** setting (`default_status`). A newly
  recorded click now always starts as *unrated*: any other starting value would
  skew the good/dropped ratio the ratings exist to measure, by counting articles
  nobody has judged. Rating an entry on the history page is unchanged.

## [0.4.0] - 2026-08-04

### Security

- The CSV export cannot carry a spreadsheet formula any more. A title, URL, feed
  name or category name starting with `=`, `+`, `-`, `@`, a tab or a carriage
  return is prefixed with a single quote, which Excel and LibreOffice read as
  "this cell is text". A feed controls all four of those values, so a headline of
  `=HYPERLINK(…)` used to run when the downloaded file was opened.
- The entry id is escaped on its way into the two hidden form fields on the
  history page, like every other value in the row.

### Removed

- The clock button the script inserted into FreshRSS' toolbar. It duplicated the
  **Click history** entry in the header menu, which needs no markup of core's,
  and it was the only reason the extension carried a `MutationObserver`, an
  inline SVG icon and two extra values in the JavaScript context. The page is
  reached from the header menu, as before.

### Added

- `tests/main-link.test.js` holds the three core-markup selectors against HTML
  copied out of the FreshRSS 1.29.0 views, including a feed putting core's own
  `go_website` class on a link in the article body. That coupling is the
  extension's most fragile and was previously only reasoned about.
- `tests/schema.php` runs against MySQL 8 and PostgreSQL 16 in CI, not only
  SQLite. Those two dialects of the schema shipped unexecuted until now.
- `.github/SECURITY.md`, and this changelog.
- `metadata.json` carries a `url`, which CI now requires alongside the other
  keys.
- A failed recording says so in the browser console. `fetch()` only rejects when
  the request never completed, so a 400, a 404, a 500 or a redirect to the login
  page used to look exactly like a recorded click.

### Changed

- `tests/schema.php` executes the extension's real SQL instead of a copy of it.
  The statements moved into `ClickHistorySchema`, which needs no FreshRSS
  context, so an edit to them is caught rather than silently diverging.
- The export is written a row at a time instead of being materialised twice, and
  a JSON export whose encoding fails now says so in the FreshRSS log instead of
  arriving empty.
- The stylesheet is only loaded on the history page, and the script only on the
  history page and the reading views. Both used to be appended to every page.
- The once-per-process table check is keyed by database prefix. The table is per
  user on MySQL and PostgreSQL, so one process serving two users could take the
  first user's table as proof that the second one's existed.
- The configuration form always sends an answer for **Record opened articles**,
  so it obeys the same "a missing field changes nothing" rule as the other two
  settings.
- CI checks that nothing was skipped and that something ran, rather than counting
  `test(` calls in the source — a guard that could go off while every test passed.

## [0.3.1] - 2026-08-03

### Fixed

- The two rating buttons no longer stack on top of each other on a wide window.

## [0.3.0] - 2026-08-03

### Added

- An opened article can be judged **Good**, **Dropped**, or left **Unrated**,
  with filter links across the top carrying a count each. A dropped entry is kept
  — it only leaves the default view — because the per-feed figures this exists
  for need it as a denominator.
- Grouping by category, JSON and CSV export of the whole history, and a
  navigation bar on the history page.
- The setting for the state a newly opened article starts in.
- Tests, CI, and the README.

### Fixed

- A confirmation wording the core can never show was dropped rather than left in
  place looking as if it worked.

[Unreleased]: https://github.com/bmmmm/xExtension-ClickHistory/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/bmmmm/xExtension-ClickHistory/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/bmmmm/xExtension-ClickHistory/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/bmmmm/xExtension-ClickHistory/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/bmmmm/xExtension-ClickHistory/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/bmmmm/xExtension-ClickHistory/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/bmmmm/xExtension-ClickHistory/releases/tag/v0.3.0
