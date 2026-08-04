<?php
declare(strict_types=1);

// The two parts of the extension a running instance would only ever tell us about
// by losing data: the schema upgrade, which happens exactly once per installation,
// and the upsert, which must not throw a judgement away when the same article is
// opened a second time.
//
// The statements are the real ones. ClickHistorySchema exists so that they can be
// required without a FreshRSS context — the DAO around them extends Minz_ModelPdo
// and cannot be instantiated here, but the SQL it runs is exactly what this file
// executes, so an edit there is caught rather than silently diverging from a copy.
//
// Which database it runs against comes from the environment, default in-memory
// SQLite:
//
//   php tests/schema.php
//   CLICKHISTORY_TEST_DSN='mysql:host=127.0.0.1;dbname=clickhistory' \
//     CLICKHISTORY_TEST_USER=root CLICKHISTORY_TEST_PASSWORD=secret php tests/schema.php
//   CLICKHISTORY_TEST_DSN='pgsql:host=127.0.0.1;dbname=clickhistory' \
//     CLICKHISTORY_TEST_USER=postgres CLICKHISTORY_TEST_PASSWORD=secret php tests/schema.php
//
// The MySQL and PostgreSQL legs run in CI against service containers
// (.github/workflows/ci.yml), which is what turns those two schema variants from
// "written against the core schema" into something that has been executed.
//
// The error mode matches Minz_ModelPdo (ERRMODE_SILENT), which is what lets the
// column probe work by reading a false return instead of catching an exception.

require_once __DIR__ . '/../Dao/ClickHistorySchema.php';

$dsn = getenv('CLICKHISTORY_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
	$dsn = 'sqlite::memory:';
}
$user = getenv('CLICKHISTORY_TEST_USER');
$password = getenv('CLICKHISTORY_TEST_PASSWORD');

// Minz_Pdo::dbType() by another route: the driver in the DSN is the same string.
$dbType = strtolower(substr($dsn, 0, (int)strpos($dsn . ':', ':')));
if (!in_array($dbType, ['mysql', 'pgsql', 'sqlite'], true)) {
	fwrite(STDERR, "unsupported DSN: {$dsn}\n");
	exit(1);
}
echo "database: {$dbType}\n";

try {
	$pdo = new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
	]);
} catch (PDOException $e) {
	fwrite(STDERR, 'cannot connect to ' . $dsn . ': ' . $e->getMessage() . "\n");
	exit(1);
}

$failures = 0;
$check = static function (string $what, bool $ok) use (&$failures): void {
	echo $ok ? 'ok   ' : 'FAIL ', $what, "\n";
	if (!$ok) {
		$failures++;
	}
};

/**
 * What Minz_Pdo does to a statement on its way to the driver: `\`_` becomes the
 * table prefix (Minz_Pdo::autoPrefix()), and on PostgreSQL the remaining backticks
 * become double quotes (Minz_PdoPgsql::preSql()). Two prefixes are used below so
 * that the fresh-install and the upgrade scenario cannot see each other's table.
 */
$prepareSql = static function (string $sql, string $prefix) use ($dbType): string {
	$sql = str_replace('`_', '`' . $prefix, $sql);
	return $dbType === 'pgsql' ? str_replace('`', '"', $sql) : $sql;
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

// MySQL and PostgreSQL keep their database between runs, so start from nothing
// rather than from whatever the last run left behind.
$fresh = 'chtest_fresh_';
$legacy = 'chtest_legacy_';
foreach ([$fresh, $legacy] as $prefix) {
	$exec($prepareSql('DROP TABLE IF EXISTS `_click_history`', $prefix));
}

// --- A fresh install ---------------------------------------------------------
// The CREATE TABLE statements per dialect, executed rather than described. Two of
// the three have only ever been read before; this is what makes them checked.

foreach (ClickHistorySchema::createTable($dbType) as $sql) {
	$exec($prepareSql($sql, $fresh));
}
$check(
	'the created table has every column the code reads',
	$pdo->query($prepareSql('SELECT ' . ClickHistorySchema::COLUMNS . ' FROM `_click_history` LIMIT 1', $fresh)) !== false
);
$check(
	'creating it twice is not an error',
	array_reduce(
		ClickHistorySchema::createTable($dbType),
		static fn(bool $ok, string $sql): bool => $ok && $pdo->exec($prepareSql($sql, $fresh)) !== false,
		true
	)
);

// The real upsert, with the real bindings. Recorded, judged, then opened again —
// the case the ON CONFLICT / ON DUPLICATE KEY clause exists for.
/** @param array<string,string|int> $values */
$record = static function (array $values) use ($pdo, $prepareSql, $dbType, $fresh): void {
	$stm = $pdo->prepare($prepareSql(ClickHistorySchema::record($dbType), $fresh));
	if ($stm === false) {
		fwrite(STDERR, 'cannot prepare the record statement: ' . json_encode($pdo->errorInfo()) . "\n");
		exit(1);
	}
	foreach ($values as $name => $value) {
		$stm->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
	}
	if (!$stm->execute()) {
		fwrite(STDERR, 'cannot record: ' . json_encode($stm->errorInfo()) . "\n");
		exit(1);
	}
};

$record([
	'id_entry' => '1', 'url' => 'http://a', 'title' => 'First title', 'feed_name' => 'Feed',
	'id_feed' => 1, 'category_name' => 'Cat', 'id_category' => 1,
	'clicked_at' => 100, 'first_clicked_at' => 100, 'status' => 'unrated',
]);
$exec($prepareSql("UPDATE `_click_history` SET status = 'good' WHERE id_entry = 1", $fresh));
$record([
	'id_entry' => '1', 'url' => 'http://changed', 'title' => 'Retitled', 'feed_name' => 'Renamed feed',
	'id_feed' => 2, 'category_name' => 'Other', 'id_category' => 2,
	'clicked_at' => 200, 'first_clicked_at' => 200, 'status' => 'unrated',
]);

$row = $fetchRow($prepareSql(
	'SELECT status, clicked_at, first_clicked_at, title FROM `_click_history` WHERE id_entry = 1',
	$fresh
));
$check('a second click keeps the judgement', $asString($row['status'] ?? null) === 'good');
$check('a second click moves clicked_at forward', $asString($row['clicked_at'] ?? null) === '200');
$check('a second click leaves first_clicked_at alone', $asString($row['first_clicked_at'] ?? null) === '100');
$check('a second click does not rewrite the archived title', $asString($row['title'] ?? null) === 'First title');

// The filtered list and its count have to agree, or the last page renders empty.
$record([
	'id_entry' => '2', 'url' => 'http://b', 'title' => 'B', 'feed_name' => 'Feed',
	'id_feed' => 1, 'category_name' => 'Cat', 'id_category' => 1,
	'clicked_at' => 300, 'first_clicked_at' => 300, 'status' => 'dropped',
]);
$record([
	'id_entry' => '3', 'url' => 'http://c', 'title' => 'C', 'feed_name' => 'Feed',
	'id_feed' => 1, 'category_name' => 'Cat', 'id_category' => 1,
	'clicked_at' => 400, 'first_clicked_at' => 400, 'status' => 'unrated',
]);

// The clauses the DAO composes its two reads from, bound the same way it binds them.
/** @return list<array<string,mixed>> */
$listed = static function (?string $status, bool $byCategory) use ($pdo, $prepareSql, $fresh): array {
	$sql = 'SELECT ' . ClickHistorySchema::COLUMNS . ' FROM `_click_history` '
		. ClickHistorySchema::statusClause($status) . ' ' . ClickHistorySchema::orderClause($byCategory);
	$stm = $pdo->prepare($prepareSql($sql, $fresh));
	if ($stm === false) {
		fwrite(STDERR, 'cannot prepare the list statement: ' . json_encode($pdo->errorInfo()) . "\n");
		exit(1);
	}
	if ($status !== null) {
		$stm->bindValue(':status', $status, PDO::PARAM_STR);
	}
	if (!$stm->execute()) {
		fwrite(STDERR, 'cannot list: ' . json_encode($stm->errorInfo()) . "\n");
		exit(1);
	}
	return $stm->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

// count() interpolates the same clause, so both sides of the page arithmetic are
// checked against one another rather than each against a hand-written query.
$counted = static function (?string $status) use ($pdo, $prepareSql, $fresh, $asString): int {
	$sql = 'SELECT COUNT(*) FROM `_click_history` ' . ClickHistorySchema::statusClause($status);
	$stm = $pdo->prepare($prepareSql($sql, $fresh));
	if ($stm === false) {
		fwrite(STDERR, 'cannot prepare the count statement: ' . json_encode($pdo->errorInfo()) . "\n");
		exit(1);
	}
	if ($status !== null) {
		$stm->bindValue(':status', $status, PDO::PARAM_STR);
	}
	if (!$stm->execute()) {
		fwrite(STDERR, 'cannot count: ' . json_encode($stm->errorInfo()) . "\n");
		exit(1);
	}
	return (int)$asString($stm->fetchColumn());
};

$check('count and list agree under a filter', $counted('good') === 1 && count($listed('good', false)) === 1);
$check('count and list agree without one', $counted(null) === 3 && count($listed(null, false)) === 3);

$byDate = [];
foreach ($listed(null, false) as $listedRow) {
	if (is_array($listedRow)) {
		$byDate[] = $asString($listedRow['id_entry'] ?? null);
	}
}
$check('the default order is most recently opened first', $byDate === ['3', '2', '1']);

$grouped = $query($prepareSql('SELECT status, COUNT(*) AS total FROM `_click_history` GROUP BY status', $fresh))
	->fetchAll(PDO::FETCH_ASSOC);
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

// --- An installation upgrading from an earlier version -----------------------
// The old CREATE TABLE is written out here rather than obtained from the code:
// it is what a previous release left behind, and that statement does not exist
// any more. Everything applied to it is the real upgrade path.

$nameType = $dbType === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
$intType = $dbType === 'sqlite' ? 'INTEGER' : 'INT';
$exec($prepareSql(<<<SQL
	CREATE TABLE `_click_history` (
		id_entry BIGINT NOT NULL, url TEXT NOT NULL, title TEXT NOT NULL,
		feed_name {$nameType} NOT NULL, id_feed {$intType},
		clicked_at BIGINT NOT NULL, first_clicked_at BIGINT NOT NULL,
		PRIMARY KEY (id_entry)
	)
	SQL, $legacy));
$exec($prepareSql(
	"INSERT INTO `_click_history` VALUES (1, 'http://a', 'First title', 'Feed', 1, 100, 100)",
	$legacy
));

$probe = static fn(string $column): bool =>
	$pdo->query($prepareSql(ClickHistorySchema::columnProbe($column), $legacy)) !== false;

$check('the probe sees the category columns missing', !$probe('category_name'));
foreach (ClickHistorySchema::addCategoryColumns($dbType) as $sql) {
	$exec($prepareSql($sql, $legacy));
}
$check('the probe sees them present afterwards', $probe('category_name'));

$check('the probe sees the status column missing', !$probe('status'));
foreach (ClickHistorySchema::addStatusColumn($dbType) as $sql) {
	$exec($prepareSql($sql, $legacy));
}
$check('the probe sees it present afterwards', $probe('status'));

$upgraded = $fetchRow($prepareSql(
	'SELECT category_name, id_category, status FROM `_click_history` WHERE id_entry = 1',
	$legacy
));
$check('a row recorded before the upgrade has no category', $asString($upgraded['category_name'] ?? null) === '');
$check('a row recorded before the upgrade reads as unrated', $asString($upgraded['status'] ?? null) === 'unrated');
$check(
	'the upgraded table has every column the code reads',
	$pdo->query($prepareSql('SELECT ' . ClickHistorySchema::COLUMNS . ' FROM `_click_history` LIMIT 1', $legacy)) !== false
);

// Leave nothing behind in a database that outlives the process.
foreach ([$fresh, $legacy] as $prefix) {
	$exec($prepareSql('DROP TABLE IF EXISTS `_click_history`', $prefix));
}

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
