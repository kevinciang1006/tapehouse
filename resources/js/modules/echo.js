import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// laravel-echo's Reverb connector looks for a global Pusher constructor.
window.Pusher = Pusher;

function meta(name) {
    const tag = document.querySelector(`meta[name="${name}"]`);

    return tag ? tag.getAttribute('content') : null;
}

let instance = null;
let tape = null;
let ops = null;

function echo() {
    if (instance === null) {
        instance = new Echo({
            broadcaster: 'reverb',
            key: meta('reverb-key'),
            wsHost: meta('reverb-host'),
            wsPort: Number(meta('reverb-port')),
            wssPort: Number(meta('reverb-port')),
            forceTLS: meta('reverb-scheme') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }

    return instance;
}

// The ONE shared private-tape.{userId} channel. QuotesUpdated and AlertFired
// both broadcast on this same channel (see routes/channels.php), so tape.js
// and alerts.js must share this single subscription rather than each
// opening their own — two subscriptions would double-handle every frame.
//
// Every .listen() call on the returned channel needs a leading dot, e.g.
// .listen('.quotes.updated', ...) / .listen('.alert.fired', ...), because
// the events define custom broadcastAs() names.
export function tapeChannel() {
    if (tape === null) {
        const userId = meta('user-id');
        tape = echo().private(`tape.${userId}`);
    }

    return tape;
}

// The shared private-ops channel. FeedStateChanged broadcasts here as
// .listen('.feed.state', ...).
export function opsChannel() {
    if (ops === null) {
        ops = echo().private('ops');
    }

    return ops;
}

// Fires whenever the underlying Pusher/Reverb connection reaches the
// "connected" state — including after a drop and reconnect — so tape.js can
// refetch its snapshot to close the gap left by whatever it missed while
// disconnected.
export function onReconnect(cb) {
    echo().connector.pusher.connection.bind('connected', cb);
}
