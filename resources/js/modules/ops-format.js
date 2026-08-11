// Pure formatting helpers for the ops panel. Split out from ops.js so they
// can be unit tested directly: ops.js also imports echo.js, which sets
// window.Pusher as an import-time side effect and therefore requires a DOM,
// which a plain `node --test` run does not have.

/**
 * mm41s-style duration: minutes always present (even "0m"), seconds
 * zero-padded. Distinct from format.js's age(), which drops the minutes
 * component under 60s — the driver/credits rows want the fixed shape
 * "0m41s" / "14m03s" the design specifies, not a shape that changes width
 * as a value crosses the one-minute mark.
 *
 * @param {number} totalSeconds
 * @returns {string}
 */
export function mmss(totalSeconds) {
    const total = Math.max(0, Math.floor(totalSeconds));
    const m = Math.floor(total / 60);
    const s = String(total % 60).padStart(2, '0');

    return `${m}m${s}s`;
}

/**
 * @param {string} driver
 * @param {number} secondsInState
 * @returns {string}
 */
export function driverLabel(driver, secondsInState) {
    return `${driver} · ${mmss(secondsInState)}`;
}

/**
 * @param {number} available
 * @param {number} capacity
 * @param {number} secondsUntilNext
 * @returns {string}
 */
export function creditsLabel(available, capacity, secondsUntilNext) {
    return `${available}/${capacity} · refill ${mmss(secondsUntilNext)}`;
}

/**
 * HH:MM:SS in the operator's local time, from an ISO-8601 instant.
 *
 * @param {string} isoString
 * @returns {string}
 */
export function formatTimestamp(isoString) {
    const date = new Date(isoString);
    const pad = (/** @type {number} */ n) => String(n).padStart(2, '0');

    return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}
