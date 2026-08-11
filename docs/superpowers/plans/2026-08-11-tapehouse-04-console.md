# Tapehouse Plan 4 — The Console Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The operator console — a hand-written Webpack build, SCSS implementing the design tokens exactly, a Blade shell, and the jQuery/ES-module frontend that renders the live tape, the ops panel and the alerts panel.

**Architecture:** Blade renders a static shell and nothing else. Every value on screen arrives from the JSON API or a Reverb frame. `tape.js` patches individual cells rather than re-rendering the table, because a full repaint would destroy the flash mid-decay.

**Tech Stack:** Webpack 5 (hand-written, no Vite, no Mix), SCSS, jQuery 3.7, vanilla ES modules, Laravel Echo + pusher-js against Reverb.

## Global Constraints

Inherited from `docs/superpowers/specs/2026-08-10-tapehouse-design.md` and Plans 1–3.

- `declare(strict_types=1)` at the top of every PHP file, including tests.
- **No Vite, no Tailwind, no Laravel Mix** anywhere — in config, dependencies, scripts, or comments. The tree has been kept clean of them since Plan 1 and three separate reviews have checked.
- No business logic in controllers. `env()` only inside `config/`.
- Pint and Larastan **level 6** must pass before every commit. No baseline, no `ignoreErrors`, no level reduction, **no PHPStan stub files** — use `Pest\Laravel\*` function helpers where `$this->` helpers trip the analyser.
- Git Flow: branch `feature/console` from `develop`. Commit per task. **Do not merge.**
- Git commands need the sandbox disabled. `php` is keg-only: `export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"`.
- **Never flush Redis db 0** — that is development. Tests use db 15.

## The design is a specification, not a suggestion

`docs/design/Tapehouse.dc.html` is the authoritative source for every value below. Where this plan and that file disagree, the file wins — read it.

**Palette** — these eight are the entire palette. Nothing else is coloured.

```
paper   #FBFBFD   app background
panel   #FFFFFF   cards, table surface
ink     #0B1220   primary type
muted   #6B7789   labels, secondary type
rule    #E4E8EF   hairlines, borders
signal  #1A56DB   interactive ONLY — links, primary button, active nav, focus
up      #067A53   price deltas and flashes ONLY
down    #C2334D   price deltas and flashes ONLY
```

**Hard rule:** `up`/`down` appear only on price deltas and the flash wash. `signal` appears only on interactive elements. A status is communicated by weight, position or a hairline — never by a hue.

**Type**

```
Space Grotesk   section labels, panel headers, wordmark
Inter           body, buttons, form fields
IBM Plex Mono   every numeral, ticker, timestamp, ID
```

Section labels: Space Grotesk, uppercase, 11px, `letter-spacing: 0.08em`, `muted`. Rail labels: the same at 9px.

**Geometry** — status bar 48px · degraded banner 34px · left rail 56px · right panel 320px · panel headers 44px · table rows 52px · numeric grid `1fr 124px 84px 54px` (last, change, change %, age).

**Numerics.** Every numeral is IBM Plex Mono with `font-variant-numeric: tabular-nums`. Prices additionally split into **two fixed spans** — integer part 92px right-aligned in `ink`, fraction 64px left-aligned in `muted`. That split, not `tabular-nums` alone, is what holds the decimal column across 2-decimal equities, 5-decimal forex and thousands-separated crypto.

**The flash** — the signature element. On a price update the numeric row takes a background wash of `up`/`down` at 12% opacity, decaying to transparent over 600ms on `cubic-bezier(0.16, 1, 0.3, 1)`. **Nothing moves** — no scale, no slide, no pulse. Under `prefers-reduced-motion` the decay is replaced by a 1px `up`/`down` left border that persists 600ms then disappears.

**Staleness** is structural, not chromatic: past the threshold the age value shifts to `muted` and the row's left border thickens to 2px `muted`.

**Hover** on a row: a 1px `signal` left border and nothing else. **Focus**: visible, `signal`, 2px offset.

## Environment facts

- `TWELVEDATA_API_KEY` is **empty**. Run the console against the simulated driver: `TAPEHOUSE_SIMULATOR_ENABLED=true TAPEHOUSE_SIMULATOR_INTERVAL_MS=0 php artisan tape:ingest`.
- Under the simulator, ingest **lag is 0ms by design** — generated data has no network transit, and fabricating a gap was explicitly rejected. The ops panel will read `0ms / 0ms`. That is correct, not a bug.
- The API is session-authenticated with CSRF on state-changing routes. Every `fetch` must send `X-CSRF-TOKEN` from the meta tag and `credentials: 'same-origin'`.
- Broadcast names are custom, so **Echo needs the leading dot**: `.listen('.quotes.updated')`, `.listen('.feed.state')`, `.listen('.alert.fired')`. Losing that dot is the single easiest way to waste an afternoon.
- `AlertFired` lands on `private-tape.{userId}`, the same channel as quotes — `alerts.js` and `tape.js` must share one channel object rather than subscribing twice.
- `GET /api/ops/health` already supplies `stale_seconds` and `feed_stopped`; do not hardcode thresholds in JavaScript.
- Quote frames carry no `price_decimals`. `tape.js` gets precision from `GET /api/watchlist` and holds a ticker→symbol map, so the watchlist fetch is a prerequisite for first paint and for every reconnect repaint.

## Deviations recorded by this plan

| # | Change | Why |
|---|---|---|
| D20 | `format.js` is unit-tested with Node's built-in `node:test`, run via `npm run test:js` | The spec says no JS test runner is in scope, and no bundler-integrated runner is being added. But price formatting is where a rendering bug silently corrupts what an operator reads, and `node:test` ships with Node — zero new dependencies, no build involvement. Everything else is verified in a real browser. |
| D21 | A `ConsoleController` and the `/` route are added here | Plan 1 deliberately removed the scaffold's root route with a comment saying the console arrives later. This is later. |

---

### Task 1: Webpack, SCSS tokens, the Blade shell and login

**Files:**
- Create: `webpack.config.js`, `resources/js/app.js`, `resources/scss/{app,_tokens,_base,_layout}.scss`
- Create: `app/Http/Controllers/ConsoleController.php`, `resources/views/layouts/app.blade.php`, `resources/views/console.blade.php`
- Create: `app/Support/Manifest.php`
- Modify: `package.json`, `routes/web.php`, `resources/views/auth/login.blade.php`, `.gitignore`
- Test: `tests/Feature/Console/ConsoleViewTest.php`

**Interfaces:**
- Produces: `npm run build` → `public/build/app.[contenthash].js`, `app.[contenthash].css`, `manifest.json`; `Manifest::asset(string $entry): string` used by the Blade layout; `GET /` behind `auth` rendering the console shell.

- [ ] **Step 1: Install the build dependencies**

```bash
npm install --save-dev webpack webpack-cli sass sass-loader css-loader mini-css-extract-plugin css-minimizer-webpack-plugin babel-loader @babel/core @babel/preset-env webpack-manifest-plugin
npm install jquery laravel-echo pusher-js
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Console/ConsoleViewTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('sends a guest to the login screen', function (): void {
    get('/')->assertRedirect('/login');
});

it('renders the console shell for an operator', function (): void {
    actingAs(User::factory()->create());

    get('/')->assertOk()->assertSee('TAPEHOUSE', false);
});

it('exposes the csrf token and the operator id the frontend needs', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    // echo.js subscribes to private-tape.{userId}; api.js sends X-CSRF-TOKEN.
    // Both read these meta tags, so a missing one breaks the console silently.
    get('/')
        ->assertOk()
        ->assertSee('name="csrf-token"', false)
        ->assertSee('name="user-id" content="'.$user->id.'"', false);
});

it('renders the login screen for a guest', function (): void {
    get('/login')->assertOk()->assertSee('Operator sign in', false);
});

it('states plainly that the demo runs on a trial key', function (): void {
    // The design requires this: an operator must know the feed degrades to
    // polling rather than discovering it as a fault.
    get('/login')->assertOk()->assertSee('polling', false);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Console/ConsoleViewTest.php`
Expected: FAIL — no `/` route.

- [ ] **Step 4: Write the Webpack config**

Create `webpack.config.js` — hand-written, CommonJS, no framework preset:

```js
const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

module.exports = (env, argv) => {
    const isProduction = argv.mode === 'production';

    return {
        entry: { app: './resources/js/app.js' },
        output: {
            path: path.resolve(__dirname, 'public/build'),
            // Content hashing is what lets the Blade helper emit a cache-busted
            // URL without a version query string.
            filename: isProduction ? '[name].[contenthash].js' : '[name].js',
            publicPath: '/build/',
            clean: true,
        },
        module: {
            rules: [
                {
                    test: /\.js$/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: { presets: [['@babel/preset-env', { targets: 'last 2 versions' }]] },
                    },
                },
                {
                    test: /\.scss$/,
                    use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'],
                },
            ],
        },
        plugins: [
            new MiniCssExtractPlugin({
                filename: isProduction ? '[name].[contenthash].css' : '[name].css',
            }),
            new WebpackManifestPlugin({ publicPath: '/build/' }),
        ],
        optimization: {
            minimizer: ['...', new CssMinimizerPlugin()],
        },
        devtool: isProduction ? false : 'source-map',
    };
};
```

Replace `package.json`'s scripts:

```json
    "scripts": {
        "dev": "webpack --mode development --watch",
        "build": "webpack --mode production",
        "test:js": "node --test resources/js/**/*.test.mjs"
    }
```

Add `/public/build` to `.gitignore` if not already ignored.

- [ ] **Step 5: Write the SCSS tokens**

Create `resources/scss/_tokens.scss`. These are the design's eight colours and nothing more:

```scss
:root {
    --paper: #FBFBFD;
    --panel: #FFFFFF;
    --ink: #0B1220;
    --muted: #6B7789;
    --rule: #E4E8EF;
    --signal: #1A56DB;
    --up: #067A53;
    --down: #C2334D;

    // The flash wash, as components, so rgba() can vary only the alpha.
    --up-rgb: 6, 122, 83;
    --down-rgb: 194, 51, 77;

    --font-label: 'Space Grotesk', sans-serif;
    --font-body: Inter, sans-serif;
    --font-mono: 'IBM Plex Mono', monospace;

    --h-statusbar: 48px;
    --h-banner: 34px;
    --h-header: 44px;
    --h-row: 52px;
    --w-rail: 56px;
    --w-panel: 320px;

    --flash-ms: 600ms;
    --flash-ease: cubic-bezier(0.16, 1, 0.3, 1);
    --flash-alpha: 0.12;
}
```

Create `resources/scss/_base.scss` with the reset, font imports, the `.label` mixin class (Space Grotesk, 11px, uppercase, `0.08em`, muted), a `.num` class applying `font-family: var(--font-mono)` and `font-variant-numeric: tabular-nums`, and a global `:focus-visible { outline: 2px solid var(--signal); outline-offset: 2px; }`.

Load fonts from Google Fonts with `display=swap` in the Blade layout head — not via `@import` in SCSS, which serialises the request behind the stylesheet.

Create `resources/scss/_layout.scss` implementing the three-zone shell: a fixed-height status bar, a flex row of rail / main / right panel, `height: 100vh`, `overflow: hidden` on the shell with each panel scrolling internally.

Create `resources/scss/app.scss` importing tokens, base, layout — later tasks add `_tape`, `_ops`, `_alerts`, `_forms`, `_auth`.

- [ ] **Step 6: Write the manifest helper**

Create `app/Support/Manifest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Manifest
{
    /** @var array<string, string>|null */
    private static ?array $entries = null;

    public static function asset(string $entry): string
    {
        self::$entries ??= self::load();

        return self::$entries[$entry]
            ?? throw new RuntimeException("Asset [{$entry}] is not in the build manifest. Run `npm run build`.");
    }

    /**
     * @return array<string, string>
     */
    private static function load(): array
    {
        $path = public_path('build/manifest.json');

        if (! is_file($path)) {
            throw new RuntimeException('Build manifest missing. Run `npm run build`.');
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
```

Note: throwing when the manifest is absent is deliberate — a silently missing stylesheet renders an unstyled console that looks like a CSS bug rather than a missing build step.

Because the tests render the console, **the build must exist before the suite runs**. Add a `beforeEach` guard or simply run `npm run build` before `vendor/bin/pest`. State which you chose in your report.

- [ ] **Step 7: Write the Blade layout and console shell**

`resources/views/layouts/app.blade.php`: doctype, `<meta name="csrf-token" content="{{ csrf_token() }}">`, `<meta name="user-id" content="{{ auth()->id() }}">`, the Google Fonts links, `<link rel="stylesheet" href="{{ \App\Support\Manifest::asset('app.css') }}">`, `@yield('body')`, and the script tag at the end of body.

`resources/views/console.blade.php`: the static shell only — status bar (wordmark, driver status dot and text, credit meter, lag, Stop feed button), the degraded banner (hidden by default), the 56px rail with three items (tape / ops / alrt), the tape panel header with symbol count and Add symbol button, an empty `<tbody id="tape-rows">`, and the 320px right panel with an ops section and an event log section. **Every dynamic value renders as an empty element with an id or data attribute** — JavaScript fills them. Do not render server-side data into the shell.

`resources/views/auth/login.blade.php`: replace the placeholder with the designed card — 360px wide, white on `paper`, 1px `rule` border, 32px padding; wordmark in Space Grotesk 16px `0.14em`; "Operator sign in" as a section label; email and password fields; one `signal` button reading `Sign in`; and the muted footnote, separated by a 1px `rule` top border, reading exactly: **"Demo instance running on a Twelve Data trial key. Streaming falls back to polling when credits run out."**

- [ ] **Step 8: Write the console controller and route**

```php
final class ConsoleController extends Controller
{
    public function __invoke(): View
    {
        return view('console');
    }
}
```

In `routes/web.php`, add inside an `auth` middleware group:

```php
Route::get('/', ConsoleController::class)->name('console');
```

- [ ] **Step 9: Build, test, gate and commit**

```bash
npm run build
ls -la public/build/
vendor/bin/pest tests/Feature/Console/ConsoleViewTest.php
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
grep -rn "vite\|Vite\|tailwind\|Tailwind\|laravel-mix" package.json webpack.config.js resources/ || echo "clean"
git add -A
git commit -m "feat: add the webpack build, scss tokens and the console shell

A hand-written Webpack config emitting content-hashed assets and a manifest
the Blade helper reads, the design's eight colour tokens as custom properties,
and the static three-zone shell. Every dynamic value is an empty element the
frontend fills — nothing is server-rendered into the tape.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: `api.js`, `format.js`, `echo.js`

**Files:**
- Create: `resources/js/modules/{api,format,echo}.js`, `resources/js/modules/format.test.mjs`
- Modify: `resources/js/app.js`
- Test: `npm run test:js`

**Interfaces:**
- `api.get(path)`, `api.post(path, body)`, `api.patch(path, body)`, `api.del(path)` — all sending `X-CSRF-TOKEN` and `credentials: 'same-origin'`, throwing `ApiError` with `.status` on non-2xx
- `format.price(value, decimals) -> {int, frac}` — the split the design requires
- `format.signed(value, decimals) -> string`, `format.percent(value) -> string`, `format.age(seconds) -> string`
- `echo.channel()` — the single shared `private-tape.{userId}` channel object, `echo.ops()`, `echo.onReconnect(cb)`

- [ ] **Step 1: Write the failing JS tests**

Create `resources/js/modules/format.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { price, signed, percent, age } from './format.js';

test('splits a price into integer and fraction parts', () => {
    assert.deepEqual(price('228.41', 2), { int: '228', frac: '.41' });
});

test('groups thousands in the integer part', () => {
    // Crypto renders with separators; the integer span is right-aligned so the
    // decimal stays on the same x across every row.
    assert.deepEqual(price('94102.50', 2), { int: '94,102', frac: '.50' });
});

test('pads the fraction to the symbol precision', () => {
    // A forex pair quoting 1.08 must render 1.08000, or the decimal column
    // ragged-edges against its neighbours.
    assert.deepEqual(price('1.08', 5), { int: '1', frac: '.08000' });
});

test('does not round away precision it was given', () => {
    assert.deepEqual(price('1.08234', 5), { int: '1', frac: '.08234' });
});

test('renders XAU/USD at two places even though it is a forex pair', () => {
    assert.deepEqual(price('2411.88', 2), { int: '2,411', frac: '.88' });
});

test('handles a null or empty price without throwing', () => {
    assert.deepEqual(price(null, 2), { int: '—', frac: '' });
    assert.deepEqual(price('', 2), { int: '—', frac: '' });
});

test('signs a change with an explicit plus or minus', () => {
    assert.equal(signed('1.82', 2), '+1.82');
    assert.equal(signed('-0.00041', 5), '−0.00041');
    assert.equal(signed('0', 2), '+0.00');
});

test('uses a real minus sign, not a hyphen', () => {
    // U+2212. A hyphen is narrower and breaks the tabular column.
    assert.ok(signed('-1.5', 2).startsWith('−'));
});

test('formats a percentage to two places with a sign', () => {
    assert.equal(percent('0.80'), '+0.80%');
    assert.equal(percent('-2.14'), '−2.14%');
});

test('formats age in seconds under a minute', () => {
    assert.equal(age(0), '0s');
    assert.equal(age(59), '59s');
});

test('formats age in minutes and padded seconds past a minute', () => {
    assert.equal(age(74), '1m14s');
    assert.equal(age(3600), '60m00s');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `npm run test:js`
Expected: FAIL — module not found.

- [ ] **Step 3: Write `format.js`**

```js
const EM_DASH = '—';
const MINUS = '−';

export function price(value, decimals) {
    if (value === null || value === undefined || value === '') {
        return { int: EM_DASH, frac: '' };
    }

    const fixed = Number(value).toFixed(decimals);
    const [whole, fraction] = fixed.split('.');

    return {
        int: Number(whole).toLocaleString('en-US'),
        frac: fraction === undefined ? '' : `.${fraction}`,
    };
}

export function signed(value, decimals) {
    if (value === null || value === undefined || value === '') {
        return EM_DASH;
    }

    const n = Number(value);
    const sign = n < 0 ? MINUS : '+';

    return `${sign}${Math.abs(n).toFixed(decimals)}`;
}

export function percent(value) {
    if (value === null || value === undefined || value === '') {
        return EM_DASH;
    }

    const n = Number(value);

    return `${n < 0 ? MINUS : '+'}${Math.abs(n).toFixed(2)}%`;
}

export function age(seconds) {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const m = Math.floor(seconds / 60);
    const s = String(seconds % 60).padStart(2, '0');

    return `${m}m${s}s`;
}
```

- [ ] **Step 4: Write `api.js`**

A thin `fetch` wrapper. It must read `X-CSRF-TOKEN` from the `csrf-token` meta tag on every non-GET, send `credentials: 'same-origin'` and `Accept: application/json`, throw an `ApiError` carrying `.status` on non-2xx, and return parsed JSON otherwise. Export `get`, `post`, `patch`, `del` and `ApiError`.

The CSRF header is not optional — Plan 3 put `PreventRequestForgery` on the API group, so a POST without it returns 419.

- [ ] **Step 5: Write `echo.js`**

Configure Laravel Echo with the `reverb` broadcaster from meta tags or a small inline config object, export a lazily-created singleton, and expose:
- `tapeChannel()` — returns the ONE `private-tape.{userId}` channel object, created once. Both `tape.js` and `alerts.js` call this; `AlertFired` and `QuotesUpdated` share the channel, so subscribing twice would double-handle frames.
- `opsChannel()` — the `private-ops` channel.
- `onReconnect(cb)` — binds to the pusher connection's `connected` state after a disconnect, so `tape.js` can refetch its snapshot.

**Every `.listen()` call needs the leading dot** — `.listen('.quotes.updated')` — because the events define custom `broadcastAs()` names.

- [ ] **Step 6: Run the tests, build, gate and commit**

```bash
npm run test:js
npm run build
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pest
git add -A
git commit -m "feat: add the api, format and echo frontend modules

Price formatting splits into integer and fraction parts so the decimal column
holds across 2-, 3- and 5-decimal symbols, and uses a real minus sign because a
hyphen is narrower and breaks the tabular column. Echo exposes one shared tape
channel — quotes and fired alerts arrive on the same private channel.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: `flash.js` and `tape.js` — the live tape

**Files:**
- Create: `resources/js/modules/{flash,tape}.js`, `resources/scss/_tape.scss`
- Modify: `resources/js/app.js`, `resources/scss/app.scss`

**Interfaces:**
- `flash.apply(rowEl, direction)` — direction `'up'|'down'`
- `tape.mount()` — fetches the watchlist, renders rows, subscribes, starts the age ticker

This task builds the signature element. Read the design section above again before starting.

- [ ] **Step 1: Write `_tape.scss`**

Row is 52px, two lines. Line one: ticker (IBM Plex Mono 12.5px, 600) and name (Inter 11.5px, muted). Line two: a grid of `1fr 124px 84px 54px`.

The price cell contains two spans: `.price-int` at `width: 92px; text-align: right; color: var(--ink)` and `.price-frac` at `width: 64px; text-align: left; color: var(--muted)`.

The flash:

```scss
.tape-row__numeric {
    background-color: rgba(var(--up-rgb), 0);
    transition: background-color var(--flash-ms) var(--flash-ease);

    &.is-flashing-up { background-color: rgba(var(--up-rgb), var(--flash-alpha)); transition: none; }
    &.is-flashing-down { background-color: rgba(var(--down-rgb), var(--flash-alpha)); transition: none; }
}
```

Applying the class with `transition: none` sets the wash instantly; removing it on the next frame lets the transition carry it back to transparent. **Nothing moves** — no transform, no scale, no opacity on the row itself.

Stale: `.tape-row.is-stale { border-left: 2px solid var(--muted); } .tape-row.is-stale .tape-row__age { color: var(--muted); }`.

Hover: `.tape-row:hover { box-shadow: inset 1px 0 0 var(--signal); }` and nothing else.

Reduced motion:

```scss
@media (prefers-reduced-motion: reduce) {
    .tape-row__numeric {
        transition: none;
        &.is-flashing-up { background-color: transparent; box-shadow: inset 1px 0 0 var(--up); }
        &.is-flashing-down { background-color: transparent; box-shadow: inset 1px 0 0 var(--down); }
    }
}
```

- [ ] **Step 2: Write `flash.js`**

```js
const pending = new WeakMap();

export function apply(el, direction) {
    const cls = direction === 'down' ? 'is-flashing-down' : 'is-flashing-up';

    // Keyed by element, so a symbol ticking twice inside the decay window
    // restarts its own flash instead of stacking timers that would each strip
    // the class and cut the decay short.
    const existing = pending.get(el);
    if (existing) {
        clearTimeout(existing);
    }

    el.classList.remove('is-flashing-up', 'is-flashing-down');
    // Force a reflow so re-adding the class restarts the transition rather
    // than being coalesced away by the browser.
    void el.offsetWidth;
    el.classList.add(cls);

    requestAnimationFrame(() => {
        requestAnimationFrame(() => el.classList.remove(cls));
    });

    pending.set(el, setTimeout(() => pending.delete(el), 600));
}
```

- [ ] **Step 3: Write `tape.js`**

Responsibilities, all of which matter:

- On mount: `GET /api/watchlist`, build a ticker→`{id, name, price_decimals, asset_type}` map, render one row per symbol in position order, then `GET /api/quotes?symbols=…` and paint the initial prices.
- Subscribe to `echo.tapeChannel().listen('.quotes.updated', …)`.
- On a frame: for each quote, **patch only the changed cells** — never re-render the row or the table. A full repaint destroys every in-flight flash and resets the whole tape's decay.
- Compare the new price against the stored previous to pick the flash direction; store the new one.
- Run a 1-second interval updating every row's age column and toggling `is-stale` past the threshold from `/api/ops/health`'s `stale_seconds`.
- On `echo.onReconnect`, refetch `GET /api/quotes` and repaint before resuming — a client that reconnects has a gap, and without this the tape silently shows stale prices after any blip.

- [ ] **Step 4: Build and verify in a real browser**

```bash
npm run build
php artisan migrate:fresh --seed
# terminal 1
php artisan reverb:start
# terminal 2
TAPEHOUSE_SIMULATOR_ENABLED=true TAPEHOUSE_SIMULATOR_INTERVAL_MS=0 php artisan tape:ingest
# terminal 3
php artisan serve --port=8000
```

Sign in as `operator@tapehouse.dev` / `tapehouse` and confirm, by looking:
- ten rows render in the seeded order
- prices move and rows flash green/red, several mid-decay at once
- the decimal column stays aligned across AAPL (2dp), EUR/USD (5dp), BTC/USD (thousands) and XAU/USD (2dp)
- nothing moves during a flash
- the age column counts up and rows go stale structurally, not chromatically

Report what you actually observed. If you cannot drive a browser, say so plainly rather than claiming success.

- [ ] **Step 5: Gate and commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pest && npm run test:js
git add -A
git commit -m "feat: add the live tape and its flash

The flash sets a background wash instantly and lets a 600ms ease-out carry it
back to transparent; nothing moves. Pending timers are keyed by element in a
WeakMap so a fast symbol restarts its own decay rather than stacking timers.
The tape patches individual cells — a full repaint would destroy every
in-flight flash.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: `ops.js` — the ops panel and feed control

**Files:**
- Create: `resources/js/modules/ops.js`, `resources/scss/_ops.scss`
- Modify: `resources/js/app.js`, `resources/scss/app.scss`

Responsibilities:
- Poll `GET /api/ops/health` every 3s and subscribe to `echo.opsChannel().listen('.feed.state', …)` for immediate transitions.
- Render: driver name and time in state (`polling · 0m41s`), the credit meter as eight discrete 11×5px cells filled in `ink` and unfilled in `rule`, lag p50/p95, reconnects, queue depth.
- Tail `GET /api/feed-events?limit=50` into the event log, newest first, each entry a monospace timestamp plus an uppercase level label plus the message. `warn` and `error` render at weight 600; `info` at 400. **No colour** — the design forbids it.
- Show the degraded banner when `feed_stopped` or when credits are exhausted, with the exact copy: **"Credit budget spent. Polling rotates through symbols until the next refill."**
- Wire the `Stop feed` button to `POST /api/ops/feed/stop`, flipping its label to `Start feed` from `feed_stopped`.

**Handle a failed health poll gracefully** — Plan 3's review flagged that this endpoint 500s if Redis blips, and a 3-second poll would then throw every 3 seconds. Catch it, leave the last known values, and render the status dot dark rather than tearing the panel down.

Verify in the browser: stop the feed from the button and confirm the ingest loop actually stops and the banner appears; start it and confirm it resumes.

- [ ] Commit with a message explaining the degradation handling.

---

### Task 5: `watchlist.js` and `alerts.js`

**Files:**
- Create: `resources/js/modules/{watchlist,alerts}.js`, `resources/scss/{_alerts,_forms}.scss`
- Modify: `resources/js/app.js`, `resources/scss/app.scss`, `resources/views/console.blade.php`

`watchlist.js`: the `Add symbol` button opens a search field; queries `GET /api/symbols?q=` on a 250ms debounce; selecting a result POSTs to `/api/watchlist/symbols` and inserts the row without a full repaint; a remove control DELETEs and removes the row. Empty state copy, exactly: **"No symbols yet. Add one to start the tape."**

`alerts.js`: the rail's `alrt` item swaps the right panel to the alerts view. Lists rules from `GET /api/alert-rules` — symbol, condition rendered as `price > 230.00` or `change% < -2.00`, and an active toggle (34×18px, `signal` when on, `rule` border when off) PATCHing `is_active`. `Create rule` opens a form posting symbol, metric, condition, threshold. Below, the fired-events log from `GET /api/alert-events?limit=50`. Subscribe to the SHARED tape channel — `echo.tapeChannel().listen('.alert.fired', …)` — and prepend new fires live.

Verify in the browser: create a rule that will fire against the simulator, watch it appear in the fired log without a refresh.

- [ ] Commit.

---

## Definition of done

- [ ] `npm run build` produces content-hashed JS and CSS plus a manifest
- [ ] `npm run test:js` green
- [ ] `vendor/bin/pest` green; `phpstan` `[OK]` at level 6; `pint --test` clean
- [ ] No Vite, Tailwind or Laravel Mix anywhere in the tree
- [ ] The console renders at 1440×900 with the tape, ops panel and event log
- [ ] Prices align on the decimal across 2-, 3-, and 5-decimal symbols
- [ ] Rows flash on update and decay over 600ms with nothing moving
- [ ] Staleness renders structurally — muted age, 2px muted left border
- [ ] `Stop feed` genuinely stops the ingest loop
- [ ] Keyboard focus is visible in `signal` at 2px offset throughout

## What this plan does not build

Production Dockerfile, docker-compose, supervisord, nginx, CI and the README — Plan 5. Mobile layout — explicitly out of scope; this is a desktop tool.
