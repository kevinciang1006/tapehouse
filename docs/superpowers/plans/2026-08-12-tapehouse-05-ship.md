# Tapehouse Plan 5 — Deployment, CI and Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Tapehouse runnable by someone who is not me — a development stack that comes up with one command, a production image that runs all five processes correctly, CI that would have caught every class of defect this build hit, and documentation that states what is not finished.

**Architecture:** One production container under supervisord running php-fpm, nginx, Reverb, a queue worker and the ingest loop. nginx serves `public/` and proxies the WebSocket upgrade to Reverb. Development uses Compose with separate services so logs are readable.

**Tech Stack:** Docker + Compose, PHP 8.4-fpm-alpine, Node 22 build stage, nginx, supervisord, PostgreSQL 16, Redis 7, GitHub Actions.

## Global Constraints

- `declare(strict_types=1)` in every PHP file including tests. Pint and Larastan **level 6** must pass. No baseline, no `ignoreErrors`, no PHPStan stub files.
- **No Vite, no Tailwind, no Laravel Mix.** jQuery stays at **3.7.x** — never 4.
- The palette is exactly eight colours; `up`/`down` only on price deltas and the flash; `signal` only on interactive elements.
- Git Flow: branch `feature/ship` from `develop`. Commit per task. **Do not merge.**
- Git commands need the sandbox disabled. `php` is keg-only: `export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"`.
- **Never flush Redis db 0** — that is development.

## Facts this plan must respect

These came out of the previous four plans' reviews and are the reason several steps exist:

1. **`Manifest::asset()` throws when `public/build/manifest.json` is absent** — deliberately, so a missing build is loud rather than an unstyled page. `/public/build` is gitignored. Therefore **`npm run build` must run before `vendor/bin/pest`** in CI, and before `php artisan config:cache` in the image, or `/` returns 500.
2. **`php artisan queue:work` is required for any broadcast to reach a browser.** The events are queued `ShouldBroadcast` on a Redis queue. Without a worker the tape silently freezes and `queue_depth` climbs — that climb is the diagnostic.
3. **`tape:ingest` reads the watchlist only at boot.** A symbol added in the console will not tick until ingest restarts. The console invites the action that breaks it, so this must be documented prominently.
4. **PHP is 8.4, not 8.3** (decision D1 — Pest 5 requires `^8.4`). The image base is `php:8.4-fpm-alpine`.
5. **`REDIS_CLIENT` must be `predis`.** `CreditBudget`'s Lua call goes through `command('eval', ...)`, whose argument shape is predis-only; under phpredis it throws. The config default is already `predis` and a test pins it — do not let a compose file or Dockerfile override it.
6. **The simulated driver reports itself as `simulated`** and its lag is genuinely 0ms. That is correct for generated data and must not be presented as live.
7. **Google Fonts is currently a hard external dependency at page load.** Task 1 removes it.

## Deviations recorded by this plan

| # | Change | Why |
|---|---|---|
| D22 | Fonts are self-hosted rather than loaded from Google | The typography *is* the product — tabular numerals holding a decimal column is the design's central claim. A container without egress, a corporate proxy, or an offline reviewer would degrade it to system fonts and take the alignment with it. |
| D23 | The image base is `php:8.4-fpm-alpine`, not the 8.3 the original build prompt named | Follows D1. Pest 5 requires PHP `^8.4`. |

---

### Task 1: Self-host the three font families

**Files:**
- Create: `public/fonts/*.woff2`, `resources/scss/_fonts.scss`
- Modify: `resources/views/layouts/app.blade.php`, `resources/scss/app.scss`, `.gitignore` (ensure `public/fonts` is NOT ignored)

The design uses three families at weights **400, 500, 600, 700** (grep confirms all four are used). Space Grotesk needs 500/600/700, Inter 400/500/600, IBM Plex Mono 400/500/600.

- [ ] **Step 1: Fetch the woff2 files**

Google's CSS API returns woff2 URLs when asked with a modern browser User-Agent. For each family, fetch the stylesheet, extract the `latin` subset woff2 URLs, and download them into `public/fonts/`:

```bash
mkdir -p public/fonts
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'
curl -sH "User-Agent: $UA" \
  'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap' \
  -o /tmp/gf.css
grep -oE 'https://[^)]+\.woff2' /tmp/gf.css | sort -u | while read -r url; do
    curl -s "$url" -o "public/fonts/$(basename "$url")"
done
ls -la public/fonts/
```

Then map each downloaded file to its family and weight by reading `/tmp/gf.css` — the `@font-face` blocks name the family, weight and `unicode-range` immediately above each URL. **Keep only the `latin` subset** (the block whose `unicode-range` starts `U+0000-00FF`); the others are dead weight for this interface. Rename files to something legible like `space-grotesk-600.woff2`.

- [ ] **Step 2: Write `_fonts.scss`**

One `@font-face` per family/weight, all with `font-display: swap` and `format('woff2')`, pointing at `/fonts/<file>.woff2`. Import it FIRST in `app.scss`, before `_tokens`.

- [ ] **Step 3: Remove the external dependency**

Delete the three Google Fonts `<link>` tags from `resources/views/layouts/app.blade.php`.

- [ ] **Step 4: Verify the fonts actually load locally**

```bash
npm run build
php artisan serve --port=8090 &
curl -sI http://127.0.0.1:8090/fonts/<one-file>.woff2 | head -3
```
Expect `200` and `content-type: font/woff2`.

Then, if you can drive a browser: load the console with the network blocked to `fonts.googleapis.com` (or simply confirm zero requests to that host in the network log) and confirm the tape still renders in IBM Plex Mono with the decimal column aligned. **A screenshot comparison against the previous render is the real check** — report what you observed.

- [ ] **Step 5: Gates and commit**

```bash
npm run build && npm run test:js
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pest
grep -rn "fonts.googleapis\|fonts.gstatic" resources/ && echo "STILL EXTERNAL" || echo "clean"
git add -A
git commit -m "feat: self-host the three font families

The typography is the product — tabular numerals holding a decimal column is
the design's central claim, and a container without egress or a proxied
network would have degraded it to system fonts and taken the alignment with
it. Latin subset only; the other subsets are dead weight for this interface.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Development Docker Compose

**Files:**
- Create: `docker-compose.yml`, `docker/php/php.ini`
- Modify: `.env.example`

Services: `postgres` (16-alpine), `redis` (7-alpine), `app` (php-fpm, source mounted), `nginx`, `reverb`, `queue`, `ingest`. Named volumes for Postgres and Redis so data survives a `down`.

- [ ] **Step 1: Write `docker-compose.yml`**

Requirements, each of which matters:
- `postgres:16-alpine` with `POSTGRES_DB=tapehouse`, a healthcheck on `pg_isready`, and a named volume.
- `redis:7-alpine` with a healthcheck on `redis-cli ping` and a named volume.
- `app`, `reverb`, `queue` and `ingest` all build from the same Dockerfile target and mount the source, so a code change is picked up without a rebuild.
- `reverb` runs `php artisan reverb:start --host=0.0.0.0 --port=8080`.
- **`queue` runs `php artisan queue:work --tries=3 --max-time=3600`. It is not optional** — without it no broadcast reaches a browser.
- `ingest` runs `php artisan tape:ingest`, with `TAPEHOUSE_SIMULATOR_ENABLED` passed through from the environment so a developer without an API key gets a moving tape.
- Every PHP service depends on postgres and redis being *healthy*, not merely started.
- `REDIS_CLIENT=predis` is set explicitly — the token bucket's Lua call only works under predis.

- [ ] **Step 2: Bring it up and prove it works**

```bash
docker compose up -d --build
docker compose ps
docker compose exec app php artisan migrate:fresh --seed
docker compose logs ingest --tail=20
docker compose exec app php artisan tinker --execute="echo App\Models\Symbol::count(), PHP_EOL;"
```

Expect all services healthy, 40 symbols seeded, and the ingest log showing `tape:ingest running · driver …`.

Then confirm the whole loop end to end inside the containers:
```bash
docker compose exec app php artisan tinker --execute="
  print_r(app(App\Services\Quotes\QuoteCache::class)->many(['AAPL']));"
```
Expect a cached quote. **Report the actual output.** If it is empty, the ingest or queue service is misconfigured — investigate rather than moving on.

- [ ] **Step 3: Commit**

---

### Task 3: The production image

**Files:**
- Create: `Dockerfile`, `docker/nginx/default.conf`, `docker/supervisor/supervisord.conf`, `docker/entrypoint.sh`, `.dockerignore`

- [ ] **Step 1: Write the multi-stage Dockerfile**

```
Stage 1 — node:22-alpine     npm ci, npm run build  → public/build
Stage 2 — composer:2          composer install --no-dev --optimize-autoloader
Stage 3 — php:8.4-fpm-alpine  runtime
```

Stage 3 must:
- install `pdo_pgsql`, `pcntl`, `sockets`, `opcache` (pcntl is **required** — `tape:ingest` references `SIGTERM`/`SIGINT` and fatals without it)
- install `nginx` and `supervisor`
- copy `vendor/` from stage 2 and `public/build/` from stage 1
- copy the application source
- run as a **non-root** user
- expose 8080 and set the entrypoint

- [ ] **Step 2: Write `docker/nginx/default.conf`**

Serves `public/`, routes everything else to `index.php`, proxies PHP to php-fpm on 127.0.0.1:9000, and proxies `/app` and `/apps` to Reverb on 127.0.0.1:8080 with the WebSocket upgrade headers:

```
location ~ ^/(app|apps) {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

Without `Upgrade`/`Connection` the socket handshake fails and the tape silently never updates.

- [ ] **Step 3: Write `docker/supervisor/supervisord.conf`**

**Five** programs, all `autorestart=true`, all logging to stdout/stderr so a container platform captures them:
`php-fpm`, `nginx`, `reverb` (`reverb:start --host=0.0.0.0 --port=8080`), `queue` (`queue:work --tries=3 --max-time=3600`), `ingest` (`tape:ingest`).

Missing the queue program is the failure that makes the tape freeze with no error — name it explicitly in a comment.

- [ ] **Step 4: Write `docker/entrypoint.sh`**

```sh
#!/bin/sh
set -e
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec supervisord -c /etc/supervisor/supervisord.conf
```

Note the ordering: `config:cache` after `migrate` so a failed migration stops the boot loudly.

- [ ] **Step 5: Write `.dockerignore`**

Exclude `node_modules`, `vendor`, `.git`, `.env`, `tests`, `.superpowers`, `docs`, `public/build` (the image builds its own).

- [ ] **Step 6: Build the image and prove it runs**

```bash
docker build -t tapehouse:local .
docker run --rm tapehouse:local php -m | grep -E "pdo_pgsql|pcntl|sockets|opcache"
docker run --rm tapehouse:local sh -c "ls public/build/ && cat public/build/manifest.json"
```
All four extensions must be present, and the manifest must exist inside the image — if it does not, `/` will 500 because `Manifest::asset()` throws by design.

Then run it against the compose Postgres/Redis and confirm the console actually serves:
```bash
docker run --rm -d --name th-prod --network tapehouse_default -p 8099:8080 \
  -e DB_HOST=postgres -e DB_DATABASE=tapehouse -e DB_USERNAME=tapehouse -e DB_PASSWORD=secret \
  -e REDIS_HOST=redis -e REDIS_CLIENT=predis -e APP_KEY="$(php artisan key:generate --show)" \
  tapehouse:local
sleep 15
docker exec th-prod supervisorctl status
curl -sI http://127.0.0.1:8099/login | head -2
docker logs th-prod --tail=30
docker rm -f th-prod
```
Expect all five supervisor programs `RUNNING` and `/login` returning 200. **Report the actual supervisorctl output** — a program in `FATAL` is the single most likely defect here.

- [ ] **Step 7: Commit**

---

### Task 4: Continuous integration

**Files:** Create `.github/workflows/ci.yml`

- [ ] **Step 1: Write the workflow**

On push and pull request. One job with `postgres:16` and `redis:7` service containers (both with healthchecks), PHP **8.4** via `shivammathur/setup-php` with `pdo_pgsql, pcntl, sockets`, and Node 22.

Steps, in this order — the ordering is load-bearing:
1. `composer install --prefer-dist --no-progress`
2. `npm ci`
3. **`npm run build`** — must come before the test step; `Manifest::asset()` throws without a manifest and four `ConsoleViewTest` cases 500
4. `npm run test:js`
5. `cp .env.example .env && php artisan key:generate`
6. `vendor/bin/pint --test`
7. `vendor/bin/phpstan analyse --memory-limit=512M`
8. `php artisan test`

Env for the test step: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=tapehouse_test`, `REDIS_HOST=127.0.0.1`, `REDIS_CLIENT=predis`.

Also add a step that fails the build if the stack constraints regress — this project's whole premise is the stack, and three separate reviews had to check it by hand:

```yaml
      - name: Assert stack constraints
        run: |
          ! grep -rniE "vite|tailwind|laravel-mix" package.json webpack.config.js resources/ config/ composer.json
          node -e "const v=require('./package.json').dependencies.jquery; if(!/^3\.7/.test(v)) { console.error('jQuery must be 3.7.x, got '+v); process.exit(1); }"
```

- [ ] **Step 2: Validate the workflow locally**

There is no runner here, so validate by parsing rather than guessing:
```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('yaml ok')"
```
Then re-run the exact command sequence from the workflow locally and confirm each step passes. Report which steps you ran.

- [ ] **Step 3: Commit**

---

### Task 5: `CLAUDE.md` and `README.md`

**Files:** Create `CLAUDE.md`, `README.md`

- [ ] **Step 1: Write `CLAUDE.md`**

Cover: the stack constraints and that they are JD-driven rather than preference; `declare(strict_types=1)` everywhere; backed enums for every status field; **no facades inside `app/Services/**`**; controllers delegate and never contain logic; the ingest path never blocks on Postgres or alert evaluation; money is a string end to end and never a float; Pint and Larastan level 6 must pass before any commit; and that `npm run build` must run before the test suite.

- [ ] **Step 2: Write `README.md`**

Sections:

1. **What this is** — one paragraph, plus the live URL placeholder `tapehouse.kevinciang.com`.
2. **Why this stack** — states plainly that Laravel, jQuery, Webpack, PostgreSQL and Redis were chosen because they are the Twelve Data stack, and that the author's daily stack is React/Vite.
3. **Architecture** — the dual-driver ingest with demotion, the Redis-read/Postgres-audit split, and broadcast coalescing. A diagram is welcome.
4. **Performance decisions** — the seven from the PRD, one line each.
5. **What this project is and isn't** — short and factual, per the PRD's honesty constraints:
   - Redis was learned on this project; it is not production experience.
   - The last production Laravel was February 2022.
   - Webpack was used because the JD names it, over a daily Vite habit.
   - jQuery was chosen deliberately for the same reason.
   - The deployed demo runs on a Twelve Data trial key and degrades to polling when streaming credits are exhausted — the intended behaviour.
   - **Where the simulated driver is active, the ops panel says `simulated` and lag reads 0ms, because generated data has no network transit.**
6. **Running locally** — `docker compose up`, seed, credentials (`operator@tapehouse.dev` / `tapehouse`).
7. **Known limitations** — this section is required, and must include:
   - **`tape:ingest` reads the watchlist only at boot.** A symbol added in the console does not tick until ingest restarts. This is the one that will bite a reviewer, because the console invites the action.
   - **A queue worker must be running** or no broadcast reaches the browser; `queue_depth` climbing on the ops panel is the symptom.
   - **What has and has not been verified against the live upstream.** Both
     transports have now run against the real Twelve Data API: REST polling
     wrote real quotes, and the WebSocket driver streamed real ticks. The
     automatic **demotion** path has never fired against a genuine upstream
     rejection — it is covered by unit tests only, because the key in use was
     never rejected.
   - **Under polling, "lag" measures quote age, not network transit.**
     `/quote` returns the last quote's own timestamp, so against a closed
     market the ops panel's p50/p95 reads as hours. That is honest — the data
     really is that old — but it is not the sub-second figure the streaming
     path produces.
8. **Testing** — how to run, and what the suite actually covers (the token bucket's atomicity and partial grants, driver demotion and promotion, the polling cursor under starvation, tick-buffer batching, alert cooldowns, cross-operator isolation, and the cross-tenant broadcast guard).
9. **Git Flow** — `main`, `develop`, `feature/*`.

Keep section 5 short and factual. It is there so a reviewer finds the gaps stated rather than hidden.

- [ ] **Step 3: Verify every claim in the README**

Do not write a claim you have not checked. For each command in "Running locally", run it. For each capability in "Testing", confirm a test exists. Report any claim you had to soften.

- [ ] **Step 4: Gates and commit**

---

## Definition of done

- [ ] No request to `fonts.googleapis.com` or `fonts.gstatic.com` at page load
- [ ] `docker compose up -d --build` brings up all seven services healthy
- [ ] `docker build -t tapehouse:local .` succeeds; all five supervisor programs report RUNNING; `/login` returns 200
- [ ] `.github/workflows/ci.yml` parses and its steps pass locally, in order, with the build before the tests
- [ ] CI fails if Vite/Tailwind/Mix appear or jQuery leaves 3.7.x
- [ ] `README.md` documents the watchlist-at-boot limitation, the queue-worker requirement, and the unverified WebSocket path
- [ ] `vendor/bin/pest`, `npm run test:js`, `phpstan` and `pint --test` all green

## What this plan does not build

Deployment to Cloud Run — a separate exercise. The sparkline drawer — cut in Plan 1 and never in scope.
