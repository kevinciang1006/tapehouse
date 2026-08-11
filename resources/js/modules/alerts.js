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
//
// jQuery: the rule form's bindings and the rule-toggle's click handling go
// through jQuery here, same split as watchlist.js — low-frequency,
// form-adjacent interactions where $.fn reads naturally, the toggle bound
// once via $(document).on('click', '.rule-toggle', …) so every row created
// by renderRule() (now or later) is covered without a per-row listener.
// tape.js's per-quote cell patching stays vanilla DOM on purpose: it runs on
// every tick for every row on screen, and wrapping that hot path in jQuery
// would cost real work for no benefit.

import $ from 'jquery';
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
        rulesEmpty: document.getElementById('alert-rules-empty'),
        firedLog: document.getElementById('alert-fired-log'),
        firedEmpty: document.getElementById('alert-fired-empty'),
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
    syncRulesEmpty();
}

// Same idea as tape.js's syncListMeta(): shown in place of #alert-rules'
// content, not alongside it, so no rules and a failed load can't be
// mistaken for one another.
function syncRulesEmpty() {
    if (els?.rulesEmpty) {
        els.rulesEmpty.hidden = rulesById.size > 0;
    }
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
    syncRulesEmpty();

    // No per-row listener: the click is caught by the delegated
    // $(document).on('click', '.rule-toggle', …) bound once in wireForm().
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
    $(els?.createBtn).on('click', openForm);
    $(els?.cancelBtn).on('click', closeForm);
    $(els?.form).on('submit', onSubmit);
    $(els?.symbolInput).on('input', onSymbolInput);
    $(els?.symbolResults).on('click', '.symbol-search__result', onSymbolResultClick);

    // Delegated from `document`, not `els.rulesList`: renderRule() can run
    // any time after mount (a fresh rule created through this same form
    // lands in the list immediately), so a listener bound once up front
    // covers every row that exists now and every row that will exist,
    // rather than renderRule() having to bind one per toggle it creates.
    $(document).on('click', '.rule-toggle', onRuleToggleClick);
}

/** @param {JQuery.ClickEvent} event */
function onRuleToggleClick(event) {
    const row = /** @type {HTMLElement} */ (event.currentTarget).closest('.rule-row');
    const ruleId = row instanceof HTMLElement ? Number(row.dataset.ruleId) : NaN;

    if (Number.isFinite(ruleId)) {
        onToggleClick(ruleId);
    }
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

    const $results = $(els.symbolResults).empty();

    if (symbols.length === 0) {
        $results.append($('<div>').addClass('symbol-search__empty').text('no matches'));
        return;
    }

    symbols.forEach((symbol) => {
        const $btn = $('<button>')
            .attr('type', 'button')
            .addClass('symbol-search__result')
            .attr('data-symbol-id', String(symbol.id))
            .attr('data-ticker', symbol.ticker)
            .append($('<span>').addClass('symbol-search__result-ticker num').text(symbol.ticker))
            .append($('<span>').addClass('symbol-search__result-name').text(symbol.name));

        $results.append($btn);
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
    syncFiredEmpty();
}

// Same idea as syncRulesEmpty() above — a bare log and a failed fetch both
// render as blank space otherwise.
function syncFiredEmpty() {
    if (els?.firedEmpty) {
        els.firedEmpty.hidden = firedRowCount > 0;
    }
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
    syncFiredEmpty();

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
