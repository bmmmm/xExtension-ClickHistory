<?php
declare(strict_types=1);

/**
 * Every SQL string the extension runs, per dialect and with nothing else in it.
 *
 * Split out of ClickHistoryDAO so that it can be executed without one: the DAO
 * extends Minz_ModelPdo and needs a FreshRSS context, which a test harness has
 * no way of standing up. tests/schema.php requires this file and runs these
 * exact strings against a real database, so the schema upgrade — which happens
 * once per installation and is invisible until it has already cost somebody
 * their history — is checked rather than described.
 *
 * The table is written as `_click_history` throughout: Minz_Pdo::autoPrefix()
 * replaces the leading backtick-underscore with the real prefix, which is empty
 * for SQLite (one database file per user) and `<prefix><user>_` for MySQL and
 * PostgreSQL, and Minz_PdoPgsql additionally turns the backticks into double
 * quotes. Anything running these outside FreshRSS has to do the same.
 *
 * `$dbType` is Minz_Pdo::dbType(): `mysql`, `pgsql`, or `sqlite`. Anything else
 * is treated as SQLite, which is what the DAO did when the switch lived there.
 */
final class ClickHistorySchema {
	/** The columns every read returns, in the order the row shape declares them. */
	public const COLUMNS = 'id_entry, url, title, feed_name, id_feed, category_name, '
		. 'id_category, clicked_at, first_clicked_at, status';

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
	public static function createTable(string $dbType): array {
		switch ($dbType) {
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
	 * Whether a table created by an earlier version already has a given column.
	 * Deliberately not expressed as `ADD COLUMN IF NOT EXISTS`: MySQL has no such
	 * clause (MariaDB does), so the presence of one column is probed instead — a
	 * SELECT that fails means it is missing, which works the same on all three
	 * backends.
	 *
	 * The column name never comes from a request; the two callers pass a literal.
	 */
	public static function columnProbe(string $column): string {
		return "SELECT {$column} FROM `_click_history` LIMIT 1";
	}

	/**
	 * Upgrades a table created before the category was recorded. A DEFAULT is
	 * required rather than merely convenient: SQLite refuses to add a NOT NULL
	 * column without one, and the rows already in the table have no category to
	 * put there — the feed they came from may not even exist any more.
	 *
	 * @return list<string>
	 */
	public static function addCategoryColumns(string $dbType): array {
		$nameType = $dbType === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
		$intType = $dbType === 'sqlite' ? 'INTEGER' : 'INT';
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
	 * column without one. It is spelled out rather than interpolated from
	 * ClickHistoryDAO::STATUS_UNRATED because it also has to sit in the CREATE
	 * TABLE statements above, where the SQL is a nowdoc.
	 *
	 * @return list<string>
	 */
	public static function addStatusColumn(string $dbType): array {
		$type = $dbType === 'mysql' ? 'VARCHAR(16)' : 'TEXT';
		return ["ALTER TABLE `_click_history` ADD COLUMN `status` {$type} NOT NULL DEFAULT 'unrated'"];
	}

	/**
	 * Records one opened article, or moves an existing entry's `clicked_at`
	 * forward. `url`, `title` and `feed_name` are never overwritten: they are the
	 * state as of the first click, which is what an archive should show even after
	 * a feed has retitled the article.
	 *
	 * The conflict clause is the one real dialect difference. Both branches update
	 * nothing but the timestamp, so a second click cannot rewrite the archived
	 * values. `status` in particular has to stay out of the UPDATE: reopening an
	 * article the user has already judged must not throw that judgement away.
	 */
	public static function record(string $dbType): string {
		$sql = <<<'SQL'
			INSERT INTO `_click_history`
				(id_entry, url, title, feed_name, id_feed, category_name, id_category, clicked_at, first_clicked_at, status)
			VALUES (:id_entry, :url, :title, :feed_name, :id_feed, :category_name, :id_category, :clicked_at, :first_clicked_at, :status)
			SQL;
		return $sql . "\n" . ($dbType === 'mysql'
			// VALUES() is deprecated from MySQL 8.0.20 in favour of an alias, but
			// the alias syntax does not exist before it and FreshRSS supports 5.7.
			? 'ON DUPLICATE KEY UPDATE clicked_at = VALUES(clicked_at)'
			: 'ON CONFLICT (id_entry) DO UPDATE SET clicked_at = excluded.clicked_at');
	}

	/**
	 * id_entry breaks the tie: two articles opened in the same second would
	 * otherwise be free to swap places between pages and appear twice or not at
	 * all. Entry ids are timestamp-derived, so this stays in click order.
	 */
	public static function orderClause(bool $byCategory): string {
		return $byCategory
			? 'ORDER BY category_name ASC, clicked_at DESC, id_entry DESC'
			: 'ORDER BY clicked_at DESC, id_entry DESC';
	}

	/** Interpolated, but never with a caller's value — the status itself is bound. */
	public static function statusClause(?string $status): string {
		return $status === null ? '' : 'WHERE status = :status';
	}
}
