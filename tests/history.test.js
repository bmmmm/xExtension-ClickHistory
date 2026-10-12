'use strict';

// Run with `node --test tests/*.test.js`. The copy button needs no DOM: an
// object with a dataset and a textContent is all copyFromButton() touches.
const test = require('node:test');
const assert = require('node:assert/strict');

const { copyFromButton } = require('../static/history.js');

function button() {
	return {
		textContent: 'Copy link',
		dataset: { url: 'https://example.org/b?id=7', copied: 'Copied', failed: 'Copy failed' },
	};
}

// Collects what would run after the delay instead of waiting for it.
function scheduler() {
	const pending = [];
	const schedule = function (fn, ms) {
		pending.push({ fn: fn, ms: ms });
	};
	schedule.pending = pending;
	return schedule;
}

test('a successful copy writes exactly the cleaned URL and says so', async () => {
	const btn = button();
	const written = [];
	const schedule = scheduler();
	await copyFromButton(btn, { writeText: (text) => { written.push(text); return Promise.resolve(); } }, schedule);
	assert.deepEqual(written, ['https://example.org/b?id=7']);
	assert.equal(btn.textContent, 'Copied');
	assert.equal(schedule.pending.length, 1);
	assert.equal(schedule.pending[0].ms, 2000);
	schedule.pending[0].fn();
	assert.equal(btn.textContent, 'Copy link');
});

test('a refused write says it failed', async () => {
	const btn = button();
	await copyFromButton(btn, { writeText: () => Promise.reject(new Error('NotAllowedError')) }, scheduler());
	assert.equal(btn.textContent, 'Copy failed');
});

test('no clipboard at all (not a secure context) says it failed instead of throwing', async () => {
	const btn = button();
	await copyFromButton(btn, undefined, scheduler());
	assert.equal(btn.textContent, 'Copy failed');
});

test('a second click while the answer is showing still restores the original label', async () => {
	const btn = button();
	const schedule = scheduler();
	const clipboard = { writeText: () => Promise.resolve() };
	await copyFromButton(btn, clipboard, schedule);
	await copyFromButton(btn, clipboard, schedule);
	schedule.pending.forEach((p) => p.fn());
	assert.equal(btn.textContent, 'Copy link');
});
