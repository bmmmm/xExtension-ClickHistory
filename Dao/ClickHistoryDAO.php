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
 */
final class ClickHistoryDAO extends Minz_ModelPdo {
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
		if (!$this->addCategoryColumns()) {
			return false;
		}
		self::$tableChecked = true;
		return true;
	}

	/**
	 * Brings a table created by 0.1.0 up to date. Deliberately not expressed as
	 * `ADD COLUMN IF NOT EXISTS`: MySQL has no such clause (MariaDB does), so the
	 * presence of the column is probed instead — a SELECT that fails means the
	 * column is missing, which works the same on all three backends.
	 */
	private function addCategoryColumns(): bool {
		if ($this->pdo->query('SELECT category_name FROM `_click_history` LIMIT 1') !== false) {
			return true;
		}
		foreach ($this->addCategoryColumnsSql() as $sql) {
			if ($this->pdo->exec($sql) === false) {
				Minz_Log::error('ClickHistory: cannot add the category columns: ' . json_encode($this->pdo->errorInfo()));
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
		int $timestamp
	): bool {
		if (!$this->ensureTableExists()) {
			return false;
		}

		$sql = <<<'SQL'
			INSERT INTO `_click_history`
				(id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at)
			VALUES (:id_entry, :url, :title, :feed_name, :id_feed, :category_name, :id_category, :clicked_at, :first_clicked_at)
			SQL;
		// The one real dialect difference. Both branches update nothing but the
		// timestamp, so a second click cannot rewrite the archived values above.
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
			$stm->execute()) {
			return true;
		}

		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('ClickHistory: cannot record entry ' . $idEntry . ': ' . json_encode($info));
		return false;
	}

	/**
	 * One page of the history: by default most recently opened first, or grouped
	 * by category with the newest first inside each group.
	 *
	 * @return list<array{id_entry:string, url:string, title:string, feed_name:string,
	 *     id_feed:int|null, category_name:string, id_category:int|null,
	 *     clicked_at:int, first_clicked_at:int}>
	 */
	public function listEntries(int $limit, int $offset, bool $byCategory = false): array {
		if (!$this->ensureTableExists()) {
			return [];
		}

		// id_entry breaks the tie: two articles opened in the same second would
		// otherwise be free to swap places between pages and appear twice or not
		// at all. Entry ids are timestamp-derived, so this stays in click order.
		$order = $byCategory
			? 'ORDER BY category_name ASC, clicked_at DESC, id_entry DESC'
			: 'ORDER BY clicked_at DESC, id_entry DESC';
		$sql = <<<SQL
			SELECT id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at
			FROM `_click_history`
			{$order}
			LIMIT :limit OFFSET :offset
			SQL;

		$stm = $this->pdo->prepare($sql);
		if ($stm === false ||
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
	 * that silently stopped at the first page would be worse than none.
	 *
	 * @return list<array{id_entry:string, url:string, title:string, feed_name:string,
	 *     id_feed:int|null, category_name:string, id_category:int|null,
	 *     clicked_at:int, first_clicked_at:int}>
	 */
	public function listAll(bool $byCategory = false): array {
		if (!$this->ensureTableExists()) {
			return [];
		}
		$order = $byCategory
			? 'ORDER BY category_name ASC, clicked_at DESC, id_entry DESC'
			: 'ORDER BY clicked_at DESC, id_entry DESC';
		$stm = $this->pdo->query(<<<SQL
			SELECT id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at
			FROM `_click_history`
			{$order}
			SQL);
		if ($stm === false) {
			Minz_Log::error('ClickHistory: cannot export entries: ' . json_encode($this->pdo->errorInfo()));
			return [];
		}
		return $this->normalise($stm);
	}

	/**
	 * SQLite hands back integers where MySQL hands back strings for the same
	 * columns, so callers get one shape either way.
	 *
	 * @return list<array{id_entry:string, url:string, title:string, feed_name:string,
	 *     id_feed:int|null, category_name:string, id_category:int|null,
	 *     clicked_at:int, first_clicked_at:int}>
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
			];
		}
		return $rows;
	}

	public function count(): int {
		if (!$this->ensureTableExists()) {
			return 0;
		}
		$stm = $this->pdo->query('SELECT COUNT(*) FROM `_click_history`');
		if ($stm === false) {
			Minz_Log::error('ClickHistory: cannot count entries: ' . json_encode($this->pdo->errorInfo()));
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
}
