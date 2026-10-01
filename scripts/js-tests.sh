#!/bin/sh
# The JS tests, as CI and deps:check run them — both through `pnpm test`, so the
# guard below holds for the nightly autobump too, not only for CI.
#
# Pin the reporter: the default differs between Node versions and the guard
# parses its output. The whole directory, not one file by name, so a test added
# tomorrow cannot be silently skipped. The glob rather than `tests/`: newer Node
# reads a bare directory as a module path and reports zero tests.
set -u
log=$(mktemp)
trap 'rm -f "$log"' EXIT
status=0
node --test --test-reporter=tap tests/*.test.js > "$log" 2>&1 || status=$?
cat "$log"
[ "$status" -eq 0 ] || exit "$status"

# `node --test` exits 0 on a run where everything was skipped, and on a run that
# found no files at all, so the counts are read back.
#
# Deliberately not compared against `grep -c '^test('`: that number is a property
# of how the tests are written, not of what ran. A `test()` call inside a loop or
# a helper, or one wrapped in a describe(), makes the two disagree while
# everything is passing — a guard that goes off when the tests are fine gets
# deleted, and then nothing checks the real failure mode. "Nothing was skipped
# and something ran" is that failure mode.
passed=$(sed -n 's/^# pass \([0-9]*\)$/\1/p' "$log")
skipped=$(sed -n 's/^# skipped \([0-9]*\)$/\1/p' "$log")
echo "passed=${passed:-0} skipped=${skipped:-0}"
[ "${skipped:-0}" -eq 0 ] || { echo 'a test was skipped'; exit 1; }
[ "${passed:-0}" -gt 0 ] || { echo 'no test ran at all'; exit 1; }
