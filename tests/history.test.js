'use strict';

// Run with `node --test tests/*.test.js`. copyFromButton() itself needs no DOM:
// an object with a dataset and a textContent is all it touches. The click
// listener is run against the rendered markup further down, with linkedom.
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

// --- Against the page's own markup -------------------------------------------
// tests/fixtures/history-actions.html is the actions cell exactly as
// views/clickhistory/index.phtml renders it (tests/index.php keeps the two in
// step), so a renamed class or data attribute on either side fails here rather
// than leaving a button that does nothing. The translations are the stub's
// keys. linkedom, because closest() and a bubbling click are what is under test.
const fs = require('node:fs');
const path = require('node:path');
const { parseHTML } = require('linkedom');
const { listen } = require('../static/history.js');

function page(refuse) {
	const cell = fs.readFileSync(path.join(__dirname, 'fixtures', 'history-actions.html'), 'utf8');
	const { document, window } = parseHTML(`<!doctype html><html><body><table><tbody><tr>${cell}</tr></tbody></table></body></html>`);
	const written = [];
	const clipboard = {
		writeText: (text) => {
			written.push(text);
			return refuse ? Promise.reject(new Error('NotAllowedError')) : Promise.resolve();
		},
	};
	listen(document, () => clipboard, scheduler());
	const click = (element) => element.dispatchEvent(new window.Event('click', { bubbles: true }));
	return { document, written, click };
}

// The answer to a click arrives a promise later.
const settled = () => new Promise((resolve) => setImmediate(resolve));

test('clicking the rendered copy button copies its cleaned address and says so', async () => {
	const { document, written, click } = page();
	const button = document.querySelector('button[type="button"]');
	assert.ok(button !== null, 'the fixture has a copy button');
	click(button);
	await settled();
	assert.deepEqual(written, ['https://example.org/c?id=7#top']);
	assert.equal(button.textContent, 'ext.click_history.action.copied');
});

test('a refused copy on the rendered button shows the rendered failure text', async () => {
	const { document, click } = page(true);
	const button = document.querySelector('button[type="button"]');
	click(button);
	await settled();
	assert.equal(button.textContent, 'ext.click_history.action.copy_failed');
});

test('the rating and delete buttons in the same cell copy nothing', async () => {
	const { document, written, click } = page();
	const submits = document.querySelectorAll('button[type="submit"]');
	assert.equal(submits.length, 3);
	submits.forEach(click);
	await settled();
	assert.deepEqual(written, []);
});
