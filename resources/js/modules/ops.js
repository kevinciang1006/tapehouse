// The ops panel: status bar driver/credits/lag, the right-panel detail rows,
// the event log tail, the degraded banner and the Stop/Start feed button.
//
// Two update paths feed the same render():
//   - a GET /api/ops/health poll every HEALTH_POLL_MS, which is the only
//     source for credits/lag/queue depth/feed_stopped;
//   - echo's private-ops channel, which broadcasts .feed.state the instant
//     the ingest loop transitions driver — far faster than the poll would
//     notice on its own, but with a narrower payload (driver, time in
//     state, reconnects, last_error only).
//
// GET /api/ops/health is documented to 500 on a Redis blip. A failed poll
// must never tear the panel down or spam the console: it keeps whatever was
// last successfully rendered on screen and drops the status dot dark, same
// as an unknown/never-started driver.

import { get, post } from './api.js';
import { opsChannel } from './echo.js';
import { mmss, driverLabel, creditsLabel, formatTimestamp } from './ops-format.js';

const HEALTH_POLL_MS = 3000;

/** @type {Record<string, HTMLElement|null>|null} */
let els = null;

/** @type {any} last successfully fetched /api/ops/health `data` payload */
let lastHealth = null;

let healthy = true;
let feedRequestInFlight = false;
let pollTimer = null;

export async function mount() {
    const driverDot = document.getElementById('driver-dot');

    if (!driverDot) {
        return;
    }

    els = {
        driverDot,
        driverText: document.getElementById('driver-text'),
        creditBars: document.getElementById('credit-bars'),
        creditText: document.getElementById('credit-text'),
        lagText: document.getElementById('lag-text'),
        stopBtn: document.getElementById('stop-feed-btn'),
        banner: document.getElementById('degraded-banner'),
        opsDriver: document.getElementById('ops-driver'),
        opsCredits: document.getElementById('ops-credits'),
        opsLag: document.getElementById('ops-lag'),
        opsReconnects: document.getElementById('ops-reconnects'),
        opsQueueDepth: document.getElementById('ops-queue-depth'),
        eventLog: document.getElementById('event-log'),
    };

    els.stopBtn?.addEventListener('click', onStopFeedClick);

    await Promise.all([pollHealth(), refreshEventLog()]);

    // Every .listen() needs the leading dot — FeedStateChanged defines a
    // custom broadcastAs() ('feed.state').
    opsChannel().listen('.feed.state', (frame) => {
        if (!lastHealth) {
            return;
        }

        lastHealth = {
            ...lastHealth,
            driver: frame.driver,
            seconds_in_state: frame.seconds_in_state,
            reconnects: frame.reconnects,
            last_error: frame.last_error,
        };

        render(lastHealth);
        refreshEventLog();
    });

    pollTimer = setInterval(() => {
        pollHealth();
        refreshEventLog();
    }, HEALTH_POLL_MS);
}

async function pollHealth() {
    try {
        const response = await get('/api/ops/health');
        lastHealth = response.data;
        healthy = true;
        render(lastHealth);
    } catch (error) {
        // Log once on the transition into failure, not on every one of a
        // string of failed 3s polls — a Redis blip must not turn into a
        // console flood.
        if (healthy) {
            console.error('[ops] health poll failed, holding last known values', error);
        }

        healthy = false;
        markOffline();
    }
}

async function refreshEventLog() {
    if (!els?.eventLog) {
        return;
    }

    try {
        const response = await get('/api/feed-events?limit=50');
        renderEventLog(response.data);
    } catch (error) {
        // A separate failure domain from the health poll (Postgres, not
        // Redis) — leave whatever was last rendered rather than clearing it.
        console.error('[ops] event log fetch failed', error);
    }
}

/**
 * @param {any} health
 */
function render(health) {
    if (!els) {
        return;
    }

    const isLive = health.driver !== 'stopped';
    els.driverDot?.classList.toggle('is-live', isLive);

    const driverText = driverLabel(health.driver, health.seconds_in_state);
    setText(els.driverText, driverText);
    setText(els.opsDriver, driverText);

    renderCreditBars(health.credits.available, health.credits.capacity);
    setText(els.creditText, `${health.credits.available}/${health.credits.capacity}`);
    setText(
        els.opsCredits,
        creditsLabel(health.credits.available, health.credits.capacity, health.credits.seconds_until_next),
    );

    setText(els.lagText, `${health.lag.p50}ms`);
    setText(els.opsLag, `${health.lag.p50}ms / ${health.lag.p95}ms`);

    setText(els.opsReconnects, String(health.reconnects));
    setText(els.opsQueueDepth, String(health.queue_depth));

    renderStopButton(health.feed_stopped);
    renderBanner(health.feed_stopped, health.credits.available === 0);
}

/**
 * One banner, but never one message for two different causes: an operator
 * who clicked Stop feed and an exhausted credit budget are different
 * situations and must read as different situations, or the console
 * contradicts what is on screen (a full credit bar next to "budget spent").
 *
 * @param {boolean} feedStopped
 * @param {boolean} creditsExhausted
 */
function renderBanner(feedStopped, creditsExhausted) {
    if (!els?.banner) {
        return;
    }

    const degraded = feedStopped || creditsExhausted;
    els.banner.hidden = !degraded;

    if (!degraded) {
        return;
    }

    setText(
        els.banner,
        feedStopped
            ? 'Feed stopped by operator.'
            : 'Credit budget spent. Polling rotates through symbols until the next refill.',
    );
}

// Health poll failed: hold every value already on screen and only drop the
// dot dark, so an intermittent Redis blip reads as "no fresh signal" rather
// than the whole panel blanking out.
function markOffline() {
    els?.driverDot?.classList.remove('is-live');
}

/**
 * @param {number} available
 * @param {number} capacity
 */
function renderCreditBars(available, capacity) {
    if (!els?.creditBars) {
        return;
    }

    els.creditBars.textContent = '';

    for (let i = 0; i < capacity; i++) {
        const cell = document.createElement('div');
        cell.className = 'credit-bar';

        if (i < available) {
            cell.classList.add('is-filled');
        }

        els.creditBars.appendChild(cell);
    }
}

/** @param {boolean} feedStopped */
function renderStopButton(feedStopped) {
    setText(els?.stopBtn ?? null, feedStopped ? 'Start feed' : 'Stop feed');
}

/**
 * @param {Array<{id: number, level: string, message: string, occurred_at: string}>} events
 */
function renderEventLog(events) {
    if (!els?.eventLog) {
        return;
    }

    els.eventLog.textContent = '';
    events.forEach((event) => els.eventLog.appendChild(buildEventRow(event)));
}

/**
 * @param {{level: string, message: string, occurred_at: string}} event
 */
function buildEventRow(event) {
    const li = document.createElement('li');
    li.className = 'event-row';

    const meta = document.createElement('div');
    meta.className = 'event-row__meta';

    const ts = document.createElement('span');
    ts.className = 'event-row__ts num';
    ts.textContent = formatTimestamp(event.occurred_at);

    const level = document.createElement('span');
    level.className = 'event-row__level';
    // Severity is weight, never colour — the ops panel and event log stay
    // monochrome end to end.
    if (event.level === 'warn' || event.level === 'error') {
        level.classList.add('is-heavy');
    }
    level.textContent = event.level.toUpperCase();

    meta.append(ts, level);

    const msg = document.createElement('div');
    msg.className = 'event-row__msg';
    msg.textContent = event.message;

    li.append(meta, msg);

    return li;
}

async function onStopFeedClick() {
    if (feedRequestInFlight || !lastHealth || !els?.stopBtn) {
        return;
    }

    feedRequestInFlight = true;
    els.stopBtn.disabled = true;

    const path = lastHealth.feed_stopped ? '/api/ops/feed/start' : '/api/ops/feed/stop';

    try {
        await post(path);
        // Reflects the operator's own action immediately rather than
        // waiting up to HEALTH_POLL_MS for the next poll to notice.
        await Promise.all([pollHealth(), refreshEventLog()]);
    } catch (error) {
        console.error('[ops] feed control request failed', error);
    } finally {
        feedRequestInFlight = false;
        els.stopBtn.disabled = false;
    }
}

/**
 * @param {HTMLElement|null|undefined} el
 * @param {string} text
 */
function setText(el, text) {
    if (el) {
        el.textContent = text;
    }
}
