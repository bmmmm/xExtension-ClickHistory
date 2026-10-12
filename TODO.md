# TODO

## Decided

- [x] **No category backfill** (2026-08-18). A best-effort pass over
      still-existing feed ids was possible, but it would write data that is
      not the state as of the click — the one guarantee every other column in
      the table makes. Older entries keep their empty category.

## Open (only on a symptom or on request)

From the review rounds of 2026-10-12/13; each one commit with a gate that can fail.

- **Schema statements per request** (`Dao/ClickHistoryDAO.php` `ensureTableExists()`):
  CREATE TABLE/INDEX IF NOT EXISTS plus two probes before every DAO action.
  Schema version in the user config, DDL only when behind, probe as fallback.
- **ALTER race on upgrade** (`ensureColumns()`): probe again after a failed ALTER.
- **PostgreSQL index name** (`ClickHistorySchema.php`) can exceed 63 characters
  with long user names; a shorter suffix needs a schema upgrade.
- **tableChecked key**: on SQLite `prefix()` is always `''`, so "per user" does
  not hold there; key on `Minz_User::name()` plus prefix.
- **Upsert moving backwards**: `clicked_at = MAX(...)` / `GREATEST(...)`.
- **Bad input reads as success** (`rateAction`, `deleteAction`): non-digit id or
  unknown rate redirects silently; a 20+ digit id overflows BIGINT.
- **Config saved twice** (`extension.php` `handleConfigureAction`), return value
  ignored; a missing `track_clicks` falls back to false, not the stored value.
- **MySQL collation of `status`**: only if a symptom shows.
- **Frontend**: `style.css` `min-width: 840px` vs core's breakpoint (841px);
  four `<nav>` without `aria-label`; README: context menu, long press and drag
  open links without a click event and are not recorded.
- **CI**: `mariadb:10.6` and `postgres:16` float within their series (MySQL is
  pinned), and MariaDB 10.6 is past its end of life — move to a supported LTS.
- **tests/e2e.sh** traps only INT/TERM; a HUP or kill leaves `php -S` running
  and the next run reports the port as in use.
