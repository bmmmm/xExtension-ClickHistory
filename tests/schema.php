<?php
declare(strict_types=1);

// The two parts of the triage feature that a running instance would only ever
// tell us about by losing data: the schema upgrade, which happens exactly once
// per installation, and the upsert, which must not throw a judgement away when
// the same article is opened a second time.
//
// Exercised against real SQLite rather than a mock, because what is in question
// is what the database does with these statements. The error mode matches
// Minz_ModelPdo (ERRMODE_SILENT), which is what lets the column probe work by
// reading a false return instead of catching an exception.
//
// Not a unit test of ClickHistoryDAO: that class needs Minz_ModelPdo and a
// FreshRSS context. The statements it builds are what is checked here, so editing
// one there means editing it here too — deliberately, since the point is to notice.

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);

$failures = 0;
$check = static function (string $what, bool $ok) use (&$failures): void {
	echo $ok ? 'ok   ' : 'FAIL ', $what, "\n";
	if (!$ok) {
		$failures++;
	}
};

/** Runs a statement that is meant to work; a failure here is a broken test, not a finding. */
$exec = static function (string $sql) use ($pdo): void {
	if ($pdo->exec($sql) === false) {
		fwrite(STDERR, 'setup failed: ' . json_encode($pdo->errorInfo()) . "\n  " . $sql . "\n");
		exit(1);
	}
};

$query = static function (string $sql) use ($pdo): PDOStatement {
	$stm = $pdo->query($sql);
	if ($stm === false) {
		fwrite(STDERR, 'query failed: ' . json_encode($pdo->errorInfo()) . "\n  " . $sql . "\n");
		exit(1);
	}
	return $stm;
};

/** @return array<string,mixed> */
$fetchRow = static function (string $sql) use ($query): array {
	$row = $query($sql)->fetch(PDO::FETCH_ASSOC);
	if (!is_array($row)) {
		fwrite(STDERR, 'expected one row from: ' . $sql . "\n");
		exit(1);
	}
	return $row;
};

/** Column values arrive as mixed and differ in type per driver, so everything is compared as text. */
$asString = static fn(mixed $value): string => is_scalar($value) ? (string)$value : '';

$scalar = static fn(string $sql): string => $asString($query($sql)->fetchColumn());

// A table as the version before this one left it: category columns present, no status.
$exec(<<<'SQL'
	CREATE TABLE click_history (
		id_entry BIGINT NOT NULL, url TEXT NOT NULL, title TEXT NOT NULL,
		feed_name TEXT NOT NULL, id_feed INTEGER,
		category_name TEXT NOT NULL DEFAULT '', id_category INTEGER,
		clicked_at INTEGER NOT NULL, first_clicked_at INTEGER NOT NULL,
		PRIMARY KEY (id_entry)
	)
	SQL);
$exec("INSERT INTO click_history VALUES (1, 'http://a', 'First title', 'Feed', 1, 'Cat', 1, 100, 100)");

$check('the probe sees the column missing', $pdo->query('SELECT status FROM click_history LIMIT 1') === false);

// What addStatusColumnSql() produces on SQLite. The DEFAULT is not optional there:
// SQLite refuses to add a NOT NULL column without one.
$exec("ALTER TABLE click_history ADD COLUMN status TEXT NOT NULL DEFAULT 'unrated'");

$check('the probe sees it present afterwards', $pdo->query('SELECT status FROM click_history LIMIT 1') !== false);
$check(
	'a row recorded before the upgrade reads as unrated',
	$scalar('SELECT status FROM click_history WHERE id_entry = 1') === 'unrated'
);

// Judge the article, then open it again — the case the ON CONFLICT clause is about.
$exec("UPDATE click_history SET status = 'good' WHERE id_entry = 1");
$exec(<<<'SQL'
	INSERT INTO click_history
		(id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at, status)
	VALUES (1, 'http://changed', 'Retitled', 'Renamed feed', 2, 'Other', 2, 200, 200, 'unrated')
	ON CONFLICT (id_entry) DO UPDATE SET clicked_at = excluded.clicked_at
	SQL);

$row = $fetchRow('SELECT status, clicked_at, first_clicked_at, title FROM click_history WHERE id_entry = 1');
$check('a second click keeps the judgement', $asString($row['status'] ?? null) === 'good');
$check('a second click moves clicked_at forward', $asString($row['clicked_at'] ?? null) === '200');
$check('a second click leaves first_clicked_at alone', $asString($row['first_clicked_at'] ?? null) === '100');
$check('a second click does not rewrite the archived title', $asString($row['title'] ?? null) === 'First title');

// The filtered list and its count have to agree, or the last page renders empty.
$exec("INSERT INTO click_history VALUES (2, 'http://b', 'B', 'Feed', 1, 'Cat', 1, 300, 300, 'dropped')");
$exec("INSERT INTO click_history VALUES (3, 'http://c', 'C', 'Feed', 1, 'Cat', 1, 400, 400, 'unrated')");

$counted = (int)$scalar("SELECT COUNT(*) FROM click_history WHERE status = 'good'");
$listed = $query("SELECT id_entry FROM click_history WHERE status = 'good' ORDER BY clicked_at DESC, id_entry DESC")
	->fetchAll(PDO::FETCH_ASSOC);
$check('count and list agree under a filter', $counted === 1 && count($listed) === 1);

$grouped = $query('SELECT status, COUNT(*) AS total FROM click_history GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
$totals = [];
foreach ($grouped as $groupedRow) {
	if (!is_array($groupedRow)) {
		continue;
	}
	$totals[$asString($groupedRow['status'] ?? null)] = (int)$asString($groupedRow['total'] ?? null);
}
$check(
	'the per-status totals add up to the whole table',
	array_sum($totals) === 3 && ($totals['good'] ?? 0) === 1
		&& ($totals['dropped'] ?? 0) === 1 && ($totals['unrated'] ?? 0) === 1
);

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
