'use strict';

// "Read" in FreshRSS only means an article scrolled past. Which ones were
// actually opened leaves no trace at all — the browser history has the URLs but
// not the feed or the headline. This listens for the one gesture that means
// "I opened this" and posts the entry id; everything else happens server-side.
(function () {
	// The article's own link appears in up to three places, and all three are in
	// the header or footer chrome — never inside the article text, so "only the
	// main link" holds structurally, without comparing hrefs.
	//
	//   a.go_website                              the headline of an expanded
	//                                             article (index/normal.phtml,
	//                                             helpers/index/article.phtml)
	//   .item.link > a[target=_blank]             the link icon in the footer and
	//                                             in the topline row
	//   li.item > a.item-element.title[…]         the headline in the collapsed
	//                                             list row, which carries neither
	//                                             of the two markers above
	//                                             (helpers/index/normal/entry_header.phtml)
	const MAIN_LINK_SELECTOR = [
		'a.go_website',
		'.item.link > a[target="_blank"]',
		'li.item > a.item-element.title[target="_blank"]',
	].join(', ');

	function extensionVars() {
		const vars = window.context && window.context.extensions;
		return (vars && vars.click_history) || {};
	}

	// Keeps one entry from being posted twice within a page load. The server-side
	// upsert is what actually guarantees one row per article — across tabs and
	// sessions this cannot see anything — so this is only here to save requests.
	function createOncePerLoadGuard() {
		const sent = new Set();
		return function (entryId) {
			if (entryId === '' || sent.has(entryId)) {
				return false;
			}
			// Filled synchronously, before the request goes out: two events in the
			// same tick would otherwise both pass.
			sent.add(entryId);
			return true;
		};
	}

	// The core's `go_website` shortcut calls window.open() directly and fires no
	// click event, so the only way to see it is to watch the same key and repeat
	// the core's own guards (p/scripts/main.js, init_shortcuts). Never calls
	// preventDefault: this reads along, it does not take the key over.
	function shouldHandleGoWebsite(ev, shortcuts, hash) {
		if (ev.ctrlKey || ev.metaKey || ev.altKey || ev.shiftKey) {
			return false;
		}
		if (ev.target && ev.target.closest && ev.target.closest('input, select, textarea')) {
			return false;
		}
		const wanted = (shortcuts && shortcuts.go_website) || '';
		if (wanted === '') {
			return false;
		}
		const key = ((ev.key || '').trim() || ev.code || 'Space').toUpperCase();
		// While a dropdown is open the core reads a digit as a menu choice and
		// never reaches its go_website branch, so neither do we.
		if (/^#dropdown-/.test(hash || '') && Number.isInteger(parseInt(key, 10))) {
			return false;
		}
		return key === wanted.toUpperCase();
	}

	// The article a click belongs to, or null if the click was not on one of the
	// main links. `.text` is checked as well: a feed controls the article body and
	// could put a `go_website` class on a link of its own.
	function articleOfMainLink(ev) {
		const flux = ev.target.closest && ev.target.closest('.flux');
		if (!flux) {
			return null;
		}
		const link = ev.target.closest(MAIN_LINK_SELECTOR);
		if (!link || !flux.contains(link) || link.closest('.text') !== null) {
			return null;
		}
		return flux;
	}

	function record(flux, guard) {
		// Read at click time rather than at startup: the script is loaded
		// asynchronously, so at startup the global context may not have arrived
		// yet and the setting would fall back to the wrong value.
		const vars = extensionVars();
		if (vars.track_clicks === false || !vars.record_url) {
			return;
		}
		const entryId = flux.getAttribute('data-entry') || '';
		if (!guard(entryId)) {
			return;
		}

		// The same shape the core posts for mark_favorite(): FreshRSS merges a
		// JSON body into $_POST before the CSRF check runs, so the token has to
		// travel inside it. keepalive covers the moment the tab is closed right
		// after the click — the links open in a new tab, so this is a corner case
		// rather than the rule.
		fetch(vars.record_url, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/json; charset=utf-8' },
			body: JSON.stringify({
				ajax: true,
				_csrf: (window.context && window.context.csrf) || '',
				id: entryId,
			}),
		}).then(function (response) {
			// fetch() only rejects when the request never completed, so without this
			// a 400, a 404, a 500 or a redirect to the login page all look exactly
			// like a recorded click — the one failure mode this extension has, and
			// the console would say nothing about it.
			if (!response.ok) {
				console.error('Click history: recording entry ' + entryId + ' failed with HTTP ' + response.status);
			}
		}).catch(console.error);
		// Errors stay in the console on purpose. A passive history must never
		// interrupt what the user was actually doing.
	}

	function init() {
		const guard = createOncePerLoadGuard();

		// One delegated listener rather than a MutationObserver: articles loaded
		// later need no preparation, since nothing is injected into them.
		//
		// click and auxclick are mutually exclusive for one and the same gesture
		// (auxclick is the middle button), so this cannot double-count; the guard
		// above covers the odd browser that disagrees.
		document.addEventListener('click', function (ev) {
			const flux = articleOfMainLink(ev);
			if (flux !== null) {
				record(flux, guard);
			}
		});
		document.addEventListener('auxclick', function (ev) {
			if (ev.button === 1) {
				const flux = articleOfMainLink(ev);
				if (flux !== null) {
					record(flux, guard);
				}
			}
		});

		document.addEventListener('keydown', function (ev) {
			const shortcuts = window.context && window.context.shortcuts;
			if (!shouldHandleGoWebsite(ev, shortcuts, window.location.hash)) {
				return;
			}
			// The core opens the site only if this link exists, so its absence
			// means nothing was opened and nothing should be recorded.
			const link = document.querySelector('.flux.current a.go_website');
			if (link !== null) {
				const flux = link.closest('.flux');
				if (flux !== null) {
					record(flux, guard);
				}
			}
		});
	}

	// Under the test runner there is no document and only the pure helpers are
	// exported; see tests/click-detection.test.js.
	if (typeof document === 'undefined') {
		module.exports = {
			createOncePerLoadGuard: createOncePerLoadGuard,
			shouldHandleGoWebsite: shouldHandleGoWebsite,
		};
		return;
	}

	init();
})();
