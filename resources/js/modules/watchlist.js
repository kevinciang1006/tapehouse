// The tape panel's "Add symbol" search and each row's remove control.
//
// Both flows call into tape.js's addSymbol()/removeSymbol() rather than
// touching #tape-rows directly — tape.js owns the DOM structure and the
// rowsByTicker map a new row must be wired into so it starts receiving
// quote patches, and inserting/removing there stays a single-row operation,
// never a repaint, so no other row's in-flight flash is interrupted.
//
// jQuery split: the debounced search input binding and the result-list's DOM
// build/teardown go through jQuery here (and in alerts.js's rule form and
// toggle delegation) — a handful of low-frequency, form-adjacent
// interactions where $.fn reads naturally. tape.js's per-quote cell patching
// stays vanilla DOM: it runs on every tick for every row on screen, and
// wrapping that hot path in jQuery would cost real work for no benefit.

import $ from 'jquery';
import { get, post, del } from './api.js';
import { addSymbol, removeSymbol } from './tape.js';

const SEARCH_DEBOUNCE_MS = 250;

/** @type {Record<string, HTMLElement|null>|null} */
let els = null;

let searchTimer = null;

export async function mount() {
    const addBtn = document.getElementById('add-symbol-btn');

    if (!addBtn) {
        return;
    }

    els = {
        addBtn,
        panel: document.getElementById('symbol-search-panel'),
        input: document.getElementById('symbol-search-input'),
        results: document.getElementById('symbol-search-results'),
        rows: document.getElementById('tape-rows'),
    };

    els.addBtn.addEventListener('click', openPanel);
    $(els.input).on('input', onSearchInput);
    els.results?.addEventListener('click', onResultClick);
    els.rows?.addEventListener('click', onRowClick);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onDocumentClick);
}

function openPanel() {
    if (!els?.panel || !els.input || !els.results) {
        return;
    }

    els.panel.hidden = false;
    els.addBtn.hidden = true;
    els.input.value = '';
    els.results.textContent = '';
    els.input.focus();
}

/**
 * @param {{restoreFocus?: boolean}} [options] - true for a keyboard-initiated
 * close (Escape), so focus does not silently drop to <body>; false for an
 * outside click or a successful pick, where the operator's attention has
 * already moved elsewhere and pulling focus back would fight them.
 */
function closePanel({ restoreFocus = false } = {}) {
    if (!els?.panel) {
        return;
    }

    els.panel.hidden = true;
    els.addBtn.hidden = false;

    if (restoreFocus) {
        els.addBtn.focus();
    }
}

/** @param {MouseEvent} event */
function onDocumentClick(event) {
    if (!els?.panel || els.panel.hidden) {
        return;
    }

    const target = /** @type {Node} */ (event.target);

    if (els.addBtn.contains(target) || els.panel.contains(target)) {
        return;
    }

    closePanel();
}

/** @param {KeyboardEvent} event */
function onKeydown(event) {
    if (event.key === 'Escape' && els?.panel && !els.panel.hidden) {
        closePanel({ restoreFocus: true });
    }
}

function onSearchInput() {
    if (!els?.input || !els.results) {
        return;
    }

    const q = els.input.value.trim();

    clearTimeout(searchTimer);

    // Without the debounce every keystroke is its own request.
    searchTimer = setTimeout(() => runSearch(q), SEARCH_DEBOUNCE_MS);
}

/** @param {string} q */
async function runSearch(q) {
    if (!els?.results) {
        return;
    }

    if (q === '') {
        els.results.textContent = '';
        return;
    }

    try {
        const response = await get(`/api/symbols?q=${encodeURIComponent(q)}`);
        renderResults(response.data);
    } catch (error) {
        console.error('[watchlist] symbol search failed', error);
    }
}

/**
 * @param {Array<{id: number, ticker: string, name: string}>} symbols
 */
function renderResults(symbols) {
    if (!els?.results) {
        return;
    }

    const $results = $(els.results).empty();

    if (symbols.length === 0) {
        $results.append($('<div>').addClass('symbol-search__empty').text('no matches'));
        return;
    }

    symbols.forEach((symbol) => {
        const $btn = $('<button>')
            .attr('type', 'button')
            .addClass('symbol-search__result')
            .attr('data-symbol-id', String(symbol.id))
            .append($('<span>').addClass('symbol-search__result-ticker num').text(symbol.ticker))
            .append($('<span>').addClass('symbol-search__result-name').text(symbol.name));

        $results.append($btn);
    });
}

/** @param {MouseEvent} event */
async function onResultClick(event) {
    const target = /** @type {HTMLElement} */ (event.target);
    const btn = target.closest('.symbol-search__result');

    if (!(btn instanceof HTMLButtonElement) || !btn.dataset.symbolId || btn.disabled) {
        return;
    }

    btn.disabled = true;

    try {
        const response = await post('/api/watchlist/symbols', {
            symbol_id: Number(btn.dataset.symbolId),
        });

        // The endpoint returns the whole watchlist in position order and the
        // symbol just attached is always placed last (see WatchlistController
        // ::store), so the last entry is the row to paint — no repaint of
        // the rest of the tape.
        const symbols = response.data.symbols;
        const added = symbols[symbols.length - 1];

        addSymbol(added);
        closePanel();
    } catch (error) {
        console.error('[watchlist] add symbol failed', error);
        btn.disabled = false;
    }
}

/** @param {MouseEvent} event */
async function onRowClick(event) {
    const target = /** @type {HTMLElement} */ (event.target);
    const btn = target.closest('.tape-row__remove');

    if (!(btn instanceof HTMLButtonElement) || btn.disabled) {
        return;
    }

    const row = btn.closest('.tape-row');
    const symbolId = row instanceof HTMLElement ? Number(row.dataset.symbolId) : NaN;

    if (!Number.isFinite(symbolId)) {
        return;
    }

    btn.disabled = true;

    try {
        await del(`/api/watchlist/symbols/${symbolId}`);
        removeSymbol(symbolId);
    } catch (error) {
        console.error('[watchlist] remove symbol failed', error);
        btn.disabled = false;
    }
}
