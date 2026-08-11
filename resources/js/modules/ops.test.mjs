import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mmss, driverLabel, creditsLabel, formatTimestamp } from './ops-format.js';

test('mmss always shows a minutes component, even at zero', () => {
    // The driver/credits rows want a fixed "0m41s" shape, unlike
    // format.js's age(), which drops the minutes under a minute.
    assert.equal(mmss(41), '0m41s');
    assert.equal(mmss(0), '0m00s');
});

test('mmss pads seconds and lets minutes grow unpadded', () => {
    assert.equal(mmss(843), '14m03s');
    assert.equal(mmss(252), '4m12s');
});

test('mmss floors fractional seconds and never goes negative', () => {
    assert.equal(mmss(41.9), '0m41s');
    assert.equal(mmss(-5), '0m00s');
});

test('driverLabel joins the driver name and time in state', () => {
    assert.equal(driverLabel('polling', 41), 'polling · 0m41s');
    assert.equal(driverLabel('websocket', 252), 'websocket · 4m12s');
});

test('creditsLabel renders the fraction plus a refill countdown', () => {
    assert.equal(creditsLabel(5, 8, 843), '5/8 · refill 14m03s');
});

test('formatTimestamp renders a zero-padded HH:MM:SS', () => {
    assert.match(formatTimestamp('2026-08-11T12:04:11Z'), /^\d{2}:\d{2}:\d{2}$/);
});
