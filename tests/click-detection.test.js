'use strict';

// Run with `node --test tests/*.test.js`. No dependencies, no framework.
const test = require('node:test');
const assert = require('node:assert/strict');

const { shouldHandleGoWebsite, createOncePerLoadGuard, createRecorder } = require('../static/script.js');

// Enough of a KeyboardEvent for the guards: they read the modifier flags, the
// key, and whether the focus sits in a form field.
function keyEvent(overrides) {
	return Object.assign({
		key: 'K',
		code: 'KeyK',
		ctrlKey: false,
		metaKey: false,
		altKey: false,
		shiftKey: false,
		target: { closest: () => null },
	}, overrides || {});
}

const SHORTCUTS = { go_website: 'K', mark_favorite: 'F' };

// --- The go_website shortcut ------------------------------------------------
// The core opens the site with window.open() and fires no click event, so this
// is the only way to see that gesture. Every guard the core applies has to be
// repeated here, or the history gains entries for keystrokes that opened
// nothing.

test('the configured key without modifiers is the one case that counts', () => {
	assert.equal(shouldHandleGoWebsite(keyEvent(), SHORTCUTS, ''), true);
});

test('the key is matched regardless of case', () => {
	assert.equal(shouldHandleGoWebsite(keyEvent({ key: 'k' }), SHORTCUTS, ''), true);
});

test('another key is not the shortcut', () => {
	assert.equal(shouldHandleGoWebsite(keyEvent({ key: 'F' }), SHORTCUTS, ''), false);
});

// The core returns early on any of these, so no site is opened.
test('a held modifier means the core never opened anything', () => {
	for (const modifier of ['ctrlKey', 'metaKey', 'altKey', 'shiftKey']) {
		const ev = keyEvent();
		ev[modifier] = true;
		assert.equal(shouldHandleGoWebsite(ev, SHORTCUTS, ''), false, modifier);
	}
});

// Typing the letter into the search box must not be read as the shortcut.
test('a keystroke inside a form field is typing, not a shortcut', () => {
	for (const field of ['input', 'select', 'textarea']) {
		const ev = keyEvent({ target: { closest: (sel) => (sel.includes(field) ? {} : null) } });
		assert.equal(shouldHandleGoWebsite(ev, SHORTCUTS, ''), false, field);
	}
});

test('an unassigned or missing shortcut set never matches', () => {
	assert.equal(shouldHandleGoWebsite(keyEvent(), { go_website: '' }, ''), false);
	assert.equal(shouldHandleGoWebsite(keyEvent(), {}, ''), false);
	assert.equal(shouldHandleGoWebsite(keyEvent(), null, ''), false);
});

// With a dropdown open the core reads a digit as a menu choice and never gets
// to its go_website branch — so a history entry there would be for an article
// that was never opened.
test('a digit while a dropdown is open belongs to the dropdown', () => {
	const digits = { go_website: '2' };
	assert.equal(shouldHandleGoWebsite(keyEvent({ key: '2' }), digits, '#dropdown-share2-42'), false);
	assert.equal(shouldHandleGoWebsite(keyEvent({ key: '2' }), digits, ''), true);
	// A letter is not a menu choice, so the dropdown does not swallow it.
	assert.equal(shouldHandleGoWebsite(keyEvent(), SHORTCUTS, '#dropdown-share2-42'), true);
});

// The core falls back to ev.code when ev.key is whitespace (the space bar).
test('the space bar arrives as a code rather than as a key', () => {
	assert.equal(shouldHandleGoWebsite(keyEvent({ key: ' ', code: 'Space' }), { go_website: 'SPACE' }, ''), true);
});

// --- The once-per-page-load guard -------------------------------------------
// Only an optimisation: the server-side upsert is what guarantees one row per
// article. What this must get right is that it cannot let the same id through
// twice, including for two events in the same tick.

test('the first click on an article passes', () => {
	const guard = createOncePerLoadGuard();
	assert.equal(guard('1700000000000001'), true);
});

test('a second click on the same article is held back', () => {
	const guard = createOncePerLoadGuard();
	guard('1700000000000001');
	assert.equal(guard('1700000000000001'), false);
	assert.equal(guard('1700000000000001'), false);
});

test('different articles each pass once', () => {
	const guard = createOncePerLoadGuard();
	assert.equal(guard('1700000000000001'), true);
	assert.equal(guard('1700000000000002'), true);
	assert.equal(guard('1700000000000001'), false);
});

// An article row without a data-entry attribute yields '', and posting that
// would only earn a 400 from the server.
test('an empty id never passes', () => {
	const guard = createOncePerLoadGuard();
	assert.equal(guard(''), false);
	assert.equal(guard(''), false);
});

test('two guards do not share what they have seen', () => {
	const first = createOncePerLoadGuard();
	const second = createOncePerLoadGuard();
	first('1700000000000001');
	assert.equal(second('1700000000000001'), true);
});

// A request that did not get through is forgotten again, so the next click on
// the same article is tried rather than dropped.
test('a released id passes again', () => {
	const guard = createOncePerLoadGuard();
	guard('1700000000000001');
	guard.release('1700000000000001');
	assert.equal(guard('1700000000000001'), true);
	assert.equal(guard('1700000000000001'), false);
});

// --- The recorder ------------------------------------------------------------
// What goes over the wire, and what happens when the answer is not a 200. The
// server-side upsert and the auth gate are core's; this is only the client half.

const ENTRY_ID = '1700000000000001';
const FLUX = { getAttribute: (name) => (name === 'data-entry' ? ENTRY_ID : null) };

// A recorder with every dependency replaced: `calls` collects what fetch was
// asked, `errors` what was logged, `answer` is what fetch resolves to (or, as an
// Error, rejects with).
function recorder(overrides) {
	const calls = [];
	const errors = [];
	const deps = Object.assign({
		vars: () => ({ track_clicks: true, record_url: './?c=clickhistory&a=record' }),
		csrf: () => 'token-123',
		fetch: (url, options) => {
			calls.push({ url, options });
			const answer = deps.answer;
			return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer);
		},
		guard: createOncePerLoadGuard(),
		error: (message) => errors.push(message),
		answer: { ok: true, status: 200, redirected: false },
	}, overrides || {});
	return { record: createRecorder(deps), calls, errors, deps };
}

test('a click posts the entry id and the CSRF token as JSON', async () => {
	const { record, calls, errors } = recorder();
	await record(FLUX);
	assert.equal(calls.length, 1);
	assert.equal(calls[0].url, './?c=clickhistory&a=record');
	assert.equal(calls[0].options.method, 'POST');
	assert.equal(calls[0].options.credentials, 'same-origin');
	assert.equal(calls[0].options.keepalive, true);
	assert.equal(calls[0].options.headers['Content-Type'], 'application/json; charset=utf-8');
	// Core merges a JSON body into $_POST before its CSRF check, so the token has
	// to be in there — and the id travels as a string, past 2^53.
	assert.deepEqual(JSON.parse(calls[0].options.body), { ajax: true, _csrf: 'token-123', id: ENTRY_ID });
	assert.deepEqual(errors, []);
});

test('nothing is posted while recording is switched off', async () => {
	const { record, calls } = recorder({ vars: () => ({ track_clicks: false, record_url: './?c=clickhistory&a=record' }) });
	assert.equal(await record(FLUX), undefined);
	assert.equal(calls.length, 0);
});

test('nothing is posted before the context has arrived', async () => {
	const { record, calls } = recorder({ vars: () => ({}) });
	assert.equal(await record(FLUX), undefined);
	assert.equal(calls.length, 0);
});

test('a recorded article is not posted again in the same page load', async () => {
	const { record, calls } = recorder();
	await record(FLUX);
	await record(FLUX);
	assert.equal(calls.length, 1);
});

// fetch() resolves on a 403 or a 500 just as it does on a 200, so the status has
// to be read — and a click the server refused may be tried again.
test('an HTTP error is logged and the article may be posted again', async () => {
	const { record, calls, errors } = recorder({ answer: { ok: false, status: 403, redirected: false } });
	await record(FLUX);
	assert.equal(errors.length, 1);
	assert.match(errors[0], /1700000000000001 .*HTTP 403/);
	await record(FLUX);
	assert.equal(calls.length, 2);
});

// fetch() follows a redirect, and the login page it lands on answers 200: an
// expired session looks like success unless the redirect itself is noticed.
test('a redirect is a failure, whatever the final status says', async () => {
	const { record, calls, errors } = recorder({ answer: { ok: true, status: 200, redirected: true } });
	await record(FLUX);
	assert.equal(errors.length, 1);
	assert.match(errors[0], /after a redirect/);
	await record(FLUX);
	assert.equal(calls.length, 2);
});

test('a request that never completed is logged and the article may be posted again', async () => {
	const failure = new Error('network down');
	const { record, calls, errors } = recorder({ answer: failure });
	await record(FLUX);
	assert.deepEqual(errors, [failure]);
	await record(FLUX);
	assert.equal(calls.length, 2);
});
