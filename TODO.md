# TODO

- [ ] Verify that a history entry survives its article being purged. Best done
      with a throwaway feed — subscribe, click one of its articles, delete the
      feed — so no real articles are lost. This is the one property the whole
      design exists for (own table, no foreign key, everything denormalised at
      click time) and the only one never checked against a running instance.
- [ ] Exercise multi-page pagination against real data. The clamping (`page=99`,
      `page=abc`, `page=-5`) and the single-page case are verified, but the
      multi-page markup never rendered: it needs more entries than `page_size`,
      whose minimum is 10.
- [ ] Decide whether older entries without a category are worth backfilling.
      They cannot be recovered from the history itself; the feed id is still
      there, so a best-effort pass could resolve the category for feeds that
      still exist — at the cost of writing data that is not the state as of the
      click, which is what every other column in the table promises.
