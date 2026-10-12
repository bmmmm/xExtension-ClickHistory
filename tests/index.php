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
];
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
	// Title, feed, category (column or group heading) and the link: four times.
	$check("a title, feed and category are escaped{$mode}", substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;') === 4);
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
