<?php
declare(strict_types=1);

// The DAO itself, on the connection classes FreshRSS hands it in production:
// Minz_PdoSqlite, Minz_PdoMysql or Minz_PdoPgsql, with what core sets on them —
// native prepares everywhere, unbuffered queries on MySQL, double quotes for
// backticks on PostgreSQL. tests/schema.php executes the statements on a bare PDO;
// this file runs the methods that compose them (paging, counting, folding unknown
// states, streaming) in the order the pages call them.
//
// Same environment variables as tests/schema.php, default in-memory SQLite:
//
//   php tests/dao.php
//   CLICKHISTORY_TEST_DSN='mysql:host=127.0.0.1;dbname=clickhistory' \
//     CLICKHISTORY_TEST_USER=root CLICKHISTORY_TEST_PASSWORD=secret php tests/dao.php
//
// Needs FreshRSS core in .freshrss-core, the same checkout PHPStan uses.

require_once __DIR__ . '/bootstrap.php';

$minz = __DIR__ . '/../.freshrss-core/lib/Minz';
if (!is_file($minz . '/ModelPdo.php')) {
	fwrite(STDERR, "FreshRSS core is missing: git clone --depth 1 --branch 1.29.0 https://github.com/FreshRSS/FreshRSS .freshrss-core\n");
	exit(1);
}

// Core's own autoloader lives in lib_rss.php, which would pull in the whole
// application. The Minz classes are one file each and need nothing else.
spl_autoload_register(static function (string $class) use ($minz): void {
	if (str_starts_with($class, 'Minz_') && is_file($minz . '/' . substr($class, 5) . '.php')) {
		require_once $minz . '/' . substr($class, 5) . '.php';
	}
});

// Minz_Pdo::preSql() calls this before every write. The real one is in lib_rss.php
// too, and touches a file in the user's data directory.
if (!function_exists('invalidateHttpCache')) {
	function invalidateHttpCache(string $username = ''): bool {
		return true;
	}
}

// Minz_Log writes into the user's data directory, which needs the rest of core to
// locate. A DAO error therefore shows up here as the wrong return value, and the
// failing check prints the connection's last error.
putenv('FRESHRSS_ENV=silent');

require_once __DIR__ . '/../Dao/ClickHistoryDAO.php';

$dsn = getenv('CLICKHISTORY_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
	$dsn = 'sqlite::memory:';
}
$user = getenv('CLICKHISTORY_TEST_USER');
$user = $user === false ? null : $user;
$password = getenv('CLICKHISTORY_TEST_PASSWORD');
$password = $password === false ? null : $password;

// What Minz_ModelPdo::dbConnect() adds on top of the classes' own settings: the
// error mode everywhere, and on MySQL a utf8mb4 connection — without it a
// headline with a four-byte character (an emoji) cannot be stored at all.
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT];
$dbType = strtolower(substr($dsn, 0, (int)strpos($dsn . ':', ':')));
if ($dbType === 'mysql') {
	$dsn .= ';charset=utf8mb4';
	if (class_exists('Pdo\Mysql')) {
		assert(is_int(Pdo\Mysql::ATTR_INIT_COMMAND));	// For PHPStan with PHP 8.4+, as core does it
		$options[Pdo\Mysql::ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4';	// PHP 8.4+, as core does it
	} else {
		$options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4';	// PHP < 8.4
	}
}
try {
	$pdo = match ($dbType) {
		'mysql' => new Minz_PdoMysql($dsn, $user, $password, $options),
		'pgsql' => new Minz_PdoPgsql($dsn, $user, $password, $options),
		'sqlite' => new Minz_PdoSqlite($dsn, $user, $password, $options),
		default => null,
	};
} catch (PDOException $e) {
	fwrite(STDERR, 'cannot connect to ' . $dsn . ': ' . $e->getMessage() . "\n");
	exit(1);
}
if ($pdo === null) {
	fwrite(STDERR, "unsupported DSN: {$dsn}\n");
	exit(1);
}
echo "database: {$pdo->dbType()}\n";

// A user name of its own rather than null, which would ask the session for one.
$dao = new ClickHistoryDAO('test', $pdo);

$failures = 0;
$check = static function (string $what, bool $ok) use (&$failures, $pdo): void {
	echo $ok ? 'ok   ' : 'FAIL ', $what, "\n";
	if (!$ok) {
		echo '     last connection error: ', json_encode($pdo->errorInfo()), "\n";
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

/** Column values arrive as mixed and differ in type per driver, so everything is compared as text. */
$asString = static fn(mixed $value): string => is_scalar($value) ? (string)$value : '';

/** One column of one row, read past the DAO — its own reads would normalise what is being checked. */
$stored = static function (string $column, string $idEntry) use ($pdo, $asString): string {
	$stm = $pdo->query("SELECT {$column} FROM `_click_history` WHERE id_entry = {$idEntry}");
	if ($stm === false) {
		return '(query failed)';
	}
	$value = $stm->fetchColumn();
	$stm->closeCursor();
	return $asString($value);
};

/** For the table under the current prefix. The statement is dropped unread, as the DAO's own column probe is. */
$tableExists = static fn(): bool => $pdo->query('SELECT 1 FROM `_click_history`') !== false;

/**
 * @param list<array{id_entry:string}> $rows
 * @return list<string>
 */
$ids = static fn(array $rows): array => array_column($rows, 'id_entry');

// MySQL and PostgreSQL keep their database between runs, so start from nothing
// rather than from whatever the last run left behind.
$prefixes = ['chtest_dao_a_', 'chtest_dao_b_', 'chtest_dao_legacy_', 'chtest_dao_fresh_', 'chtest_dao_figures_'];
$dropAll = static function () use ($pdo, $prefixes, $exec): void {
	foreach ($prefixes as $prefix) {
		$pdo->setPrefix($prefix);
		$exec('DROP TABLE IF EXISTS `_click_history`');
	}
};
$dropAll();

// --- One connection, two users -----------------------------------------------
// On MySQL and PostgreSQL the prefix carries the user name, so it is a different
// table per user; the once-per-process check must not take one for the other.

[$a, $b] = $prefixes;
$pdo->setPrefix($a);
$check('the table is created', $dao->ensureTableExists());
$pdo->setPrefix($b);
$dao->ensureTableExists();
$check('a second prefix on the same connection gets a table of its own', $tableExists());
$pdo->setPrefix($a);

// --- Recording, paging, counting ---------------------------------------------
// Entry ids the size core gives them: microsecond timestamps, past 32 bits.

$e1 = '1759276800000001';
$e2 = '1759276800000002';
$e3 = '1759276800000003';
$e4 = '1759276800000004';
$unicodeTitle = 'Café über Ünïcödé 🎉';
$recorded = $dao->record($e1, 'https://example.org/1', 'One', 'Feed', 1, 'Cat', 10, 100) &&
	$dao->record($e2, 'https://example.org/2', 'Two', 'Feed', 1, 'Cat', 10, 200) &&
	$dao->record($e3, 'https://example.org/3', $unicodeTitle, 'Other feed', 2, '', null, 300) &&
	$dao->record($e4, 'https://example.org/4', 'Four', 'Feed', 1, 'Cat', 10, 400);
$check('four entries are recorded', $recorded && $dao->count() === 4);

$check('a rating is stored', $dao->setStatus($e1, ClickHistoryDAO::STATUS_GOOD) && $dao->setStatus($e2, ClickHistoryDAO::STATUS_DROPPED));
// A status this version does not know, as a later one or a hand edit would leave it.
$exec("UPDATE `_click_history` SET status = 'later' WHERE id_entry = {$e4}");

// The same article opened again, after its feed retitled it.
$check('opening an entry again is recorded', $dao->record($e1, 'https://example.org/1b', 'One, retitled', 'Feed renamed', 1, 'Cat', 10, 500));
$check(
	'opening it again moves clicked_at and nothing else',
	$stored('clicked_at', $e1) === '500' && $stored('first_clicked_at', $e1) === '100' &&
		$stored('title', $e1) === 'One' && $stored('url', $e1) === 'https://example.org/1' &&
		$stored('feed_name', $e1) === 'Feed'
);
$check('opening it again keeps its rating', $stored('status', $e1) === ClickHistoryDAO::STATUS_GOOD);

$all = $dao->listEntries(100, 0);
$check('the list is newest first, ids intact', $ids($all) === [$e1, $e4, $e3, $e2]);
$check('a headline with an umlaut and a four-byte emoji survives', ($all[2]['title'] ?? '') === $unicodeTitle);
$check('an unknown status is listed as unrated', ($all[1]['status'] ?? '') === ClickHistoryDAO::STATUS_UNRATED);

$paged = array_merge($ids($dao->listEntries(2, 0)), $ids($dao->listEntries(2, 2)));
$check('two pages of two hold every entry exactly once', $paged === $ids($all));

// The row whose status is unknown is listed as unrated, so the unrated filter has
// to show it too — and count it, or the filter link and the page disagree.
foreach (['all' => 4, ClickHistoryDAO::STATUS_UNRATED => 2, ClickHistoryDAO::STATUS_GOOD => 1, ClickHistoryDAO::STATUS_DROPPED => 1] as $status => $expected) {
	$status = $status === 'all' ? null : $status;
	$check(
		'count(' . ($status ?? 'all') . ') matches the rows listed under it',
		$dao->count($status) === $expected && count($dao->listEntries(100, 0, false, $status)) === $expected
	);
}

$counts = $dao->countByStatus();
$check('the filter counts fold an unknown status into unrated', $counts === [
	ClickHistoryDAO::STATUS_UNRATED => 2,
	ClickHistoryDAO::STATUS_GOOD => 1,
	ClickHistoryDAO::STATUS_DROPPED => 1,
]);
$check('the filter counts add up to the total', array_sum($counts) === $dao->count());
$check(
	'the unrated filter lists the unrated and the unknown row, as its count says',
	$ids($dao->listEntries(100, 0, false, ClickHistoryDAO::STATUS_UNRATED)) === [$e4, $e3] &&
		$counts[ClickHistoryDAO::STATUS_UNRATED] === $dao->count(ClickHistoryDAO::STATUS_UNRATED)
);

// The export walks the whole result set one row at a time. On MySQL that result is
// unbuffered, so the connection is only free again once it has been read to the end.
$check('the export streams the same rows the list shows', $ids(iterator_to_array($dao->streamAll(), false)) === $ids($all));
$check('the connection takes the next query after the export', $dao->count() === 4);
$check(
	'the grouped, filtered export matches the grouped, filtered list',
	iterator_to_array($dao->streamAll(true, ClickHistoryDAO::STATUS_GOOD), false) === $dao->listEntries(100, 0, true, ClickHistoryDAO::STATUS_GOOD)
);

$check('setting a status this version does not know', $dao->setStatus($e3, 'later'));
$check('… stores unrated instead', $stored('status', $e3) === ClickHistoryDAO::STATUS_UNRATED);
$check('deleting an entry removes that one', $dao->delete($e2) && $ids($dao->listEntries(100, 0)) === [$e1, $e4, $e3]);
$check('clearing empties the table', $dao->clear() && $dao->count() === 0);

// A statement the database refuses has to come back as a failure: the history
// page tells the user so, rather than redirecting as if it had worked. The table
// goes away behind the DAO's back, after its once-per-process check has passed.
$exec('DROP TABLE `_click_history`');
$check('a refused write is reported as a failure', !$dao->setStatus($e1, ClickHistoryDAO::STATUS_GOOD) && !$dao->delete($e1));
$check('a refused read comes back empty', $dao->listEntries(10, 0) === [] && $dao->count() === 0 && $dao->statsByFeed() === []);

// --- The per-feed figures ----------------------------------------------------
// A second feed, so that the ordering has something to order; one of its rows
// without an id_feed, so that MAX() has a NULL to ignore; the first feed clicked
// again under a second category, which is what a feed that has been moved leaves
// behind — the category is a copy taken at click time, so that feed is two rows;
// and a status this version does not know, which has to land in unrated.

$pdo->setPrefix($prefixes[4]);
$dao->record($e1, 'https://example.org/1', 'One', 'Feed', 1, 'Cat', 10, 100);
$dao->record($e2, 'https://example.org/2', 'Two', 'Feed', 1, 'Cat', 10, 200);
$dao->record($e3, 'https://example.org/3', 'Three', 'Feed', 1, 'Cat', 10, 300);
$dao->record($e4, 'https://example.org/4', 'Four', 'Other feed', 2, 'Cat', 10, 400);
$dao->record('1759276800000005', 'https://example.org/5', 'Five', 'Other feed', null, 'Cat', 10, 500);
$dao->record('1759276800000006', 'https://example.org/6', 'Six', 'Feed', 1, 'Moved', 20, 600);
$dao->setStatus($e1, ClickHistoryDAO::STATUS_GOOD);
$dao->setStatus($e2, ClickHistoryDAO::STATUS_DROPPED);
$exec("UPDATE `_click_history` SET status = 'later' WHERE id_entry = {$e3}");
$dao->setStatus($e4, ClickHistoryDAO::STATUS_GOOD);
$dao->setStatus('1759276800000005', ClickHistoryDAO::STATUS_GOOD);

$figures = $dao->statsByFeed();
$check('the figures: one row per feed and category, most opened first', $figures === [
	['feed_name' => 'Feed', 'category_name' => 'Cat', 'id_feed' => 1, 'opened' => 3, 'good' => 1, 'dropped' => 1, 'unrated' => 1],
	// MAX() over a row that has an id and one that has none still yields the id.
	['feed_name' => 'Other feed', 'category_name' => 'Cat', 'id_feed' => 2, 'opened' => 2, 'good' => 2, 'dropped' => 0, 'unrated' => 0],
	['feed_name' => 'Feed', 'category_name' => 'Moved', 'id_feed' => 1, 'opened' => 1, 'good' => 0, 'dropped' => 0, 'unrated' => 1],
]);
// The grouping is the whole table seen from another angle, so nothing may fall
// out of it.
$check('the figures account for every row in the table', array_sum(array_column($figures, 'opened')) === $dao->count() && $dao->count() === 6);

// --- The history page on a table nobody has touched yet ----------------------
// What indexAction() does, in its order, as the first thing in a process: create
// the table, probe both upgrade columns, then three reads on the same connection.

$pdo->setPrefix($prefixes[3]);
// count() answers 0 on an error as well, so the table is looked for separately.
$check('the first read creates the table', $dao->count() === 0 && $tableExists());
// Grouped, the older entry comes first: its category sorts before the other one.
$dao->record($e1, 'https://example.org/1', 'One', 'Feed', 1, 'A', 10, 100);
$dao->record($e2, 'https://example.org/2', 'Two', 'Other feed', 2, 'B', 20, 200);
$total = $dao->count(ClickHistoryDAO::STATUS_UNRATED);
$page = $dao->listEntries(50, 0, true, ClickHistoryDAO::STATUS_UNRATED);
$byStatus = $dao->countByStatus();
$check(
	'count, grouped list and filter counts in a row on one connection',
	$total === 2 && $ids($page) === [$e1, $e2] && $byStatus[ClickHistoryDAO::STATUS_UNRATED] === 2
);

// --- An installation upgrading from the first version ------------------------
// The table as the first release created it, with neither the category nor the
// status columns, then nothing but the DAO's own way of catching up.

$pdo->setPrefix($prefixes[2]);
$nameType = $pdo->dbType() === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
$intType = $pdo->dbType() === 'sqlite' ? 'INTEGER' : 'INT';
$exec(<<<SQL
	CREATE TABLE `_click_history` (
		id_entry BIGINT NOT NULL, url TEXT NOT NULL, title TEXT NOT NULL,
		feed_name {$nameType} NOT NULL, id_feed {$intType},
		clicked_at BIGINT NOT NULL, first_clicked_at BIGINT NOT NULL,
		PRIMARY KEY (id_entry)
	)
	SQL);
$exec("INSERT INTO `_click_history` VALUES ({$e1}, 'https://example.org/1', 'One', 'Feed', 1, 100, 100)");

$check('the DAO upgrades a table from the first version', $dao->ensureTableExists());
$check('a row from before the upgrade is listed, unrated and without a category', $dao->listEntries(10, 0) === [[
	'id_entry' => $e1, 'url' => 'https://example.org/1', 'title' => 'One', 'feed_name' => 'Feed', 'id_feed' => 1,
	'category_name' => '', 'id_category' => null, 'clicked_at' => 100, 'first_clicked_at' => 100, 'status' => 'unrated',
]]);
$check('the upgraded table takes a rating', $dao->setStatus($e1, ClickHistoryDAO::STATUS_GOOD) && $stored('status', $e1) === 'good');

// Leave nothing behind in a database that outlives the process.
$dropAll();

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
