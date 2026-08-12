# CLAUDE.md

Working agreement for anyone — human or agent — touching this repository.
Tapehouse is a demo built to match a specific job description, and most of
the rules below exist to keep that match intact rather than to express a
personal preference.

## The stack is not negotiable

Laravel, jQuery, Webpack, PostgreSQL and Redis are here because the Twelve
Data job description names them, not because they are this author's daily
tools (see `README.md` §2 and §5 for the honest version of that statement).
That means:

- **No Vite, no Tailwind, no Laravel Mix — ever.** If a change seems to need
  one of these, the change is wrong, not the constraint.
- **jQuery stays pinned to `3.7.x`.** A loose `^3` or `~3` range in
  `package.json` has silently resolved to 4.x before during this project's
  own history — that is precisely the class of regression
  `.github/workflows/ci.yml`'s "Assert stack constraints" step exists to
  catch. Do not loosen the version range to "fix" a dependency conflict.
- Do not introduce a second build tool, a second CSS framework, or a second
  frontend framework "just for one page." The whole point of this codebase
  is that it is boring and matches the JD.

## PHP conventions

- **`declare(strict_types=1);` in every PHP file, including tests.** No
  exceptions. Pint's `declare_strict_types` rule enforces this; do not
  disable it.
- **Backed enums for every status field.** `TickSource`, `FeedEventLevel`,
  `DriverState`, `AssetType`, `AlertCondition`, `AlertMetric` are the
  existing examples in `app/Enums/`. A new status column is a new backed
  enum, not a raw string or an integer with a comment explaining what each
  value means.
- **No facades inside `app/Services/**`.** Services take their dependencies
  through the constructor (see `QuoteBroadcaster`, `DriverManager`,
  `CreditBudget`) so they stay testable without booting the full
  application container and so a service's actual dependencies are visible
  in its signature. Facades are acceptable in controllers, console commands,
  and providers — not in `app/Services`.
- **Controllers delegate; they never contain logic.** A controller method
  should read as: pull input, call a service, return a response/resource.
  If a controller method has a conditional that isn't about HTTP concerns
  (status codes, request shape), that logic belongs in a service.
- **The ingest path never blocks on Postgres or alert evaluation.** `tape:ingest`
  writes to the Redis last-price hash on the hot path and only *appends* to
  the buffered tick writer for Postgres (flushed on a timer/size threshold,
  never synchronously per tick). Alert evaluation is dispatched to the queue
  (`EvaluateAlerts`), never run inline during ingest. If a change makes
  ingest wait on either of those, it reintroduces the exact stall this
  architecture was built to avoid.
- **Money is a string end to end, never a float.** Prices, changes, and
  thresholds move through `Quote` DTOs, Eloquent attributes, and JSON
  payloads as strings (see `app/Services/Upstream/DTO/Quote.php` and the
  `ticks`/`alert_rules` schema). Do not cast a price column to `float`,
  `decimal:n`, or do float arithmetic on a price anywhere in the request or
  ingest path — precision loss on a money value is not an acceptable
  tradeoff here.

## Before any commit

1. `npm run build` — must run before the test suite. `Manifest::asset()`
   throws by design when `public/build/manifest.json` is missing (it's
   gitignored), so skipping this step makes several feature tests 500 for a
   reason that has nothing to do with the change you made.
2. `vendor/bin/pint` (or `pint --test` to check without rewriting).
3. `vendor/bin/phpstan analyse --memory-limit=512M` — **Larastan level 6**.
   No baseline file, no `ignoreErrors`, no PHPStan stub files. If the
   analyser is wrong about something, fix the code so it's obviously
   correct to the analyser too, or ask before adding a suppression.
4. `vendor/bin/pest` (or `php artisan test`).

`.github/workflows/ci.yml` runs the same four gates, in this order, on
every push and pull request — matching it locally before pushing saves a
round trip.

## Where to look first

- `docs/Tapehouse_PRD.md` — product intent, the seven performance decisions
  (§7), and the honesty constraints (§8) that also appear in `README.md`.
- `README.md` — architecture, running locally, and known limitations. Read
  "Known limitations" before assuming a symptom is a bug: the watchlist-at-boot
  behavior and the queue-worker requirement are both by design, not gaps.
