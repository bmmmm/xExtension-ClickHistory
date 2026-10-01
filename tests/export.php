<?php
declare(strict_types=1);

// The export view, rendered from rows made up here and read back the way the
// receiving end reads it: a CSV parser that follows RFC 4180, and json_decode().
// What a spreadsheet makes of a headline is the part that reading the template
// cannot show.
//
// Needs nothing but PHP: the view is included on a stand-in for ClickHistoryView
// that carries the two properties it reads.
//
//   php tests/export.php

/**
 * Stands in for ClickHistoryView: the two properties the template reads, filled
 * the way the controller fills them.
 *
 * @phpstan-import-type ClickHistoryRow from ClickHistoryDAO
 */
final class ExportView {
	public string $exportFormat = 'json';
	/** @var iterable<ClickHistoryRow> */
	public iterable $exportRows = [];

	/** @return ClickHistoryRow */
	public static function row(string $id, string $title, string $url, string $feed, string $category, string $status = 'unrated'): array {
		return [
			'id_entry' => $id, 'url' => $url, 'title' => $title, 'feed_name' => $feed, 'id_feed' => 1,
			'category_name' => $category, 'id_category' => null, 'clicked_at' => 200, 'first_clicked_at' => 100, 'status' => $status,
		];
	}

	/**
	 * Renders the template once. The rows arrive as a generator, the way the DAO's
	 * streamAll() hands them over: anything that walks them twice gets nothing.
	 *
	 * @param list<ClickHistoryRow> $rows
	 */
	public static function render(string $format, array $rows): string {
		$view = new self();
		$view->exportFormat = $format;
		$view->exportRows = self::stream($rows);
		ob_start();
		(function (): void {
			include __DIR__ . '/../views/clickhistory/export.phtml';
		})->call($view);
		return (string)ob_get_clean();
	}

	/**
	 * @param list<ClickHistoryRow> $rows
	 * @return Generator<int, ClickHistoryRow>
	 */
	private static function stream(array $rows): Generator {
		yield from $rows;
	}
}

$failures = 0;
$check = static function (string $what, bool $ok) use (&$failures): void {
	echo $ok ? 'ok   ' : 'FAIL ', $what, "\n";
	if (!$ok) {
		$failures++;
	}
};

// --- CSV -----------------------------------------------------------------------

$tricky = '=HYPERLINK("x") a\"b';
$rows = [ExportView::row('1759276800000001', $tricky, 'https://example.org/', 'Feed', 'Cat')];
// Every character a spreadsheet reads as the start of a formula, in each of the
// four columns a feed decides.
$triggers = ['=', '+', '-', '@', "\t", "\r"];
foreach ($triggers as $i => $char) {
	$rows[] = ExportView::row((string)(1759276800000010 + $i), $char . 'title', $char . 'url', $char . 'feed', $char . 'category');
}
// The extension's own columns: not feed text, so not escaped, whatever they hold.
$rows[] = ExportView::row('1759276800000020', 'Plain', 'https://example.org/p', 'Feed', 'Cat', '-x');

$csv = ExportView::render('csv', $rows);
$check('the CSV starts with a UTF-8 BOM', str_starts_with($csv, "\xEF\xBB\xBF"));

$stream = fopen('php://memory', 'r+');
if ($stream === false) {
	fwrite(STDERR, "cannot open a memory stream\n");
	exit(1);
}
fwrite($stream, substr($csv, 3));
rewind($stream);
$lines = [];
while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
	$lines[] = $fields;
}
fclose($stream);

// A template that rendered nothing would pass every per-row check below.
if (count($lines) < 2) {
	fwrite(STDERR, 'the CSV export rendered ' . count($lines) . " line(s), expected a header and rows\n" . $csv);
	exit(1);
}
$check('the header row', $lines[0] === [
	'id_entry', 'title', 'url', 'feed', 'category', 'status',
	'clicked_at', 'clicked_at_iso', 'first_clicked_at', 'first_clicked_at_iso',
]);
$check('one line per row, every one with ten cells', count($lines) === count($rows) + 1 &&
	array_filter($lines, static fn(array $line): bool => count($line) !== 10) === []);
$check('a headline with quotes and \\" comes back whole', ($lines[1][1] ?? null) === "'" . $tricky);

$columns = ['title' => 1, 'url' => 2, 'feed' => 3, 'category' => 4];
foreach ($columns as $name => $index) {
	$escaped = [];
	foreach ($triggers as $i => $char) {
		$escaped[] = ($lines[$i + 2][$index] ?? null) === "'" . $char . $name;
	}
	$check("a {$name} starting with = + - @ tab or CR stays text", !in_array(false, $escaped, true));
}

$last = $lines[count($lines) - 1];
$check('the extension\'s own columns are written as they are', array_slice($last, 0, 1) === ['1759276800000020'] &&
	array_slice($last, 5) === ['-x', '200', date(DATE_ATOM, 200), '100', date(DATE_ATOM, 100)]);

// --- JSON ----------------------------------------------------------------------

// One byte that is not UTF-8, the way a broken feed delivers it.
$broken = "Caf\xE9";
$json = json_decode(ExportView::render('json', [ExportView::row('1', $broken, 'https://example.org/', 'Feed', ''), ...$rows]), true);
$entries = is_array($json) && is_array($json['entries'] ?? null) ? $json['entries'] : [];
$check('the JSON export parses, with every row and a count that agrees', is_array($json) &&
	count($entries) === count($rows) + 1 && ($json['count'] ?? null) === count($entries));
$first = is_array($entries[0] ?? null) ? $entries[0] : [];
$check('a malformed byte becomes U+FFFD rather than an empty file', ($first['title'] ?? null) === "Caf\u{FFFD}");
$second = is_array($entries[1] ?? null) ? $entries[1] : [];
$check('JSON is not formula-escaped', ($second['title'] ?? null) === $tricky);

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
