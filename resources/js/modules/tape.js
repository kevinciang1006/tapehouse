// The live tape. QuotesUpdated frames carry no price_decimals, so precision
// comes from the watchlist — fetched once on mount and held for both the
// first paint and every reconnect repaint.
//
// The one rule everything else here serves: a quotes frame PATCHES the
// changed cells of the affected rows. It never re-renders a row or the
// table — a full repaint would wipe out every row's in-flight flash and
// collapse the tape's ambient shimmer, which is the entire personality of
// the interface.

import { get } from './api.js';
import * as format from './format.js';
import * as flash from './flash.js';
import { tapeChannel, onReconnect } from './echo.js';

const CONTAINER_ID = 'tape-rows';

/** @type {Map<string, RowRefs>} */
const rowsByTicker = new Map();

let staleSeconds = 30;
let ageTimer = null;

/**
 * @typedef {object} RowRefs
 * @property {HTMLElement} el - the .tape-row itself (border/hover/stale)
 * @property {HTMLElement} numeric - .tape-row__numeric (the flash target)
 * @property {HTMLElement} intEl
 * @property {HTMLElement} fracEl
 * @property {HTMLElement} changeEl
 * @property {HTMLElement} pctEl
 * @property {HTMLElement} ageEl
 * @property {number} decimals
 * @property {number|null} prevPrice
 * @property {Date|null} quotedAt
 */

export async function mount() {
    const watchlist = await get('/api/watchlist');
    const symbols = watchlist.data.symbols;
    const tickers = symbols.map((symbol) => symbol.ticker);

    renderRows(symbols);

    const [health] = await Promise.all([
        get('/api/ops/health'),
        paintSnapshot(tickers, { doFlash: false }),
    ]);

    // 0 is a real, meaningful value here (the "feed stopped" sentinel), so
    // this must be a nullish check, not `||`.
    staleSeconds = health.data.stale_seconds ?? staleSeconds;

    // Every .listen() needs the leading dot — QuotesUpdated defines a custom
    // broadcastAs() ('quotes.updated'), so the un-dotted Pusher/Reverb event
    // name never matches and nothing arrives, silently.
    tapeChannel().listen('.quotes.updated', (frame) => {
        frame.quotes.forEach((quote) => patchQuote(quote, { doFlash: true }));
    });

    // A reconnecting client has a gap — whatever ticked while the socket was
    // down never arrived. Repaint from a fresh snapshot before resuming so
    // the tape does not silently keep showing pre-drop prices.
    onReconnect(() => {
        paintSnapshot(tickers, { doFlash: false });
    });

    startAgeTicker();
}

/**
 * @param {Array<{id: number, ticker: string, name: string, price_decimals: number}>} symbols
 */
function renderRows(symbols) {
    const container = document.getElementById(CONTAINER_ID);

    if (!container) {
        return;
    }

    container.textContent = '';
    rowsByTicker.clear();

    symbols.forEach((symbol) => {
        const refs = buildRow(symbol);
        container.appendChild(refs.el);
        rowsByTicker.set(symbol.ticker, refs);
    });
}

/**
 * @param {{ticker: string, name: string, price_decimals: number}} symbol
 * @returns {RowRefs}
 */
function buildRow(symbol) {
    const el = document.createElement('div');
    el.className = 'tape-row';
    el.dataset.ticker = symbol.ticker;

    const meta = document.createElement('div');
    meta.className = 'tape-row__meta';

    const tickerEl = document.createElement('span');
    tickerEl.className = 'tape-row__ticker';
    tickerEl.textContent = symbol.ticker;

    const nameEl = document.createElement('span');
    nameEl.className = 'tape-row__name';
    nameEl.textContent = symbol.name;

    meta.append(tickerEl, nameEl);

    const numeric = document.createElement('div');
    numeric.className = 'tape-row__numeric';

    const priceCell = document.createElement('div');
    priceCell.className = 'tape-row__price';

    const intEl = document.createElement('span');
    intEl.className = 'price-int';

    const fracEl = document.createElement('span');
    fracEl.className = 'price-frac';

    priceCell.append(intEl, fracEl);

    const changeEl = document.createElement('div');
    changeEl.className = 'tape-row__change';

    const pctEl = document.createElement('div');
    pctEl.className = 'tape-row__pct';

    const ageEl = document.createElement('div');
    ageEl.className = 'tape-row__age';

    numeric.append(priceCell, changeEl, pctEl, ageEl);
    el.append(meta, numeric);

    /** @type {RowRefs} */
    const refs = {
        el,
        numeric,
        intEl,
        fracEl,
        changeEl,
        pctEl,
        ageEl,
        decimals: symbol.price_decimals,
        prevPrice: null,
        quotedAt: null,
    };

    // Placeholder state before the first snapshot resolves, built from the
    // same format helpers as a real patch so an empty row and a null-price
    // row read identically.
    paintPrice(refs, null);
    ageEl.textContent = '—';

    return refs;
}

/**
 * @param {string[]} tickers
 * @param {{doFlash: boolean}} options
 */
async function paintSnapshot(tickers, options) {
    if (tickers.length === 0) {
        return;
    }

    const response = await get(`/api/quotes?symbols=${encodeURIComponent(tickers.join(','))}`);

    response.data.forEach((quote) => patchQuote(quote, options));
}

/**
 * Patches only the cells of the row the quote belongs to. Called both for a
 * live tick (doFlash: true) and for the initial/reconnect snapshot paint
 * (doFlash: false) — a repaint corrects the numbers without pretending to be
 * a tick.
 *
 * @param {{ticker: string, price: string|null, day_change: string|null, day_change_pct: string|null, quoted_at: string}} quote
 * @param {{doFlash: boolean}} options
 */
function patchQuote(quote, { doFlash }) {
    const row = rowsByTicker.get(quote.ticker);

    if (!row) {
        // A quote for a symbol not on this watchlist snapshot — ignore
        // rather than throw; the watchlist is the source of truth for which
        // rows exist.
        return;
    }

    paintPrice(row, quote.price);

    row.changeEl.textContent = format.signed(quote.day_change, row.decimals);
    setTone(row.changeEl, quote.day_change);

    row.pctEl.textContent = format.percent(quote.day_change_pct);
    setTone(row.pctEl, quote.day_change_pct);

    row.quotedAt = quote.quoted_at ? new Date(quote.quoted_at) : new Date();
    updateAge(row);

    const newPrice = quote.price === null || quote.price === undefined ? null : Number(quote.price);

    // Compare the new price against the stored previous to pick the flash
    // direction; store the new one regardless of whether a flash fired.
    if (doFlash && newPrice !== null && row.prevPrice !== null && newPrice !== row.prevPrice) {
        flash.apply(row.numeric, newPrice > row.prevPrice ? 'up' : 'down');
    }

    if (newPrice !== null) {
        row.prevPrice = newPrice;
    }
}

/**
 * @param {RowRefs} row
 * @param {string|null} price
 */
function paintPrice(row, price) {
    const parts = format.price(price, row.decimals);
    row.intEl.textContent = parts.int;
    row.fracEl.textContent = parts.frac;
}

/**
 * @param {HTMLElement} el
 * @param {string|null|undefined} value
 */
function setTone(el, value) {
    const missing = value === null || value === undefined || value === '';
    const negative = !missing && Number(value) < 0;

    el.classList.toggle('is-up', !missing && !negative);
    el.classList.toggle('is-down', negative);
}

/** @param {RowRefs} row */
function updateAge(row) {
    if (!row.quotedAt) {
        return;
    }

    const seconds = Math.max(0, Math.floor((Date.now() - row.quotedAt.getTime()) / 1000));

    row.ageEl.textContent = format.age(seconds);
    // Staleness renders structurally (border weight + muted age), never as
    // a colour swap on its own — the threshold itself is driver-relative and
    // comes from /api/ops/health, never hardcoded here.
    row.el.classList.toggle('is-stale', seconds > staleSeconds);
}

function startAgeTicker() {
    if (ageTimer !== null) {
        return;
    }

    ageTimer = setInterval(() => {
        rowsByTicker.forEach(updateAge);
    }, 1000);
}
