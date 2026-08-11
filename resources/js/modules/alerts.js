// The alerts panel: the rule list with its active toggles, the create-rule
// form, and the fired-events log — plus the rail wiring that swaps the
// right panel between the ops view (Task 4) and this one, since this is the
// last module to mount and the only one that needs both views to exist.
//
// AlertFired broadcasts on the SAME private-tape.{userId} channel
// QuotesUpdated uses (see routes/channels.php and app/Events/AlertFired.php)
// — not a dedicated alerts channel — so this module calls the memoized
// tapeChannel() from echo.js rather than opening a second subscription,
// which would double-handle every frame on that channel.

import { get, post, patch } from './api.js';
import { tapeChannel } from './echo.js';
import { formatTimestamp } from './ops-format.js';
import { conditionText, firedDetail } from './alerts-format.js';

const FIRED_LIMIT = 50;
const SEARCH_DEBOUNCE_MS = 250;

/** @type {Record<string, HTMLElement|null>|null} */
let els = null;

/** @type {Map<number, RuleRow>} */
const rulesById = new Map();

/** @type {Map<string, number>} ticker -> price_decimals, for formatting thresholds/prices */
const decimalsByTicker = new Map();

/** @type {{id: number, ticker: string}|null} */
let selectedSymbol = null;

let searchTimer = null;
let firedRowCount = 0;

/**
 * @typedef {object} RuleRow
 * @property {HTMLElement} el
 * @property {HTMLButtonElement} toggle
 * @property {string} ticker
 * @property {boolean} isActive
 * @property {boolean} toggleInFlight
 */

export async function mount() {
    const view = document.getElementById('alerts-view');

    if (!view) {
        return;
    }

    els = {
        opsView: document.getElementById('ops-view'),
        alertsView: view,
        createBtn: document.getElementById('create-rule-btn'),
        form: document.getElementById('rule-form'),
        symbolInput: document.getElementById('rule-symbol-input'),
        symbolResults: document.getElementById('rule-symbol-results'),
        metricSelect: document.getElementById('rule-metric'),
        conditionSelect: document.getElementById('rule-condition'),
        thresholdInput: document.getElementById('rule-threshold'),
        submitBtn: document.getElementById('rule-form-submit'),
        cancelBtn: document.getElementById('rule-form-cancel'),
        formError: document.getElementById('rule-form-error'),
        rulesList: document.getElementById('alert-rules'),
        firedLog: document.getElementById('alert-fired-log'),
    };

    wireRail();
    wireForm();

    await Promise.all([seedDecimals(), loadRules(), loadFired()]);

    // Every .listen() needs the leading dot — AlertFired defines a custom
    // broadcastAs() ('alert.fired'), same as QuotesUpdated's '.quotes.updated'
    // on this channel; without it nothing arrives and nothing errors.
    tapeChannel().listen('.alert.fired', (frame) => {
        prependFired(frame);
    });
}

// --- Rail: swaps the right panel between #ops-view and #alerts-view -------

function wireRail() {
    document.querySelectorAll('.rail-item').forEach((item) => {
        item.addEventListener('click', () => onRailClick(item));
    });
}

/** @param {Element} item */
function onRailClick(item) {
    const panel = /** @type {HTMLElement} */ (item).dataset.panel;

    document.querySelectorAll('.rail-item').forEach((el) => el.classList.toggle('is-active', el === item));

    // 'tape' has no right-panel view of its own — the tape panel is always
    // visible in the middle column — so only ops/alrt touch which view of
    // the right panel is shown; only one is ever unhidden.
    if (panel === 'ops' && els?.opsView && els.alertsView) {
        els.opsView.hidden = false;
        els.alertsView.hidden = true;
    } else if (panel === 'alrt' && els?.opsView && els.alertsView) {
        els.opsView.hidden = true;
        els.alertsView.hidden = false;
    }
}

// --- Rules ------------------------------------------------------------------

async function seedDecimals() {
    try {
        const watchlist = await get('/api/watchlist');
        watchlist.data.symbols.forEach((symbol) => {
            decimalsByTicker.set(symbol.ticker, symbol.price_decimals);
        });
    } catch (error) {
        console.error('[alerts] watchlist fetch for threshold precision failed', error);
    }
}

async function loadRules() {
    if (!els?.rulesList) {
        return;
    }

    const response = await get('/api/alert-rules');

    els.rulesList.textContent = '';
    rulesById.clear();
    response.data.forEach(renderRule);
}

/**
 * @param {{id: number, ticker: string, metric: string, condition: string, threshold: string, is_active: boolean}} rule
 */
function renderRule(rule) {
    if (!els?.rulesList) {
        return;
    }

    const el = document.createElement('div');
    el.className = 'rule-row';
    el.dataset.ruleId = String(rule.id);

    const main = document.createElement('div');
    main.className = 'rule-row__main';

    const ticker = document.createElement('div');
    ticker.className = 'rule-row__ticker num';
    ticker.textContent = rule.ticker;

    const condition = document.createElement('div');
    condition.className = 'rule-row__condition num';
    condition.textContent = conditionText(rule, decimalsByTicker.get(rule.ticker) ?? 2);

    main.append(ticker, condition);

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'rule-toggle';

    const knob = document.createElement('span');
    knob.className = 'rule-toggle__knob';
    toggle.appendChild(knob);

    el.append(main, toggle);
    els.rulesList.appendChild(el);

    /** @type {RuleRow} */
    const row = { el, toggle, ticker: rule.ticker, isActive: rule.is_active, toggleInFlight: false };
    rulesById.set(rule.id, row);
    paintToggle(row);

    toggle.addEventListener('click', () => onToggleClick(rule.id));
}

/** @param {number} ruleId */
async function onToggleClick(ruleId) {
    const row = rulesById.get(ruleId);

    if (!row || row.toggleInFlight) {
        return;
    }

    const nextActive = !row.isActive;

    // Optimistic: the toggle reflects the click immediately and rolls back
    // only if the PATCH fails, rather than waiting on the round trip.
    row.toggleInFlight = true;
    row.isActive = nextActive;
    paintToggle(row);

    try {
        await patch(`/api/alert-rules/${ruleId}`, { is_active: nextActive });
    } catch (error) {
        console.error('[alerts] toggle failed', error);
        row.isActive = !nextActive;
        paintToggle(row);
    } finally {
        row.toggleInFlight = false;
    }
}

/** @param {RuleRow} row */
function paintToggle(row) {
    row.toggle.classList.toggle('is-on', row.isActive);
    row.toggle.setAttribute('aria-pressed', String(row.isActive));
    row.toggle.setAttribute('aria-label', `${row.ticker} alert ${row.isActive ? 'on' : 'off'}`);
}

// --- Create-rule form ---------------------------------------------------

function wireForm() {
    els?.createBtn?.addEventListener('click', openForm);
    els?.cancelBtn?.addEventListener('click', closeForm);
    els?.form?.addEventListener('submit', onSubmit);
    els?.symbolInput?.addEventListener('input', onSymbolInput);
    els?.symbolResults?.addEventListener('click', onSymbolResultClick);
}

function openForm() {
    if (!els?.form || !els.createBtn) {
        return;
    }

    els.createBtn.hidden = true;
    els.form.hidden = false;
    els.symbolInput?.focus();
}

function closeForm() {
    if (!els?.form || !els.createBtn) {
        return;
    }

    els.form.hidden = true;
    els.createBtn.hidden = false;
    resetForm();
}

function resetForm() {
    if (!(els?.form instanceof HTMLFormElement)) {
        return;
    }

    els.form.reset();
    selectedSymbol = null;

    if (els.symbolResults) {
        els.symbolResults.textContent = '';
    }

    hideError();
}

function onSymbolInput() {
    if (!els?.symbolInput || !els.symbolResults) {
        return;
    }

    selectedSymbol = null;

    const q = els.symbolInput.value.trim();

    clearTimeout(searchTimer);

    if (q === '') {
        els.symbolResults.textContent = '';
        return;
    }

    // Without the debounce every keystroke is its own request.
    searchTimer = setTimeout(() => runSymbolSearch(q), SEARCH_DEBOUNCE_MS);
}

/** @param {string} q */
async function runSymbolSearch(q) {
    if (!els?.symbolResults) {
        return;
    }

    try {
        const response = await get(`/api/symbols?q=${encodeURIComponent(q)}`);

        response.data.forEach((symbol) => decimalsByTicker.set(symbol.ticker, symbol.price_decimals));
        renderSymbolResults(response.data);
    } catch (error) {
        console.error('[alerts] symbol search failed', error);
    }
}

/**
 * @param {Array<{id: number, ticker: string, name: string}>} symbols
 */
function renderSymbolResults(symbols) {
    if (!els?.symbolResults) {
        return;
    }

    els.symbolResults.textContent = '';

    if (symbols.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'symbol-search__empty';
        empty.textContent = 'no matches';
        els.symbolResults.appendChild(empty);
        return;
    }

    symbols.forEach((symbol) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'symbol-search__result';
        btn.dataset.symbolId = String(symbol.id);
        btn.dataset.ticker = symbol.ticker;

        const ticker = document.createElement('span');
        ticker.className = 'symbol-search__result-ticker num';
        ticker.textContent = symbol.ticker;

        const name = document.createElement('span');
        name.className = 'symbol-search__result-name';
        name.textContent = symbol.name;

        btn.append(ticker, name);
        els.symbolResults.appendChild(btn);
    });
}

/** @param {MouseEvent} event */
function onSymbolResultClick(event) {
    const target = /** @type {HTMLElement} */ (event.target);
    const btn = target.closest('.symbol-search__result');

    if (!(btn instanceof HTMLButtonElement) || !btn.dataset.symbolId || !btn.dataset.ticker) {
        return;
    }

    selectedSymbol = { id: Number(btn.dataset.symbolId), ticker: btn.dataset.ticker };

    if (els?.symbolInput) {
        els.symbolInput.value = btn.dataset.ticker;
    }

    if (els?.symbolResults) {
        els.symbolResults.textContent = '';
    }
}

/** @param {SubmitEvent} event */
async function onSubmit(event) {
    event.preventDefault();
    hideError();

    if (!selectedSymbol) {
        showError('pick a symbol to continue.');
        return;
    }

    const threshold = els?.thresholdInput?.value.trim() ?? '';

    if (threshold === '') {
        showError('enter a threshold.');
        return;
    }

    if (els?.submitBtn) {
        els.submitBtn.disabled = true;
    }

    try {
        const response = await post('/api/alert-rules', {
            symbol_id: selectedSymbol.id,
            metric: els?.metricSelect?.value,
            condition: els?.conditionSelect?.value,
            threshold,
        });

        renderRule(response.data);
        closeForm();
    } catch (error) {
        console.error('[alerts] create rule failed', error);
        showError('could not create the rule — check the fields and try again.');
    } finally {
        if (els?.submitBtn) {
            els.submitBtn.disabled = false;
        }
    }
}

/** @param {string} message */
function showError(message) {
    if (els?.formError) {
        els.formError.textContent = message;
        els.formError.hidden = false;
    }
}

function hideError() {
    if (els?.formError) {
        els.formError.hidden = true;
        els.formError.textContent = '';
    }
}

// --- Fired-events log ---------------------------------------------------

async function loadFired() {
    if (!els?.firedLog) {
        return;
    }

    const response = await get(`/api/alert-events?limit=${FIRED_LIMIT}`);

    els.firedLog.textContent = '';
    firedRowCount = 0;
    // Already newest-first (AlertEventController orders by fired_at desc).
    response.data.forEach((event) => appendFired(event, { prepend: false }));
}

/**
 * @param {{rule_id: number, ticker: string, metric: string, condition: string, threshold: string, price: string, fired_at: string}} event
 */
function prependFired(event) {
    appendFired(event, { prepend: true });
}

/**
 * @param {{ticker: string, metric: string, condition: string, threshold: string, price: string, fired_at: string}} event
 * @param {{prepend: boolean}} options
 */
function appendFired(event, { prepend }) {
    if (!els?.firedLog) {
        return;
    }

    const li = buildFiredRow(event);

    if (prepend) {
        els.firedLog.prepend(li);
    } else {
        els.firedLog.appendChild(li);
    }

    firedRowCount += 1;

    // A live tail, capped at the same limit the initial load asked for —
    // otherwise a long session accumulates an unbounded log in the DOM.
    while (firedRowCount > FIRED_LIMIT && els.firedLog.lastElementChild) {
        els.firedLog.lastElementChild.remove();
        firedRowCount -= 1;
    }
}

/**
 * @param {{ticker: string, metric: string, condition: string, threshold: string, price: string, fired_at: string}} event
 */
function buildFiredRow(event) {
    const li = document.createElement('li');
    li.className = 'fired-row';

    const meta = document.createElement('div');
    meta.className = 'fired-row__meta';

    const ts = document.createElement('span');
    ts.className = 'fired-row__ts num';
    ts.textContent = formatTimestamp(event.fired_at);

    const ticker = document.createElement('span');
    ticker.className = 'fired-row__ticker num';
    ticker.textContent = event.ticker;

    meta.append(ts, ticker);

    const detail = document.createElement('div');
    detail.className = 'fired-row__detail num';
    detail.textContent = firedDetail(event, decimalsByTicker.get(event.ticker) ?? 2);

    li.append(meta, detail);

    return li;
}
