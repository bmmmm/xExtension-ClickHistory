'use strict';

// The history page's "Copy link" button. The address it copies is already
// stripped of tracking parameters by the time it reaches the page
// (ClickHistoryExtension::cleanUrl), so this only puts it on the clipboard and
// says for a moment whether that worked.
(function () {
	const RESET_AFTER_MS = 2000;

	// `clipboard` is navigator.clipboard, which a page outside a secure context
	// (plain http on anything but localhost) does not have at all: that is a
	// failure to report like a refused write, not an exception in the console.
	// writeText() is called synchronously, inside the click handler, because
	// Safari only allows it while the user's gesture is still being handled.
	function copyFromButton(button, clipboard, schedule) {
		// The label is kept the first time round, so a second click while the
		// first answer is still showing does not make "Copied" the label.
		if (button.dataset.label === undefined) {
			button.dataset.label = button.textContent;
		}
		function show(text) {
			button.textContent = text;
			schedule(function () {
				button.textContent = button.dataset.label;
			}, RESET_AFTER_MS);
		}
		let written;
		try {
			written = clipboard.writeText(button.dataset.url);
		} catch (error) {
			written = Promise.reject(error);
		}
		return Promise.resolve(written).then(function () {
			show(button.dataset.copied);
		}, function () {
			show(button.dataset.failed);
		});
	}

	// One listener for the whole page rather than one per button. The clipboard
	// is looked up per click and passed in, like the timer, so that
	// tests/history.test.js can run this against the page's real markup.
	function listen(root, clipboardOf, schedule) {
		root.addEventListener('click', function (ev) {
			const target = ev.target;
			const button = target && typeof target.closest === 'function' ? target.closest('.click-history-copy') : null;
			if (button !== null) {
				copyFromButton(button, clipboardOf(), schedule);
			}
		});
	}

	// Under the test runner there is no document; see tests/history.test.js.
	if (typeof document === 'undefined') {
		module.exports = {
			copyFromButton: copyFromButton,
			listen: listen,
		};
		return;
	}

	listen(document, function () {
		return navigator.clipboard;
	}, window.setTimeout.bind(window));
})();
