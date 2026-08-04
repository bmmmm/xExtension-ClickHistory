<?php
declare(strict_types=1);

require_once __DIR__ . '/Dao/ClickHistoryDAO.php';

final class ClickHistoryExtension extends Minz_Extension {
	private const DEFAULTS = [
		'track_clicks' => true,
		'page_size' => 50,
		'default_status' => ClickHistoryDAO::STATUS_UNRATED,
	];

	private const PAGE_SIZE_MIN = 10;
	private const PAGE_SIZE_MAX = 500;

	private static ?self $instance = null;

	/**
	 * The controller needs the settings, and Minz_ExtensionManager::findExtension()
	 * would only find this by its display name from metadata.json — a string a
	 * user is free to change. The instance registers itself here instead.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	#[\Override]
	public function init(): void {
		parent::init();
		self::$instance = $this;

		$this->registerController('clickhistory');
		$this->registerViews();
		$this->registerTranslates();
		$this->registerHook(Minz_HookType::JsVars, [$this, 'jsVars']);
		$this->registerHook(Minz_HookType::MenuOtherEntry, [$this, 'menuEntry']);

		Minz_View::appendStyle($this->getFileUrl('style.css'));
		Minz_View::appendScript($this->getFileUrl('script.js'));
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();

		// Extensions are only init()ed once they are enabled, while this action is
		// reached for any listed one, so the translations are registered here too.
		$this->registerTranslates();

		if (!Minz_Request::isPost()) {
			return;
		}

		// Every value falls back to what is already stored rather than to the
		// default, so a request carrying only some of the fields cannot silently
		// reset the rest. An unchecked checkbox sends nothing at all, which would
		// make track_clicks the one field that breaks that rule — configure.phtml
		// therefore puts a hidden `0` in front of it, so it is always present and
		// paramBoolean() reads an answer rather than an absence.
		$current = $this->settings();

		$this->setUserConfigurationValue('track_clicks', Minz_Request::paramBoolean('track_clicks'));
		$this->setUserConfigurationValue('page_size', self::clampInt(
			Minz_Request::paramIntNull('page_size'), self::PAGE_SIZE_MIN, self::PAGE_SIZE_MAX, $current['page_size'],
		));
		$submitted = Minz_Request::paramString('default_status');
		$this->setUserConfigurationValue('default_status', in_array($submitted, ClickHistoryDAO::STATUSES, true)
			? $submitted : $current['default_status']);

		Minz_Request::good(_t('feedback.conf.updated'), [
			'c' => 'extension', 'a' => 'configure', 'params' => ['e' => urlencode($this->getName())],
		]);
	}

	/**
	 * `metadata.json` says `"type": "user"` on purpose: user extensions are
	 * enabled per logged-in user, so this runs in that user's database context
	 * and every user ends up with their own table. As a system extension it
	 * would only ever run for the admin who flipped the switch.
	 *
	 * @return string|true true on success, an explanation otherwise
	 */
	#[\Override]
	public function install() {
		try {
			if (!(new ClickHistoryDAO())->ensureTableExists()) {
				return 'Click History: the history table could not be created, see the FreshRSS logs.';
			}
		} catch (Exception $e) {
			return 'Click History: ' . $e->getMessage();
		}
		return true;
	}

	/**
	 * Deliberately does not drop the table. FreshRSS calls uninstall() when an
	 * extension is merely *disabled* in the extensions screen, not only when its
	 * files are removed (app/Controllers/extensionController.php), so dropping
	 * here would let one stray click destroy the entire archive. Getting rid of
	 * the data is what the "delete the whole history" button is for.
	 *
	 * @return string|true
	 */
	#[\Override]
	public function uninstall() {
		return true;
	}

	/**
	 * The history page is reachable from every page, so the entry goes into the
	 * header dropdown rather than into the stream's own nav menu: nav_menu.phtml
	 * is only loaded from the three stream views, so a link placed there would
	 * disappear the moment it is followed.
	 */
	public function menuEntry(): string {
		// Deliberately text only, no icon: every other entry in this dropdown
		// (Logs, About, the configuration list) is plain text, and an icon here
		// would be the one thing in the menu that has one.
		$active = Minz_Request::controllerName() === 'clickhistory' ? ' active' : '';
		return '<li class="item' . $active . '"><a href="' . _url('clickhistory', 'index') . '">'
			. htmlspecialchars(_t('ext.click_history.menu'), ENT_COMPAT, 'UTF-8') . '</a></li>';
	}

	/**
	 * Only the on/off switch travels: the script sends an entry id and nothing
	 * else, so it needs no strings and no other setting. Checking it server-side
	 * alone would leave the listener firing into requests that are then ignored.
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function jsVars(array $vars): array {
		$vars['click_history'] = [
			'track_clicks' => $this->settings()['track_clicks'],
			// getFileUrl()-style HTML escaping is wrong here: this ends up in JSON
			// and is used verbatim by fetch().
			'record_url' => html_entity_decode(_url('clickhistory', 'record'), ENT_QUOTES),
		];
		return $vars;
	}

	/**
	 * The stored settings, validated. Values written by an earlier version or by
	 * hand are corrected here too, so the view and the JS context can trust them.
	 *
	 * @return array{track_clicks:bool, page_size:int, default_status:string}
	 */
	public function settings(): array {
		$status = $this->getUserConfigurationString('default_status');
		return [
			'track_clicks' => $this->getUserConfigurationBool('track_clicks') ?? self::DEFAULTS['track_clicks'],
			'page_size' => self::clampInt(
				$this->getUserConfigurationInt('page_size'),
				self::PAGE_SIZE_MIN, self::PAGE_SIZE_MAX, self::DEFAULTS['page_size'],
			),
			'default_status' => in_array($status, ClickHistoryDAO::STATUSES, true)
				? $status : self::DEFAULTS['default_status'],
		];
	}

	/** @return array{int, int} */
	public function pageSizeRange(): array {
		return [self::PAGE_SIZE_MIN, self::PAGE_SIZE_MAX];
	}

	/**
	 * The name of each triage state, and the tooltip for the button that sets it.
	 *
	 * Spelled out rather than built from the constant with `'…status.' . $status`:
	 * a key assembled at runtime cannot be found by the check that every string
	 * the extension asks for exists in every language (.github/workflows/ci.yml),
	 * so a missing translation would only show up as the raw key on the page.
	 *
	 * @return array<string,string>
	 */
	public static function statusLabels(): array {
		return [
			ClickHistoryDAO::STATUS_UNRATED => _t('ext.click_history.status.unrated'),
			ClickHistoryDAO::STATUS_GOOD => _t('ext.click_history.status.good'),
			ClickHistoryDAO::STATUS_DROPPED => _t('ext.click_history.status.dropped'),
		];
	}

	/** @return array<string,string> */
	public static function rateTitles(): array {
		return [
			ClickHistoryDAO::STATUS_GOOD => _t('ext.click_history.rate.good'),
			ClickHistoryDAO::STATUS_DROPPED => _t('ext.click_history.rate.dropped'),
		];
	}

	/** A missing value falls back to $fallback; one out of range is pulled to the nearest bound. */
	private static function clampInt(?int $value, int $min, int $max, int $fallback): int {
		if ($value === null) {
			return $fallback;
		}
		return max($min, min($max, $value));
	}
}
