# TODO

## Decided

- [x] **No category backfill** (2026-08-18). A best-effort pass over
      still-existing feed ids was possible, but it would write data that is
      not the state as of the click — the one guarantee every other column in
      the table makes. Older entries keep their empty category.
