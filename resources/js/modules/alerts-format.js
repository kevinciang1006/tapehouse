// Pure formatting helpers for the alerts panel. Split out from alerts.js the
// same way ops-format.js was split from ops.js — so this logic is unit
// testable directly, without pulling in echo.js's window.Pusher side effect
// or a DOM.

const MINUS = '−';

/**
 * A threshold or fired price rendered to a fixed number of places with a
 * real minus sign on a negative value and no leading sign on a positive one
 * — distinct from format.js's signed(), which always shows a '+' on a
 * non-negative delta. A threshold is a bound, not a change.
 *
 * @param {string|number} value
 * @param {number} decimals
 * @returns {string}
 */
export function formatThreshold(value, decimals) {
    const n = Number(value);
    const sign = n < 0 ? MINUS : '';

    return `${sign}${Math.abs(n).toFixed(decimals)}`;
}

/**
 * @param {string} metric - 'price' | 'change_pct'
 * @returns {string}
 */
export function metricLabel(metric) {
    return metric === 'change_pct' ? 'change%' : 'price';
}

/**
 * @param {string} condition - 'above' | 'below'
 * @returns {string}
 */
export function conditionSymbol(condition) {
    return condition === 'below' ? '<' : '>';
}

/**
 * Renders a rule (or a fired event, which carries the same metric/condition/
 * threshold fields) in operator register: `price > 230.00` or
 * `change% < -2.00`. change% thresholds are always 2 decimal places — a
 * percentage point, not a symbol price; price thresholds take the symbol's
 * own price_decimals so a forex pair's threshold does not ragged-edge
 * against its own quoted price.
 *
 * @param {{metric: string, condition: string, threshold: string|number}} rule
 * @param {number} priceDecimals
 * @returns {string}
 */
export function conditionText(rule, priceDecimals) {
    const decimals = rule.metric === 'change_pct' ? 2 : priceDecimals;

    return `${metricLabel(rule.metric)} ${conditionSymbol(rule.condition)} ${formatThreshold(rule.threshold, decimals)}`;
}

/**
 * A fired-log detail line: the condition that fired, plus the price the
 * event recorded. AlertEvent always stores the underlying price at fire
 * time (see App\Jobs\EvaluateAlerts), never the observed change% for a
 * change_pct rule — there is no separate "observed value" field to arrow to
 * — so the trailing number is always formatted as a price, not re-derived
 * as a percentage.
 *
 * @param {{metric: string, condition: string, threshold: string|number, price: string|number}} event
 * @param {number} priceDecimals
 * @returns {string}
 */
export function firedDetail(event, priceDecimals) {
    return `${conditionText(event, priceDecimals)} → ${formatThreshold(event.price, priceDecimals)}`;
}
