# Tapehouse — Claude Design prompt

Paste this into Claude Design. Output wanted: the operator console screen at desktop width, plus the login screen, plus the alert rules panel. Static visual direction only — no build.

---

## Brief

Design the interface for **Tapehouse**, an internal operations console used by engineers at a market data company. It is not a consumer trading app and must not look like one. There is no onboarding, no marketing, no gradient hero, no dark-glass fintech aesthetic. It is a tool that four people use every day and already know how to operate.

The screen answers three questions in priority order:

1. Is the feed healthy?
2. What are the prices?
3. What fired?

Density is a virtue here. An operator should be able to read the whole state of the system without scrolling. Whitespace is used to separate zones, not to make the page feel calm.

## Subject grounding

The material to draw from is the **ticker tape** — the printed paper strip that carried prices before screens. What is worth stealing from it: monospaced numerals in strict columns, symbols in caps, no decoration whatsoever, and the fact that information arrives as a continuous physical stream rather than as a refreshed page.

What to avoid stealing: nothing about this should look retro, beige, or nostalgic. It is the tape's discipline, not its era.

## Palette

Light ground, ink type. Financial terminals default to black backgrounds with green and amber, and that is exactly why this one should not.

```
paper       #FBFBFD    app background
panel       #FFFFFF    cards, table surface
ink         #0B1220    primary type, near-black navy
muted       #6B7789    labels, secondary type
rule        #E4E8EF    hairlines, table borders
signal      #1A56DB    interactive only — links, primary button, active nav
up          #067A53    deep green
down        #C2334D    deep red
```

Hard rule on colour: `up` and `down` appear **only** on price deltas and on the update flash. `signal` appears **only** on interactive elements. Nothing else in the interface is coloured. If a status needs to be communicated, it uses weight, position, or a hairline — not a hue.

## Type

```
Space Grotesk    section labels, panel headers, the wordmark
Inter            body, buttons, form fields
IBM Plex Mono    every numeral, every ticker symbol, every timestamp, every ID
```

The typographic decision that carries the design: **all numerics are IBM Plex Mono with `font-variant-numeric: tabular-nums` locked on**. Prices must not shift horizontally when digits change. This is the difference between a tool and a mockup, and it should be visible in the design — set a column of prices with varying digit counts and show them aligned on the decimal.

Section labels are Space Grotesk, uppercase, 11px, letter-spacing 0.08em, in `muted`. Use them sparingly — one per panel, not per row.

## Layout

Fixed three-zone console. No page scroll on the shell; individual panels scroll internally.

```
┌────────────────────────────────────────────────────────────────────┐
│ TAPEHOUSE   ● websocket · 4m12s     credits ▓▓▓▓▓░░░ 5/8    lag 34 │  status bar, 48px
├──────┬─────────────────────────────────────────┬───────────────────┤
│      │  THE TAPE                                │  OPS              │
│ rail │  ┌────────────────────────────────────┐ │  driver           │
│      │  │ AAPL  Apple Inc                    │ │  credits          │
│ 56px │  │        228.41   +1.82   +0.80%  2s │ │  lag p50/p95      │
│      │  ├────────────────────────────────────┤ │  reconnects       │
│ tape │  │ EUR/USD  Euro / US Dollar          │ │  queue depth      │
│ ops  │  │        1.08234  -0.00041 -0.04% 1s │ │                   │
│ alrt │  ├────────────────────────────────────┤ │  ─────────────    │
│      │  │ BTC/USD                            │ │  EVENT LOG        │
│      │  │        94,102.50 +812.20 +0.87% 0s │ │  12:04:11 warn    │
│      │  └────────────────────────────────────┘ │  ws demoted →     │
│      │                                          │  polling          │
│      │                                          │  12:03:58 info    │
└──────┴─────────────────────────────────────────┴───────────────────┘
                                                    right panel 320px
```

Left rail is 56px, icon over a 9px Space Grotesk label, no expansion behaviour. Right panel is fixed 320px. The tape takes everything remaining.

Table rows are 52px — two lines: ticker and name on line one in Inter/Plex Mono, the numeric row on line two, right-aligned in a fixed grid of four columns (last, change, change %, age). Hairline `rule` between rows, no zebra striping, no row borders on the sides.

## The signature element

**The flash.** When a price updates, the entire numeric row gets a background wash — `up` or `down` at 12% opacity — which decays to transparent over 600ms on an ease-out curve. Nothing moves. No scale, no slide, no pulse ring. The row simply registers that something happened and returns to rest.

Because ticks arrive continuously, the tape is always faintly alive: several rows mid-decay at any moment, at different opacities. That ambient shimmer is the whole personality of the interface, and it is the reason everything else is monochrome. Show this in the design by rendering three or four rows at different decay stages simultaneously.

Second-order detail worth designing: the **age column**, a monospace counter showing seconds since last tick. When a symbol goes stale (>30s), the age value shifts to `muted` and the row's left hairline thickens to 2px in `muted`. Staleness is communicated structurally, not with a colour or an icon.

## Motion

The flash and nothing else. No page-load choreography, no scroll reveals, no hover lifts. Hover on a table row shows a 1px `signal` left border and nothing more. Respect `prefers-reduced-motion` by replacing the decay with a 1px left border in `up`/`down` that persists for 600ms and disappears.

## Copy

Operator register. Terse, lowercase for status values, no exclamation, no personality.

- Driver status reads `websocket · 4m12s` or `polling · 0m41s`, not "Connected!"
- Empty watchlist: **"No symbols yet. Add one to start the tape."**
- Budget exhausted: **"Credit budget spent. Polling rotates through symbols until the next refill."** — states the consequence, does not apologise
- Upstream auth failure: **"Upstream rejected the API key. Feed is stopped."** — says what happened and implies the fix
- Buttons say the action: `Add symbol`, `Create rule`, `Stop feed`

## Screens wanted

1. **Console** — the main screen above, populated with realistic data. Mix asset types: US equities at 2 decimals, forex at 5, crypto at 2 with thousands separators. Show the polling-fallback state in the status bar, not the happy path — the degraded state is the interesting one.
2. **Alert rules panel** — the right panel switched to alerts: a list of rules (symbol, condition, threshold, active toggle) above a fired-events log with timestamps.
3. **Login** — single centered card on `paper`. Wordmark, email, password, one `signal` button. One line of small `muted` text noting this is a demo running on a Twelve Data trial key and will fall back to polling when streaming credits run out.

## Quality floor

Visible keyboard focus in `signal`, 2px offset. Contrast at AA for all type including `muted` on `paper`. Down to 1280px the layout holds; below that the right panel collapses to a bottom drawer. Do not design mobile — this is a desktop tool and pretending otherwise would be dishonest about its use.
