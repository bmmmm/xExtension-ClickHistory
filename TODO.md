# TODO

## Ideas

- [ ] Report per-feed figures from the ratings: opened / good / dropped and the
      ratio, per feed and per category. This is what makes rating worth doing at
      all — without it the buttons are three clicks that lead nowhere, and they
      will stop being pressed. The data needs nothing new: `feed_name`,
      `id_feed` and the category are already denormalised into every row, so it
      is one GROUP BY and a table. Needs a few weeks of ratings first to be
      worth looking at.
- [ ] Decide whether older entries without a category are worth backfilling.
      They cannot be recovered from the history itself; the feed id is still
      there, so a best-effort pass could resolve the category for feeds that
      still exist — at the cost of writing data that is not the state as of the
      click, which is what every other column in the table promises.
