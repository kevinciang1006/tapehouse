import '../scss/app.scss';
import * as tape from './modules/tape.js';
import * as ops from './modules/ops.js';
import * as watchlist from './modules/watchlist.js';
import * as alerts from './modules/alerts.js';

// The login screen shares this same bundle (one entry, no code-splitting),
// so every panel module boots defensively off the DOM element it owns
// rather than assuming it is running on the console page.
if (document.getElementById('tape-rows')) {
    tape.mount().catch((error) => {
        console.error('[tape] mount failed', error);
    });

    // Depends on tape.js's addSymbol()/removeSymbol() exports, so it mounts
    // after tape.js is asked to (both are async and DOM-driven; there is no
    // ordering hazard between the two mounts themselves).
    watchlist.mount().catch((error) => {
        console.error('[watchlist] mount failed', error);
    });
}

if (document.getElementById('driver-dot')) {
    ops.mount().catch((error) => {
        console.error('[ops] mount failed', error);
    });
}

if (document.getElementById('alerts-view')) {
    alerts.mount().catch((error) => {
        console.error('[alerts] mount failed', error);
    });
}
