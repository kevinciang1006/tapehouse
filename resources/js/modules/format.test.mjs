import { test } from 'node:test';
import assert from 'node:assert/strict';
import { price, signed, percent, age } from './format.js';

test('splits a price into integer and fraction parts', () => {
    assert.deepEqual(price('228.41', 2), { int: '228', frac: '.41' });
});

test('groups thousands in the integer part', () => {
    // Crypto renders with separators; the integer span is right-aligned so the
    // decimal stays on the same x across every row.
    assert.deepEqual(price('94102.50', 2), { int: '94,102', frac: '.50' });
});

test('pads the fraction to the symbol precision', () => {
    // A forex pair quoting 1.08 must render 1.08000, or the decimal column
    // ragged-edges against its neighbours.
    assert.deepEqual(price('1.08', 5), { int: '1', frac: '.08000' });
});

test('does not round away precision it was given', () => {
    assert.deepEqual(price('1.08234', 5), { int: '1', frac: '.08234' });
});

test('renders XAU/USD at two places even though it is a forex pair', () => {
    assert.deepEqual(price('2411.88', 2), { int: '2,411', frac: '.88' });
});

test('handles a null or empty price without throwing', () => {
    assert.deepEqual(price(null, 2), { int: '—', frac: '' });
    assert.deepEqual(price('', 2), { int: '—', frac: '' });
});

test('signs a change with an explicit plus or minus', () => {
    assert.equal(signed('1.82', 2), '+1.82');
    assert.equal(signed('-0.00041', 5), '−0.00041');
    assert.equal(signed('0', 2), '+0.00');
});

test('uses a real minus sign, not a hyphen', () => {
    // U+2212. A hyphen is narrower and breaks the tabular column.
    assert.ok(signed('-1.5', 2).startsWith('−'));
});

test('formats a percentage to two places with a sign', () => {
    assert.equal(percent('0.80'), '+0.80%');
    assert.equal(percent('-2.14'), '−2.14%');
});

test('formats age in seconds under a minute', () => {
    assert.equal(age(0), '0s');
    assert.equal(age(59), '59s');
});

test('formats age in minutes and padded seconds past a minute', () => {
    assert.equal(age(74), '1m14s');
    assert.equal(age(3600), '60m00s');
});
