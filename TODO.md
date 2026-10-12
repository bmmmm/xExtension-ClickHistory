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

## Open (only on a symptom or on request)

From the review rounds of 2026-10-12/13; each one commit with a gate that can fail.

- **MySQL collation of `status`**: only if a symptom shows.

From the review of 2026-10-13 (copy link and B2–B11), all P2:

- **Copy script's browser wiring untested**: `listen(document, () =>
  navigator.clipboard, window.setTimeout…)` in `static/history.js` runs in no
  test; a typo there breaks every copy with the gates green (confirmed working
  live on 2026-10-13).
- **Missing index is no longer repaired**: the index is only created with the
  table, so a `CREATE INDEX` that failed after `CREATE TABLE` stays missing.
- **Half-upgraded table from ≤ 0.7.1**: the old code could add `status` while
  `id_category` had failed; the new fast path takes that table as current and
  every click fails. Not a regression, and no such table is known.
- **Concurrent first `CREATE` on PostgreSQL** can fail the slower request once
  (unique_violation); only the ALTER path re-probes.
- **MariaDB 10.6 left CI** while Ubuntu 22.04 still ships it; SQL needing
  10.7+ would now pass CI.
