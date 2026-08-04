# Security policy

## Supported versions

The latest release is the only one that gets fixes. This is a single-file-scale
FreshRSS extension; upgrading means replacing the directory, so there is nothing
a backport would buy anyone.

It is written against **FreshRSS 1.29.0 or newer** and CI analyses it against
exactly that release. A problem that only appears on an older FreshRSS is out of
scope.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Write to **hi@brtsz.de** instead. Say "Click History" somewhere in the subject so
it does not get read as a question about something else.

What to expect:

* An acknowledgement within **7 days**. If you have not heard anything by then,
  assume the mail went astray and send it again.
* An assessment — whether it is a vulnerability, and how bad — within **30 days**.
* A fix released before the details are published. The release and the changelog
  entry come first, the advisory afterwards, so that upgrading is possible the
  moment the problem is public.

You will be credited in the changelog unless you would rather not be.

## What to put in the report

The extension sits between a feed, FreshRSS and a browser, and which of the three
matters is rarely obvious from a description alone. The more of this the report
carries, the less of it has to be guessed:

* **FreshRSS version** — from *About*, e.g. 1.29.1.
* **Database backend** — SQLite, MySQL/MariaDB, or PostgreSQL. Only SQLite is
  exercised end to end in a running installation; the schema statements for the
  other two are executed in CI but the extension has not been run on them.
* **Browser and version**, if a click, the export, or anything else on the page
  is involved.
* **The feed and the article that reproduce it** — a feed URL, or the raw item
  XML if the feed cannot be shared. A title, link or category that a feed chose
  is untrusted input here, so the exact bytes are usually the whole story.
* **What you saw and what you expected**, and whether it needs an authenticated
  session.

## What is already known and deliberate

Not vulnerabilities, and reported often enough to list:

* **Disabling the extension does not delete the history table.** FreshRSS calls
  an extension's uninstall step when it is merely *disabled*, so dropping the
  table there would let one stray click destroy the archive. The "delete the
  whole history" button is what removes the data.
* **The history outlives the article on purpose.** Headline, URL, feed and
  category are copied into the extension's own table at the moment of the click,
  so a FreshRSS purge does not take them with it. That is the feature.
* **A non-`http(s)` link is shown as plain text**, not as a link, and not hidden.
  A `javascript:` URL a feed smuggled in stays visible as part of the history but
  is never clickable.

## Scope

In scope: this repository's PHP, JavaScript, SQL and CI configuration.

Out of scope: FreshRSS itself (report to
[FreshRSS/FreshRSS](https://github.com/FreshRSS/FreshRSS/security)), the web
server, and anything that requires an attacker to already have the user's
FreshRSS session.
