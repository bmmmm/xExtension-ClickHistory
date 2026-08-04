'use strict';

// Run with `node --test tests/*.test.js`.
//
// MAIN_LINK_SELECTOR and articleOfMainLink() are the one place where this
// extension reaches into markup it does not own. There is no hook for "the user
// opened this article", so the three elements core renders the article's own link
// into are matched by their classes — and if a future release renames one of them,
// clicks through it stop being recorded without anything failing.
//
// The fixtures below are copied out of the FreshRSS 1.29.0 views, with the PHP
// evaluated by hand and the parts that do not affect matching cut away. The file
// each one comes from is named above it, so re-checking against a newer release is
// a diff rather than an investigation.
//
// linkedom rather than a hand-rolled stub: closest() against a real selector list
// is exactly what is under test here, so faking it would test the fake.
const test = require('node:test');
const assert = require('node:assert/strict');
const { parseHTML } = require('linkedom');

const { MAIN_LINK_SELECTOR, articleOfMainLink } = require('../static/script.js');

const ENTRY_ID = '1700000000000001';

// app/views/index/normal.phtml — the article wrapper, with the header, the body
// and the bottom row of the two helpers inside it. Everything a click can land on
// sits somewhere in here.
const NORMAL_VIEW = `
<div class="flux not_read" id="flux_${ENTRY_ID}" data-entry="${ENTRY_ID}"
	data-category="2" data-feed="7" data-priority="10" data-link="https://example.org/article">

	<!-- app/views/helpers/index/normal/entry_header.phtml -->
	<ul class="horizontal-list flux_header websitename" data-website-name="Example feed" data-article-authors="A · B">
		<li class="item manage"><a class="item-element read" href="./?c=entry&amp;a=read&amp;id=${ENTRY_ID}" title="Mark as read">read</a></li>
		<li class="item manage"><a class="item-element bookmark" href="./?c=entry&amp;a=bookmark&amp;id=${ENTRY_ID}" title="Favourite">star</a></li>
		<li class="item website name">
			<a href="./?get=f_7" class="item-element" title="Filter: Example feed"><span class="websiteName">Example feed</span></a>
		</li>
		<li class="item titleAuthorSummaryDate">
			<a target="_blank" rel="noreferrer" href="https://example.org/article" class="item-element title" dir="auto">The headline</a>
			<span class="item-element date"><time datetime="2026-08-04T09:00:00+00:00">4 Aug</time>&nbsp;</span>
		</li>
		<li class="item share">
			<div class="item-element dropdown">
				<div id="dropdown-share2-${ENTRY_ID}" class="dropdown-target"></div>
				<a class="dropdown-toggle" href="#dropdown-share2-${ENTRY_ID}" title="Share">share</a>
			</div>
		</li>
		<li class="item link"><a target="_blank" rel="noreferrer" href="https://example.org/article" class="item-element" title="See on website">link</a></li>
	</ul>

	<article class="flux_content" dir="auto">
		<div class="content content_thin">
			<header>
				<div class="website"><a href="./?get=f_7" title="Filter"><span>Example feed</span></a></div>
				<h1 class="title"><a target="_blank" rel="noreferrer" class="go_website"
					href="https://example.org/article" title="See on website">The headline</a></h1>
			</header>
			<div class="text">
				<p>Body text with <a href="https://example.org/inside">a link the feed put there</a>.</p>
			</div>
		</div>
		<footer>
			<!-- app/views/helpers/index/normal/entry_bottom.phtml -->
			<ul class="horizontal-list bottom">
				<li class="item manage"><a class="item-element read" href="./?c=entry&amp;a=read&amp;id=${ENTRY_ID}" title="Mark as read">read</a></li>
				<li class="item tags">
					<div class="item-element dropdown">
						<a class="dropdown-toggle" href="#dropdown-tags-${ENTRY_ID}">tag<span class="dropdown-label">Related tags</span></a>
						<ul class="dropdown-menu">
							<li class="dropdown-header">Related tags</li>
							<li class="item"><a href="./?search=%23news">news</a></li>
						</ul>
					</div>
				</li>
				<li class="item share"></li>
				<li class="item date"><time datetime="2026-08-04T09:00:00+00:00" class="item-element">4 Aug</time>&nbsp;</li>
				<li class="item link"><a target="_blank" class="item-element" rel="noreferrer"
					href="https://example.org/article" title="See on website">link</a></li>
			</ul>
		</footer>
	</article>
</div>`;

// app/views/helpers/index/article.phtml, as the reading view renders it inside
// the .flux wrapper of app/views/index/reader.phtml. The topline link sits in a
// <div class="item link"> here rather than an <li>, which is why the selector
// keys on the classes and not on the element.
const READER_VIEW = `
<div class="flux current" id="flux_${ENTRY_ID}" data-entry="${ENTRY_ID}" data-link="https://example.org/article">
	<article class="flux_content content_thin" dir="auto">
		<div class="content">
			<header>
				<div class="article-header-topline horizontal-list">
					<div class="item manage"><a class="read" href="./?c=entry&amp;a=read&amp;id=${ENTRY_ID}" title="Mark as read">read</a></div>
					<div class="item">
						<a class="website" href="./?c=index&amp;a=reader&amp;get=f_7" title="Filter"><span>Example feed</span></a>
					</div>
					<div class="item link">
						<a target="_blank" rel="noreferrer" href="https://example.org/article" class="item-element" title="See on website">link</a>
					</div>
				</div>
				<h1 class="title"><a target="_blank" rel="noreferrer" class="go_website" href="https://example.org/article">The headline</a></h1>
			</header>
			<div class="text">
				<p>Body text with <a href="https://example.org/inside">a link the feed put there</a>.</p>
			</div>
		</div>
		<footer>
			<ul class="horizontal-list bottom">
				<li class="item share"></li>
				<li class="item link">
					<a target="_blank" rel="noreferrer" href="https://example.org/article" class="item-element" title="See on website">link</a>
				</li>
			</ul>
		</footer>
	</article>
</div>`;

function parse(html) {
	return parseHTML(`<!DOCTYPE html><html><body>${html}</body></html>`).document;
}

// A click event as the delegated listener sees it: only ev.target is read.
function clickOn(element) {
	return { target: element };
}

function only(document, selector) {
	const found = document.querySelectorAll(selector);
	assert.equal(found.length, 1, `expected exactly one ${selector}, found ${found.length}`);
	return found[0];
}

// --- The three elements the selector is meant to cover ------------------------

test('the headline of an expanded article is a main link', () => {
	const document = parse(NORMAL_VIEW);
	const flux = articleOfMainLink(clickOn(only(document, 'h1.title a.go_website')));
	assert.notEqual(flux, null);
	assert.equal(flux.getAttribute('data-entry'), ENTRY_ID);
});

test('the headline of a collapsed list row is a main link', () => {
	const document = parse(NORMAL_VIEW);
	const flux = articleOfMainLink(clickOn(only(document, 'li.titleAuthorSummaryDate > a.title')));
	assert.notEqual(flux, null);
	assert.equal(flux.getAttribute('data-entry'), ENTRY_ID);
});

test('the link icon in the topline row is a main link', () => {
	const document = parse(NORMAL_VIEW);
	const flux = articleOfMainLink(clickOn(only(document, '.flux_header .item.link > a')));
	assert.notEqual(flux, null);
	assert.equal(flux.getAttribute('data-entry'), ENTRY_ID);
});

test('the link icon in the bottom row is a main link', () => {
	const document = parse(NORMAL_VIEW);
	const flux = articleOfMainLink(clickOn(only(document, '.horizontal-list.bottom .item.link > a')));
	assert.notEqual(flux, null);
	assert.equal(flux.getAttribute('data-entry'), ENTRY_ID);
});

// A click usually lands on something inside the link — the icon glyph, a nested
// span — so closest() has to walk up to it rather than matching the target.
test('a click on a node inside the headline still finds the article', () => {
	const document = parse(NORMAL_VIEW);
	const link = only(document, 'h1.title a.go_website');
	const span = document.createElement('span');
	link.appendChild(span);
	assert.notEqual(articleOfMainLink(clickOn(span)), null);
});

// --- The reading view ---------------------------------------------------------

test('the reading view headline and both link icons are main links', () => {
	const document = parse(READER_VIEW);
	for (const selector of [
		'h1.title a.go_website',
		'.article-header-topline .item.link > a',
		'.horizontal-list.bottom .item.link > a',
	]) {
		const flux = articleOfMainLink(clickOn(only(document, selector)));
		assert.notEqual(flux, null, selector);
		assert.equal(flux.getAttribute('data-entry'), ENTRY_ID, selector);
	}
});

// --- Everything else in the same article --------------------------------------
// "Only the article's own link" is what the README promises. These are the other
// links core puts inside the same .flux.

test('none of the other links in the article count', () => {
	const document = parse(NORMAL_VIEW);
	for (const selector of [
		'.item.manage > a.read',
		'.item.manage > a.bookmark',
		'.item.website a',
		'.item.share a.dropdown-toggle',
		'.item.tags a.dropdown-toggle',
		'.dropdown-menu .item a',
		'header .website a',
	]) {
		const element = document.querySelector(selector);
		assert.notEqual(element, null, `fixture has no ${selector}`);
		assert.equal(articleOfMainLink(clickOn(element)), null, selector);
	}
});

test('a link the feed put in the article body does not count', () => {
	const document = parse(NORMAL_VIEW);
	assert.equal(articleOfMainLink(clickOn(only(document, '.text a'))), null);
});

// The one case that is not merely a miss but an attempt: a feed controls the
// article body, so it can put core's own class on a link of its own. The `.text`
// check is what stops that from writing a history entry — for an article the user
// never opened, pointing at a URL the feed chose.
test('a forged go_website link inside the article body does not count', () => {
	const document = parse(NORMAL_VIEW);
	const body = only(document, '.text');
	body.innerHTML = '<p>Body <a class="go_website" target="_blank" href="https://attacker.example/">click me</a></p>';
	const forged = body.querySelector('a.go_website');
	assert.notEqual(forged, null);
	// It matches the selector — that is the point — and is rejected anyway.
	assert.notEqual(forged.closest(MAIN_LINK_SELECTOR), null);
	assert.equal(articleOfMainLink(clickOn(forged)), null);
});

test('a forged list-row headline inside the article body does not count', () => {
	const document = parse(NORMAL_VIEW);
	const body = only(document, '.text');
	body.innerHTML = '<ul><li class="item link"><a target="_blank" class="item-element title" href="https://attacker.example/">x</a></li></ul>';
	assert.equal(articleOfMainLink(clickOn(body.querySelector('a'))), null);
});

// --- Outside an article -------------------------------------------------------

test('a link outside any .flux is not a main link', () => {
	const document = parse('<nav class="nav_menu"><a class="btn go_website" target="_blank" href="https://example.org/">x</a></nav>');
	assert.equal(articleOfMainLink(clickOn(only(document, 'a'))), null);
});

test('a target without closest() is ignored rather than throwing', () => {
	assert.equal(articleOfMainLink({ target: {} }), null);
});

// --- The selector itself ------------------------------------------------------
// Held separately from articleOfMainLink() so that a rename in core shows up as
// "this element is no longer matched" rather than as a wrong article id.

test('the selector names exactly the three core elements', () => {
	assert.equal(MAIN_LINK_SELECTOR,
		'a.go_website, .item.link > a[target="_blank"], li.item > a.item-element.title[target="_blank"]');
});

test('every main link in the fixtures matches the selector and nothing else does', () => {
	for (const [name, html] of [['normal', NORMAL_VIEW], ['reader', READER_VIEW]]) {
		const document = parse(html);
		const matched = [...document.querySelectorAll(MAIN_LINK_SELECTOR)];
		assert.ok(matched.length > 0, name);
		for (const link of matched) {
			assert.equal(link.getAttribute('href'), 'https://example.org/article', `${name}: ${link.outerHTML}`);
		}
	}
});
