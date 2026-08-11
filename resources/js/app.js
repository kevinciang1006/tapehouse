import '../scss/app.scss';
import * as tape from './modules/tape.js';

// The login screen shares this same bundle (one entry, no code-splitting),
// so every panel module boots defensively off the DOM element it owns
// rather than assuming it is running on the console page.
if (document.getElementById('tape-rows')) {
    tape.mount().catch((error) => {
        console.error('[tape] mount failed', error);
    });
}

// Later Plan 4 tasks add ops.js (feed control, event log) and watchlist.js /
// alerts.js (add/remove symbols, alert rules, fired log) alongside tape.js
// here.
