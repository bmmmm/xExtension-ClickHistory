# TODO

## Decided

- [x] **No category backfill** (2026-08-18). A best-effort pass over
      still-existing feed ids was possible, but it would write data that is
      not the state as of the click — the one guarantee every other column in
      the table makes. Older entries keep their empty category.
- [x] **Schema check by probe, not by a version in the user config**
      (2026-10-13). A stored schema version would skip the DDL just as well,
      but it is a second record of the table's state that a database restored
      from a backup silently contradicts — and then nothing repairs it. One
      probe on the newest column costs the same single statement and cannot
      disagree with the table it looks at.
- [x] **MariaDB in CI: the current LTS only** (2026-10-13, user's call). 12.3.3
      rather than the oldest supported LTS — what a new installation gets —
      and one leg rather than both ends.

## Open (only on a symptom or on request)

From the review rounds of 2026-10-12/13; each one commit with a gate that can fail.

- **MySQL collation of `status`**: only if a symptom shows.

From the review of 2026-10-13 (copy link and B2–B11), all P2:

- **Copy script's browser wiring untested**: `listen(document, () =>
  navigator.clipboard, window.setTimeout…)` in `static/history.js` runs in no
  test; a typo there breaks every copy with the gates green (confirmed working
  live on 2026-10-13).
- **Index missing on a table from 0.7.2/0.7.3**: those versions ran `CREATE
  INDEX` only with the table and outside a transaction; if it failed, nothing adds
  the index later. No such table is known.
- **MariaDB older than 12.3 is not in CI** (10.11 on Debian 12, 10.6 on
  Ubuntu 22.04): SQL that needs a newer release would pass CI and fail there.
  The current SQL ran green on 10.11.19 (2026-10-13).
