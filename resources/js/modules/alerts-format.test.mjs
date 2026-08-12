import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatThreshold, metricLabel, conditionSymbol, conditionText, firedDetail } from './alerts-format.js';

test('formatThreshold shows no sign on a non-negative value', () => {
    assert.equal(formatThreshold('230', 2), '230.00');
    assert.equal(formatThreshold(0, 2), '0.00');
});

test('formatThreshold uses a real minus sign on a negative value', () => {
    // U+2212, not a hyphen — same rule format.js's signed() follows.
    assert.equal(formatThreshold('-2', 2), '−2.00');
    assert.ok(formatThreshold(-2, 2).startsWith('−'));
});

test('formatThreshold pads to the requested precision', () => {
    assert.equal(formatThreshold('1.075', 5), '1.07500');
});

test('metricLabel renders change_pct as change%', () => {
    assert.equal(metricLabel('change_pct'), 'change%');
    assert.equal(metricLabel('price'), 'price');
});

test('conditionSymbol renders above/below as > and <', () => {
    assert.equal(conditionSymbol('above'), '>');
    assert.equal(conditionSymbol('below'), '<');
});

test('conditionText renders a price rule at the symbol\'s own precision', () => {
    assert.equal(
        conditionText({ metric: 'price', condition: 'above', threshold: '230.00000000' }, 2),
        'price > 230.00',
    );
});

test('conditionText renders a change_pct rule at two places regardless of the symbol precision', () => {
    assert.equal(
        conditionText({ metric: 'change_pct', condition: 'below', threshold: '-2.00000000' }, 5),
        'change% < −2.00',
    );
});

test('conditionText renders a 5-decimal forex threshold without ragged edges', () => {
    assert.equal(
        conditionText({ metric: 'price', condition: 'below', threshold: '1.07500000' }, 5),
        'price < 1.07500',
    );
});

test('firedDetail appends the fired price after the condition, arrow-separated', () => {
    assert.equal(
        firedDetail({ metric: 'price', condition: 'above', threshold: '230.00000000', price: '230.06000000' }, 2),
        'price > 230.00 → 230.06',
    );
});
