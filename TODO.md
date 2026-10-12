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
