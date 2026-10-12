<?php
declare(strict_types=1);

// The history page, rendered from rows made up here, the way tests/export.php
// renders the export: what a feed puts into a title or a link must come out as
// text, a link that is not http(s) must not become one, and the button that
// deletes the whole history must be there whenever there is a history at all —
// including on a filtered page that shows none of it.
//
// Needs FreshRSS core in .freshrss-core, the same checkout PHPStan uses: the
// template reads ClickHistoryDAO's constants and ClickHistoryExtension's labels,
// and both classes extend core's. Core's request, translation and auth machinery
// is stubbed below.
//
//   php tests/index.php

require_once __DIR__ . '/bootstrap.php';

$minz = __DIR__ . '/../.freshrss-core/lib/Minz';
if (!is_file($minz . '/ModelPdo.php')) {
	fwrite(STDERR, "FreshRSS core is missing: git clone --depth 1 --branch 1.29.0 https://github.com/FreshRSS/FreshRSS .freshrss-core\n");
	exit(1);
}

// Stand-ins for the core classes the template calls statically. Aliased before
// the autoloader below can load the real ones, which need a request and a
// session; Minz/Url.php would also redeclare _url().
final class UrlStub {
	/** @param array{c?:string, a?:string, params?:array<string, string>} $url */
	public static function display(array $url): string {
		return htmlspecialchars('./?' . http_build_query(['c' => $url['c'] ?? '', 'a' => $url['a'] ?? ''] + ($url['params'] ?? [])), ENT_COMPAT, 'UTF-8');
	}
}
final class AuthStub {
	public static function csrfToken(): string {
		return 'token';
	}
}
class_alias(UrlStub::class, 'Minz_Url');
class_alias(AuthStub::class, 'FreshRSS_Auth');

spl_autoload_register(static function (string $class) use ($minz): void {
	if (str_starts_with($class, 'Minz_') && is_file($minz . '/' . substr($class, 5) . '.php')) {
		require_once $minz . '/' . substr($class, 5) . '.php';
	}
});

// Core's helpers, from lib/Minz/Translate.php, lib/Minz/Url.php and lib/lib_rss.php.
// The translation key stands in for the translation.
if (!function_exists('_t')) {
	function _t(string $key, bool|float|int|string ...$args): string {
		return $key;
	}
}
if (!function_exists('_url')) {
	function _url(string $controller, string $action, int|string ...$args): string {
		return './?c=' . $controller . '&amp;a=' . $action;
	}
}
if (!function_exists('timestamptodate')) {
	function timestamptodate(int $t, bool $hour = true): string {
		return date('Y-m-d H:i', $t);
	}
}
if (!function_exists('timestampToMachineDate')) {
	function timestampToMachineDate(int $t): string {
		return date(DATE_ATOM, $t);
	}
}

require_once __DIR__ . '/../extension.php';

/**
 * Stands in for ClickHistoryView: what index.phtml reads, filled the way the
 * controller fills it. The helpers (nav, filters, pagination) are not rendered.
 *
 * @phpstan-import-type ClickHistoryRow from ClickHistoryDAO
 */
final class IndexView {
	/** @var list<ClickHistoryRow> */
	public array $history = [];
	/** @var array<string, int> */
	public array $statusCounts = [];
	public ?string $status = null;
	public bool $byCategory = false;
	public int $currentPage = 1;
	public int $nbPage = 1;

	/** @return array<string, string> */
	public function carriedParams(): array {
		return array_filter([
			'group' => $this->byCategory ? 'category' : null,
			'status' => $this->status,
		], static fn(?string $value): bool => $value !== null);
	}

	public function renderHelper(string $helper): void {
	}

	/** @return ClickHistoryRow */
	public static function row(string $id, string $title, string $url, string $feed = 'Feed', string $category = 'Cat'): array {
		return [
			'id_entry' => $id, 'url' => $url, 'title' => $title, 'feed_name' => $feed, 'id_feed' => 1,
			'category_name' => $category, 'id_category' => null, 'clicked_at' => 200, 'first_clicked_at' => 100, 'status' => 'unrated',
		];
	}

	/** The pagination helper, which render() leaves out like the other helpers. */
	public static function renderPagination(int $nbPage, int $currentPage): string {
		$view = new self();
		$view->nbPage = $nbPage;
		$view->currentPage = $currentPage;
		ob_start();
		(function (): void {
			include __DIR__ . '/../views/helpers/clickhistory/pagination.phtml';
		})->call($view);
		return (string)ob_get_clean();
	}

	/**
	 * @param list<ClickHistoryRow> $history
	 * @param array<string, int> $statusCounts
	 */
	public static function render(array $history, array $statusCounts, ?string $status = null, bool $byCategory = false): string {
		$view = new self();
		$view->history = $history;
		$view->statusCounts = $statusCounts;
		$view->status = $status;
		$view->byCategory = $byCategory;
		ob_start();
		(function (): void {
			include __DIR__ . '/../views/clickhistory/index.phtml';
		})->call($view);
		return (string)ob_get_clean();
	}
}

$failures = 0;
$check = static function (string $what, bool $ok) use (&$failures): void {
	echo $ok ? 'ok   ' : 'FAIL ', $what, "\n";
	if (!$ok) {
		$failures++;
	}
};

$script = '"><script>alert(1)</script>';
$rows = [
	IndexView::row('1', 'Plain', 'https://example.org/a?x=1&y=2'),
	IndexView::row('2', 'Script link', 'javascript:alert(1)'),
	IndexView::row('3', 'Spaced script link', ' javascript:alert(1)'),
	// http(s) further in: only the start of a URL decides what it is.
	IndexView::row('6', 'Script link to a site', 'javascript:fetch("https://example.org/")'),
	IndexView::row('4', $script, 'https://example.org/' . $script, $script, $script),
	IndexView::row('5', 'Mixed case', 'HTTPS://example.org/b'),
	IndexView::row('7', 'Tracked', 'https://example.org/c?utm_source=feed&id=7&fbclid=x#top'),
];
$webRows = 4;
$counts = ['unrated' => 5, 'good' => 0, 'dropped' => 0];

foreach ([false, true] as $byCategory) {
	$html = IndexView::render($rows, $counts, null, $byCategory);
	$mode = $byCategory ? ' (by category)' : '';
	// A template that rendered nothing would pass every "is not in it" check below.
	$check("every row is rendered{$mode}", substr_count($html, 'name="rate"') === 2 * count($rows));
	$check("an http(s) link becomes a link, with & escaped{$mode}", str_contains($html, 'href="https://example.org/a?x=1&amp;y=2"') &&
		str_contains($html, 'href="HTTPS://example.org/b"'));
	$check("a javascript: URL is never an href{$mode}", preg_match('/href="\s*javascript:/i', $html) === 0);
	$check("a javascript: URL is shown as text{$mode}", str_contains($html, '<code>javascript:alert(1)</code>'));
	$check("no markup from a feed reaches the page{$mode}", !str_contains($html, '<script>'));
	$check("a quote in a link cannot leave the attribute{$mode}", str_contains($html, 'href="https://example.org/&quot;&gt;&lt;script&gt;'));
	// Title, feed, category (column or group heading), the link and the copy
	// button's address: five times.
	$check("a title, feed and category are escaped{$mode}", substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;') === 5);
	$check("every web link has a copy button, and nothing else has one{$mode}", substr_count($html, 'click-history-copy') === $webRows);
	$check("the copy button carries the link without its tracking parameters{$mode}",
		str_contains($html, 'data-url="https://example.org/c?id=7#top"'));
	$check("the copy button's address is escaped{$mode}", str_contains($html, 'data-url="https://example.org/a?x=1&amp;y=2"') &&
		str_contains($html, 'data-url="https://example.org/&quot;&gt;&lt;script&gt;'));
	$check("the copy button never submits a form{$mode}",
		substr_count($html, '<button type="button" class="btn click-history-copy"') === $webRows);
}

$check('the pagination is a named landmark', str_contains(
	IndexView::renderPagination(3, 2),
	'<nav class="nav-pagination nav-list" aria-label="ext.click_history.landmark.pages">'
));

// What the copy button copies.
$cleanUrlCases = [
	'tracking before the rest, fragment kept' => ['https://example.org/p?utm_source=x&id=5#frag', 'https://example.org/p?id=5#frag'],
	'nothing but tracking: no ? left' => ['https://example.org/p?utm_source=x&utm_medium=rss', 'https://example.org/p'],
	'nothing but tracking, with a fragment' => ['https://example.org/p?fbclid=abc#top', 'https://example.org/p#top'],
	'the name in another case' => ['https://example.org/p?UTM_Source=x&id=5', 'https://example.org/p?id=5'],
	'the name percent-encoded' => ['https://example.org/p?%75tm_source=x&id=5', 'https://example.org/p?id=5'],
	'a click id at the end' => ['https://example.org/p?id=5&fbclid=abc', 'https://example.org/p?id=5'],
	'Matomo and Piwik prefixes' => ['https://example.org/p?mtm_campaign=a&pk_kwd=b&id=5', 'https://example.org/p?id=5'],
	'a parameter without a value' => ['https://example.org/p?gclid&id=5', 'https://example.org/p?id=5'],
	'nothing to remove: unchanged, empty pairs and all' => ['https://example.org/p?a=1&&b=2', 'https://example.org/p?a=1&&b=2'],
	'nothing to remove: a trailing ? stays' => ['https://example.org/p?', 'https://example.org/p?'],
	'no query at all' => ['https://example.org/p#utm_source=x', 'https://example.org/p#utm_source=x'],
	'a ? in the fragment is not a query' => ['https://example.org/p#a?utm_source=x', 'https://example.org/p#a?utm_source=x'],
	'ref, source and s are content on some sites' => ['https://example.org/p?ref=a&source=b&s=c', 'https://example.org/p?ref=a&source=b&s=c'],
	'encoding and order of the rest untouched' => ['https://example.org/p?b=%20x&utm_id=1&a.b=1&c[]=2', 'https://example.org/p?b=%20x&a.b=1&c[]=2'],
	'a name that only contains a tracking one' => ['https://example.org/p?my_utm_source=x&xfbclid=y', 'https://example.org/p?my_utm_source=x&xfbclid=y'],
];
foreach ($cleanUrlCases as $what => [$url, $expected]) {
	$actual = ClickHistoryExtension::cleanUrl($url);
	$check("cleanUrl: {$what}", $actual === $expected);
	if ($actual !== $expected) {
		echo "     got {$actual}\n";
	}
}

$clear = 'a=clear';
$check('the clear button is there on a page with rows', str_contains(IndexView::render($rows, $counts), $clear));
$check('the clear button is there on a filtered page that shows nothing', str_contains(
	IndexView::render([], ['unrated' => 0, 'good' => 0, 'dropped' => 1], 'good'),
	$clear
));
$check('a single row in the archive is enough for the clear button', str_contains(
	IndexView::render([], ['unrated' => 1, 'good' => 0, 'dropped' => 0], 'dropped'),
	$clear
));
$check('there is no clear button when there is no history', !str_contains(
	IndexView::render([], ['unrated' => 0, 'good' => 0, 'dropped' => 0]),
	$clear
));

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
