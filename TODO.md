# TODO

## Ideas

- [ ] Decide whether older entries without a category are worth backfilling.
      They cannot be recovered from the history itself; the feed id is still
      there, so a best-effort pass could resolve the category for feeds that
      still exist — at the cost of writing data that is not the state as of the
      click, which is what every other column in the table promises.
