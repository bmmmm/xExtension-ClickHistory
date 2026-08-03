<?php
declare(strict_types=1);

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

	/** @var list<string> */
	public const STATUSES = [self::STATUS_UNRATED, self::STATUS_GOOD, self::STATUS_DROPPED];

	/**
	 * Once per process: install() creates the table, but an installation where
	 * that never ran (enabled by hand, restored from a backup) would otherwise
	 * fail on every single request instead of repairing itself once.
	 */
	private static bool $tableChecked = false;

	/** @return bool false if the table is missing and could not be created */
	public function ensureTableExists(): bool {
		if (self::$tableChecked) {
			return true;
		}
		foreach ($this->createTableSql() as $sql) {
			if ($this->pdo->exec($sql) === false) {
				Minz_Log::error('ClickHistory: cannot create table: ' . json_encode($this->pdo->errorInfo()));
				return false;
			}
		}
		// In the order the columns were introduced. Each probe is independent, so a
		// table stuck at any earlier version catches up in one pass.
		if (!$this->ensureColumns('category_name', $this->addCategoryColumnsSql()) ||
			!$this->ensureColumns('status', $this->addStatusColumnSql())) {
			return false;
		}
		self::$tableChecked = true;
		return true;
	}

	/**
	 * Adds columns a table created by an earlier version does not have yet.
	 * Deliberately not expressed as `ADD COLUMN IF NOT EXISTS`: MySQL has no such
	 * clause (MariaDB does), so the presence of one column is probed instead — a
	 * SELECT that fails means it is missing, which works the same on all three
	 * backends.
	 *
	 * @param string $probe the column whose absence means the statements must run
	 * @param list<string> $statements
	 */
	private function ensureColumns(string $probe, array $statements): bool {
		if ($this->pdo->query("SELECT {$probe} FROM `_click_history` LIMIT 1") !== false) {
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
	 * forward. `url`, `title` and `feed_name` are never overwritten: they are the
	 * state as of the first click, which is what an archive should show even
	 * after a feed has retitled the article.
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
		string $status = self::STATUS_UNRATED
	): bool {
		if (!$this->ensureTableExists()) {
			return false;
		}

		$sql = <<<'SQL'
			INSERT INTO `_click_history`
				(id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at, status)
			VALUES (:id_entry, :url, :title, :feed_name, :id_feed, :category_name, :id_category, :clicked_at, :first_clicked_at, :status)
			SQL;
		// The one real dialect difference. Both branches update nothing but the
		// timestamp, so a second click cannot rewrite the archived values above.
		// `status` in particular has to stay out of the UPDATE: reopening an
		// article the user has already judged must not throw that judgement away.
		$sql .= "\n" . ($this->pdo->dbType() === 'mysql'
			// VALUES() is deprecated from MySQL 8.0.20 in favour of an alias, but
			// the alias syntax does not exist before it and FreshRSS supports 5.7.
			? 'ON DUPLICATE KEY UPDATE clicked_at = VALUES(clicked_at)'
			: 'ON CONFLICT (id_entry) DO UPDATE SET clicked_at = excluded.clicked_at');

		$stm = $this->pdo->prepare($sql);
		if ($stm !== false &&
			// A 64-bit id bound as an integer would overflow on 32-bit PHP, so it
			// travels as a string all the way to the BIGINT column — the same way
			// core treats FreshRSS_Entry::id().
			$stm->bindValue(':id_entry', $idEntry, PDO::PARAM_STR) &&
			$stm->bindValue(':url', $url, PDO::PARAM_STR) &&
			$stm->bindValue(':title', $title, PDO::PARAM_STR) &&
			$stm->bindValue(':feed_name', $feedName, PDO::PARAM_STR) &&
			$stm->bindValue(':id_feed', $idFeed, $idFeed === null ? PDO::PARAM_NULL : PDO::PARAM_INT) &&
			$stm->bindValue(':category_name', $categoryName, PDO::PARAM_STR) &&
			$stm->bindValue(':id_category', $idCategory, $idCategory === null ? PDO::PARAM_NULL : PDO::PARAM_INT) &&
			$stm->bindValue(':clicked_at', $timestamp, PDO::PARAM_INT) &&
			$stm->bindValue(':first_clicked_at', $timestamp, PDO::PARAM_INT) &&
			$stm->bindValue(':status', self::normaliseStatus($status), PDO::PARAM_STR) &&
			$stm->execute()) {
			return true;
		}

		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('ClickHistory: cannot record entry ' . $idEntry . ': ' . json_encode($info));
		return false;
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

		$sql = <<<SQL
			SELECT id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at, status
			FROM `_click_history`
			{$this->statusClause($status)}
			{$this->orderClause($byCategory)}
			LIMIT :limit OFFSET :offset
			SQL;

		$stm = $this->pdo->prepare($sql);
		if ($stm === false ||
			($status !== null && !$stm->bindValue(':status', $status, PDO::PARAM_STR)) ||
			!$stm->bindValue(':limit', $limit, PDO::PARAM_INT) ||
			!$stm->bindValue(':offset', $offset, PDO::PARAM_INT) ||
			!$stm->execute()) {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('ClickHistory: cannot list entries: ' . json_encode($info));
			return [];
		}

		return $this->normalise($stm);
	}

	/**
	 * The whole history, for the export. Deliberately not paginated: an export
	 * that silently stopped at the first page would be worse than none. The
	 * status filter travels with it, so what is downloaded is what was on screen.
	 *
	 * @return list<ClickHistoryRow>
	 */
	public function listAll(bool $byCategory = false, ?string $status = null): array {
		if (!$this->ensureTableExists()) {
			return [];
		}
		$sql = <<<SQL
			SELECT id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at, status
			FROM `_click_history`
			{$this->statusClause($status)}
			{$this->orderClause($byCategory)}
			SQL;
		$stm = $this->pdo->prepare($sql);
		if ($stm === false ||
			($status !== null && !$stm->bindValue(':status', $status, PDO::PARAM_STR)) ||
			!$stm->execute()) {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('ClickHistory: cannot export entries: ' . json_encode($info));
			return [];
		}
		return $this->normalise($stm);
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
		$stm = $this->pdo->prepare('UPDATE `_click_history` SET status = :status WHERE id_entry = :id_entry');
		if ($stm !== false &&
			$stm->bindValue(':status', self::normaliseStatus($status), PDO::PARAM_STR) &&
			$stm->bindValue(':id_entry', $idEntry, PDO::PARAM_STR) &&
			$stm->execute()) {
			return true;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('ClickHistory: cannot set the status of entry ' . $idEntry . ': ' . json_encode($info));
		return false;
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
	 * id_entry breaks the tie: two articles opened in the same second would
	 * otherwise be free to swap places between pages and appear twice or not at
	 * all. Entry ids are timestamp-derived, so this stays in click order.
	 */
	private function orderClause(bool $byCategory): string {
		return $byCategory
			? 'ORDER BY category_name ASC, clicked_at DESC, id_entry DESC'
			: 'ORDER BY clicked_at DESC, id_entry DESC';
	}

	/** Interpolated, but never with a caller's value — the status itself is bound. */
	private function statusClause(?string $status): string {
		return $status === null ? '' : 'WHERE status = :status';
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
			$rows[] = [
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
		return $rows;
	}

	/** Must be filtered exactly like listEntries(), or the page count drifts from the rows. */
	public function count(?string $status = null): int {
		if (!$this->ensureTableExists()) {
			return 0;
		}
		$stm = $this->pdo->prepare('SELECT COUNT(*) FROM `_click_history` ' . $this->statusClause($status));
		if ($stm === false ||
			($status !== null && !$stm->bindValue(':status', $status, PDO::PARAM_STR)) ||
			!$stm->execute()) {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('ClickHistory: cannot count entries: ' . json_encode($info));
			return 0;
		}
		$value = $stm->fetchColumn();
		return is_numeric($value) ? (int)$value : 0;
	}

	public function delete(string $idEntry): bool {
		if (!$this->ensureTableExists()) {
			return false;
		}
		$stm = $this->pdo->prepare('DELETE FROM `_click_history` WHERE id_entry = :id_entry');
		if ($stm !== false && $stm->bindValue(':id_entry', $idEntry, PDO::PARAM_STR) && $stm->execute()) {
			return true;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('ClickHistory: cannot delete entry ' . $idEntry . ': ' . json_encode($info));
		return false;
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
	 * The statements that create the table, in order. Split into one statement
	 * per element because PDO::exec() only runs the first one on some drivers.
	 *
	 * Three things differ per dialect and nothing else: the column types, the
	 * table options, and where the index on `clicked_at` goes — MySQL has no
	 * `CREATE INDEX IF NOT EXISTS`, so there it has to be declared inline.
	 *
	 * There is deliberately no FOREIGN KEY to `_entry`: with ON DELETE CASCADE,
	 * purging an article would delete its history entry (the opposite of what
	 * this extension is for), and without it the purge would be blocked instead.
	 *
	 * `url` and `title` stay TEXT even on MySQL. Only `clicked_at` is ever
	 * indexed, and a VARCHAR long enough for real article links would run into
	 * InnoDB's row-size limit for nothing — core keeps `_entry.link` unindexed
	 * for the same reason.
	 *
	 * @return list<string>
	 */
	private function createTableSql(): array {
		switch ($this->pdo->dbType()) {
			case 'mysql':
				return [
					<<<'SQL'
						CREATE TABLE IF NOT EXISTS `_click_history` (
							`id_entry` BIGINT NOT NULL,
							`url` TEXT NOT NULL,
							`title` TEXT NOT NULL,
							`feed_name` VARCHAR(255) NOT NULL,
							`id_feed` INT,
							`category_name` VARCHAR(255) NOT NULL DEFAULT '',
							`id_category` INT,
							`clicked_at` BIGINT NOT NULL,
							`first_clicked_at` BIGINT NOT NULL,
							`status` VARCHAR(16) NOT NULL DEFAULT 'unrated',
							PRIMARY KEY (`id_entry`),
							INDEX (`clicked_at`)
						) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
						ENGINE = INNODB
						SQL,
				];
			case 'pgsql':
				return [
					<<<'SQL'
						CREATE TABLE IF NOT EXISTS `_click_history` (
							`id_entry` BIGINT NOT NULL,
							`url` TEXT NOT NULL,
							`title` TEXT NOT NULL,
							`feed_name` TEXT NOT NULL,
							`id_feed` INT,
							`category_name` TEXT NOT NULL DEFAULT '',
							`id_category` INT,
							`clicked_at` BIGINT NOT NULL,
							`first_clicked_at` BIGINT NOT NULL,
							`status` TEXT NOT NULL DEFAULT 'unrated',
							PRIMARY KEY (`id_entry`)
						)
						SQL,
					'CREATE INDEX IF NOT EXISTS `_click_history_clicked_at_index` ON `_click_history`(`clicked_at`)',
				];
			default:
				return [
					<<<'SQL'
						CREATE TABLE IF NOT EXISTS `_click_history` (
							`id_entry` BIGINT NOT NULL,
							`url` TEXT NOT NULL,
							`title` TEXT NOT NULL,
							`feed_name` TEXT NOT NULL,
							`id_feed` INTEGER,
							`category_name` TEXT NOT NULL DEFAULT '',
							`id_category` INTEGER,
							`clicked_at` INTEGER NOT NULL,
							`first_clicked_at` INTEGER NOT NULL,
							`status` TEXT NOT NULL DEFAULT 'unrated',
							PRIMARY KEY (`id_entry`)
						)
						SQL,
					'CREATE INDEX IF NOT EXISTS `_click_history_clicked_at_index` ON `_click_history`(`clicked_at`)',
				];
		}
	}

	/**
	 * Upgrades a table created before the category was recorded. A DEFAULT is
	 * required rather than merely convenient: SQLite refuses to add a NOT NULL
	 * column without one, and the rows already in the table have no category to
	 * put there — the feed they came from may not even exist any more.
	 *
	 * @return list<string>
	 */
	private function addCategoryColumnsSql(): array {
		$nameType = $this->pdo->dbType() === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
		$intType = $this->pdo->dbType() === 'sqlite' ? 'INTEGER' : 'INT';
		return [
			"ALTER TABLE `_click_history` ADD COLUMN `category_name` {$nameType} NOT NULL DEFAULT ''",
			"ALTER TABLE `_click_history` ADD COLUMN `id_category` {$intType}",
		];
	}

	/**
	 * Upgrades a table created before articles could be judged. Everything already
	 * recorded becomes unrated, which is the honest answer: those clicks happened
	 * before there was anything to say about them.
	 *
	 * The DEFAULT is not merely convenient — SQLite refuses to add a NOT NULL
	 * column without one. It is spelled out rather than interpolated from the
	 * constant because it also has to sit in the CREATE TABLE statements above,
	 * where the SQL is a nowdoc.
	 *
	 * @return list<string>
	 */
	private function addStatusColumnSql(): array {
		$type = $this->pdo->dbType() === 'mysql' ? 'VARCHAR(16)' : 'TEXT';
		return ["ALTER TABLE `_click_history` ADD COLUMN `status` {$type} NOT NULL DEFAULT 'unrated'"];
	}
}
