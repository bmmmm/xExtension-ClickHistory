<?php
declare(strict_types=1);

require_once __DIR__ . '/ClickHistorySchema.php';

/**
 * The click history lives in a table of its own rather than in the core's
 * tag/entrytag mechanism, because `_entrytag` references `_entry(id)`: purging an
 * article would take its history entry with it, and outliving the article is the
 * whole point here. Everything the page shows is therefore denormalised into
 * this table at the moment of the click.
 *
 * Deliberately no `FreshRSS_` prefix on the class name — that namespace belongs
 * to core and a future release could collide with it.
 *
 * The table name is written as `_click_history` throughout: Minz_Pdo::autoPrefix()
 * turns the leading backtick-underscore into the real prefix, which is empty for
 * SQLite (one database file per user) and `<prefix><user>_` for MySQL and
 * PostgreSQL. Per-user separation comes out of that for free.
 *
 * @phpstan-type ClickHistoryRow array{id_entry:string, url:string, title:string,
 *     feed_name:string, id_feed:int|null, category_name:string, id_category:int|null,
 *     clicked_at:int, first_clicked_at:int, status:string}
 * @phpstan-type ClickHistoryFeedStats array{feed_name:string, category_name:string,
 *     id_feed:int|null, opened:int, good:int, dropped:int, unrated:int}
 */
final class ClickHistoryDAO extends Minz_ModelPdo {
	/**
	 * The triage state of one entry. `unrated` is what a click leaves behind
	 * unless the settings say otherwise, and the other two are what the history
	 * page writes: `good` means the article was worth reading, `dropped` that it
	 * was not. `dropped` deliberately only hides a row — the per-feed figures
	 * this exists for need it as a denominator, and removing it is what the
	 * delete button is for.
	 */
	public const STATUS_UNRATED = 'unrated';
	public const STATUS_GOOD = 'good';
	public const STATUS_DROPPED = 'dropped';

	/** @var list<'unrated'|'good'|'dropped'> */
	public const STATUSES = [self::STATUS_UNRATED, self::STATUS_GOOD, self::STATUS_DROPPED];

	/**
	 * Once per process and per table: install() creates the table, but an
	 * installation where that never ran (enabled by hand, restored from a backup)
	 * would otherwise fail on every single request instead of repairing itself once.
	 *
	 * Keyed by the PDO prefix rather than a plain flag, because `_click_history` is
	 * a different table per user on MySQL and PostgreSQL (`<prefix><user>_`). One
	 * process serving two users — a CLI run over every account, or any setup where
	 * a request switches context — would otherwise take the first user's table as
	 * proof that the second one's exists.
	 *
	 * @var array<string,true>
	 */
	private static array $tableChecked = [];

	/** @return bool false if the table is missing and could not be created */
	public function ensureTableExists(): bool {
		$key = $this->pdo->prefix();
		if (isset(self::$tableChecked[$key])) {
			return true;
		}
		foreach (ClickHistorySchema::createTable($this->pdo->dbType()) as $sql) {
			if ($this->pdo->exec($sql) === false) {
				Minz_Log::error('ClickHistory: cannot create table: ' . json_encode($this->pdo->errorInfo()));
				return false;
			}
		}
		// In the order the columns were introduced. Each probe is independent, so a
		// table stuck at any earlier version catches up in one pass.
		$dbType = $this->pdo->dbType();
		if (!$this->ensureColumns('category_name', ClickHistorySchema::addCategoryColumns($dbType)) ||
			!$this->ensureColumns('status', ClickHistorySchema::addStatusColumn($dbType))) {
			return false;
		}
		self::$tableChecked[$key] = true;
		return true;
	}

	/**
	 * Adds columns a table created by an earlier version does not have yet: a probe
	 * that fails means the column is missing (see ClickHistorySchema::columnProbe()).
	 *
	 * @param string $probe the column whose absence means the statements must run
	 * @param list<string> $statements
	 */
	private function ensureColumns(string $probe, array $statements): bool {
		if ($this->pdo->query(ClickHistorySchema::columnProbe($probe)) !== false) {
			return true;
		}
		foreach ($statements as $sql) {
			if ($this->pdo->exec($sql) === false) {
				Minz_Log::error("ClickHistory: cannot add the {$probe} column: " . json_encode($this->pdo->errorInfo()));
				return false;
			}
		}
		return true;
	}

	/**
	 * Records one opened article, or moves an existing entry's `clicked_at`
	 * forward; the statement itself is ClickHistorySchema::record().
	 */
	public function record(
		string $idEntry,
		string $url,
		string $title,
		string $feedName,
		?int $idFeed,
		string $categoryName,
		?int $idCategory,
		int $timestamp,
	): bool {
		if (!$this->ensureTableExists()) {
			return false;
		}

		return $this->run(ClickHistorySchema::record($this->pdo->dbType()), [
			':id_entry' => $idEntry,
			':url' => $url,
			':title' => $title,
			':feed_name' => $feedName,
			':id_feed' => $idFeed,
			':category_name' => $categoryName,
			':id_category' => $idCategory,
			':clicked_at' => $timestamp,
			':first_clicked_at' => $timestamp,
			// Only ever used for a row that does not exist yet: the upsert leaves
			// the status of an already-judged article alone.
			':status' => self::STATUS_UNRATED,
		], 'record entry ' . $idEntry) !== null;
	}

	/**
	 * One page of the history: by default most recently opened first, or grouped
	 * by category with the newest first inside each group. A null status means
	 * every row, whatever its triage state.
	 *
	 * @return list<ClickHistoryRow>
	 */
	public function listEntries(int $limit, int $offset, bool $byCategory = false, ?string $status = null): array {
		if (!$this->ensureTableExists()) {
			return [];
		}

		$columns = ClickHistorySchema::COLUMNS;
		$where = ClickHistorySchema::statusClause($status);
		$order = ClickHistorySchema::orderClause($byCategory);
		$sql = <<<SQL
			SELECT {$columns}
			FROM `_click_history`
			{$where}
			{$order}
			LIMIT :limit OFFSET :offset
			SQL;

		$stm = $this->run($sql, self::statusParam($status) + [':limit' => $limit, ':offset' => $offset], 'list entries');
		return $stm === null ? [] : $this->normalise($stm);
	}

	/**
	 * The whole history, for the export. Deliberately not paginated: an export
	 * that silently stopped at the first page would be worse than none. The
	 * status filter travels with it, so what is downloaded is what was on screen.
	 *
	 * A generator rather than a list, because "the whole history" is the one query
	 * here with no upper bound on its size: this code keeps one row at a time and
	 * builds no array of its own. (pdo_pgsql still buffers the whole result set on
	 * its side; on MySQL core switches buffering off in Minz_PdoMysql, so the rows
	 * arrive as they are read and the connection is busy until the last one has
	 * been.) An error yields nothing, which the caller sees as an empty export — the same answer
	 * the list-returning methods give.
	 *
	 * @return Generator<int, ClickHistoryRow>
	 */
	public function streamAll(bool $byCategory = false, ?string $status = null): Generator {
		if (!$this->ensureTableExists()) {
			return;
		}
		$columns = ClickHistorySchema::COLUMNS;
		$where = ClickHistorySchema::statusClause($status);
		$order = ClickHistorySchema::orderClause($byCategory);
		$sql = <<<SQL
			SELECT {$columns}
			FROM `_click_history`
			{$where}
			{$order}
			SQL;
		$stm = $this->run($sql, self::statusParam($status), 'export entries');
		if ($stm === null) {
			return;
		}
		while (true) {
			/** @var mixed $row */
			$row = $stm->fetch(PDO::FETCH_ASSOC);
			if (!is_array($row)) {
				break;
			}
			/** @var array<string,mixed> $row */
			yield self::normaliseRow($row);
		}
	}

	/**
	 * Moves one entry to another triage state. Nothing else about the row is
	 * touched: the archived values are the state as of the click, and the
	 * judgement is separate from them.
	 */
	public function setStatus(string $idEntry, string $status): bool {
		if (!$this->ensureTableExists()) {
			return false;
		}
		return $this->run(
			'UPDATE `_click_history` SET status = :status WHERE id_entry = :id_entry',
			[':status' => self::normaliseStatus($status), ':id_entry' => $idEntry],
			'set the status of entry ' . $idEntry,
		) !== null;
	}

	/**
	 * How many entries sit in each triage state, for the filter links. Every
	 * state is present in the result even when nothing is in it, so a caller can
	 * render a count without checking.
	 *
	 * @return array<string,int>
	 */
	public function countByStatus(): array {
		$counts = array_fill_keys(self::STATUSES, 0);
		if (!$this->ensureTableExists()) {
			return $counts;
		}
		$stm = $this->pdo->query('SELECT status, COUNT(*) AS total FROM `_click_history` GROUP BY status');
		if ($stm === false) {
			Minz_Log::error('ClickHistory: cannot count by status: ' . json_encode($this->pdo->errorInfo()));
			return $counts;
		}
		/** @var array<string,mixed> $row */
		foreach ($stm->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
			// A value written by hand or by a later version is folded into the
			// state the rest of the code shows it as, so the totals still add up.
			$status = self::normaliseStatus(is_scalar($row['status'] ?? null) ? (string)$row['status'] : '');
			$counts[$status] += is_numeric($row['total'] ?? null) ? (int)$row['total'] : 0;
		}
		return $counts;
	}

	/**
	 * What each feed has cost and returned: one row per feed and category, with the
	 * clicks it collected and how they were judged. This is what the rating buttons
	 * are for — without it they are three clicks that lead nowhere.
	 *
	 * Not paginated and not filtered: there is one row per feed ever clicked, which
	 * is bounded by the number of subscriptions rather than by the size of the
	 * history, and every state has to be in it for the figures to mean anything.
	 *
	 * The statement itself is ClickHistorySchema::statsByFeed().
	 *
	 * @return list<ClickHistoryFeedStats>
	 */
	public function statsByFeed(): array {
		if (!$this->ensureTableExists()) {
			return [];
		}
		$stm = $this->run(
			ClickHistorySchema::statsByFeed(),
			[':good' => self::STATUS_GOOD, ':dropped' => self::STATUS_DROPPED],
			'count by feed',
		);
		if ($stm === null) {
			return [];
		}

		$rows = [];
		/** @var array<string,mixed> $row */
		foreach ($stm->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
			$rows[] = self::normaliseStatsRow($row);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return ClickHistoryFeedStats
	 */
	private static function normaliseStatsRow(array $row): array {
		$opened = is_numeric($row['opened'] ?? null) ? (int)$row['opened'] : 0;
		$good = is_numeric($row['good'] ?? null) ? (int)$row['good'] : 0;
		$dropped = is_numeric($row['dropped'] ?? null) ? (int)$row['dropped'] : 0;
		return [
			'feed_name' => is_scalar($row['feed_name'] ?? null) ? (string)$row['feed_name'] : '',
			'category_name' => is_scalar($row['category_name'] ?? null) ? (string)$row['category_name'] : '',
			'id_feed' => is_numeric($row['id_feed'] ?? null) ? (int)$row['id_feed'] : null,
			'opened' => $opened,
			'good' => $good,
			'dropped' => $dropped,
			// The remainder rather than a third SUM in the query: a status value
			// nobody here wrote — hand-edited, or left by a later version — is folded
			// into unrated by normaliseStatus() everywhere else, and counting it any
			// other way would leave the three columns not adding up to `opened`.
			'unrated' => $opened - $good - $dropped,
		];
	}

	/** An unknown value — hand-edited, or written by a later version — reads as unrated. */
	private static function normaliseStatus(string $status): string {
		return in_array($status, self::STATUSES, true) ? $status : self::STATUS_UNRATED;
	}

	/**
	 * SQLite hands back integers where MySQL hands back strings for the same
	 * columns, so callers get one shape either way.
	 *
	 * @return list<ClickHistoryRow>
	 */
	private function normalise(PDOStatement $stm): array {
		$rows = [];
		/** @var array<string,mixed> $row */
		foreach ($stm->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
			$rows[] = self::normaliseRow($row);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return ClickHistoryRow
	 */
	private static function normaliseRow(array $row): array {
		return [
			'id_entry' => is_scalar($row['id_entry'] ?? null) ? (string)$row['id_entry'] : '',
			'url' => is_scalar($row['url'] ?? null) ? (string)$row['url'] : '',
			'title' => is_scalar($row['title'] ?? null) ? (string)$row['title'] : '',
			'feed_name' => is_scalar($row['feed_name'] ?? null) ? (string)$row['feed_name'] : '',
			'id_feed' => is_numeric($row['id_feed'] ?? null) ? (int)$row['id_feed'] : null,
			'category_name' => is_scalar($row['category_name'] ?? null) ? (string)$row['category_name'] : '',
			'id_category' => is_numeric($row['id_category'] ?? null) ? (int)$row['id_category'] : null,
			'clicked_at' => is_numeric($row['clicked_at'] ?? null) ? (int)$row['clicked_at'] : 0,
			'first_clicked_at' => is_numeric($row['first_clicked_at'] ?? null) ? (int)$row['first_clicked_at'] : 0,
			'status' => self::normaliseStatus(is_scalar($row['status'] ?? null) ? (string)$row['status'] : ''),
		];
	}

	/** Must be filtered exactly like listEntries(), or the page count drifts from the rows. */
	public function count(?string $status = null): int {
		if (!$this->ensureTableExists()) {
			return 0;
		}
		$stm = $this->run(
			'SELECT COUNT(*) FROM `_click_history` ' . ClickHistorySchema::statusClause($status),
			self::statusParam($status),
			'count entries',
		);
		if ($stm === null) {
			return 0;
		}
		$value = $stm->fetchColumn();
		return is_numeric($value) ? (int)$value : 0;
	}

	public function delete(string $idEntry): bool {
		if (!$this->ensureTableExists()) {
			return false;
		}
		return $this->run(
			'DELETE FROM `_click_history` WHERE id_entry = :id_entry',
			[':id_entry' => $idEntry],
			'delete entry ' . $idEntry,
		) !== null;
	}

	public function clear(): bool {
		if (!$this->ensureTableExists()) {
			return false;
		}
		if ($this->pdo->exec('DELETE FROM `_click_history`') === false) {
			Minz_Log::error('ClickHistory: cannot clear history: ' . json_encode($this->pdo->errorInfo()));
			return false;
		}
		return true;
	}

	/**
	 * Prepares, binds and executes one statement, and logs why if any of the three
	 * fails. Each value is bound as what it is in PHP: null as NULL, an int as an
	 * integer, a string as text. That is why entry ids are strings everywhere in
	 * here: a 64-bit id bound as an integer would overflow on 32-bit PHP, so it
	 * travels as a string all the way to the BIGINT column — the same way core
	 * treats FreshRSS_Entry::id().
	 *
	 * @param array<string,string|int|null> $params placeholder => value
	 * @param string $what what the statement was for, as the log should say it
	 * @return PDOStatement|null the executed statement, null after an error
	 */
	private function run(string $sql, array $params, string $what): ?PDOStatement {
		$stm = $this->pdo->prepare($sql);
		$ok = $stm !== false;
		foreach ($params as $name => $value) {
			$type = $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
			if (!$ok || !$stm->bindValue($name, $value, $type)) {
				$ok = false;
				break;
			}
		}
		if ($ok && $stm->execute()) {
			return $stm;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('ClickHistory: cannot ' . $what . ': ' . json_encode($info));
		return null;
	}

	/**
	 * The binding that goes with ClickHistorySchema::statusClause(): none at all
	 * for every status, since the clause is empty then.
	 *
	 * @return array<string,string>
	 */
	private static function statusParam(?string $status): array {
		return $status === null ? [] : [':status' => $status];
	}
}
