<?php
declare(strict_types=1);

require_once __DIR__ . '/Dao/ClickHistoryDAO.php';

final class ClickHistoryExtension extends Minz_Extension {
	/** Also what the controller falls back to while no instance is registered. */
	public const DEFAULTS = [
		'track_clicks' => true,
		'page_size' => 50,
	];

	private const PAGE_SIZE_MIN = 10;
	private const PAGE_SIZE_MAX = 500;

	/**
	 * Query parameters that only say where a click came from. Kept short on
	 * purpose: `ref`, `source` or `s` are tracking on one site and the article
	 * id or the search term on the next, and a copied link that no longer
	 * opens the article is worse than one with a campaign tag left in it.
	 */
	private const TRACKING_PREFIXES = ['utm_', 'mtm_', 'pk_'];
	private const TRACKING_PARAMS = [
		'fbclid', 'gclid', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'yclid', 'igshid', 'twclid', 'ttclid',
		'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'mkt_tok', 'oly_anon_id', 'oly_enc_id', 'vero_id',
	];

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

		// Both assets used to be appended to every page in the installation. The
		// routing has already happened by the time an extension is enabled for the
		// user (Minz_FrontController's constructor runs before FreshRSS::init()), so
		// the controller name is known here and each file can be limited to the
		// pages it does anything on.
		$controller = Minz_Request::controllerName();
		// Every rule in the stylesheet is under `.click-history*`, which exists
		// nowhere but this extension's own page, and the copy buttons the second
		// script serves are only rendered there. Who may see that page is the
		// controller's firstAction() to decide.
		if ($controller === 'clickhistory') {
			Minz_View::appendStyle($this->getFileUrl('style.css'));
			Minz_View::appendScript($this->getFileUrl('history.js'));
		}
		// The click listener needs the stream, which is the `index` controller in
		// all three reading modes (normal, reader, global). The extension's own
		// pages render no article markup, so the script has nothing to do there.
		//
		// Only for a logged-in user: with allow_anonymous, core enables the
		// default user's extensions for anonymous visitors too (FreshRSS::init),
		// but the controller refuses them in firstAction(), so every click would
		// be a POST into a 403 and a console error. Auth is initialised before
		// extensions are enabled, so hasAccess() already has its answer here.
		if ($controller === 'index' && FreshRSS_Auth::hasAccess()) {
			Minz_View::appendScript($this->getFileUrl('script.js'));
		}
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
		// therefore puts a hidden `0` in front of it, so the form always sends an
		// answer and only a request made some other way reaches the fallback.
		$current = $this->settings();
		$values = [
			'track_clicks' => Minz_Request::paramTernary('track_clicks') ?? $current['track_clicks'],
			'page_size' => self::clampInt(
				Minz_Request::paramIntNull('page_size'), self::PAGE_SIZE_MIN, self::PAGE_SIZE_MAX, $current['page_size'],
			),
		];

		// Written here rather than through setUserConfigurationValue(), which
		// saves the whole user configuration on every call and drops save()'s
		// answer: two fields were two writes, and a configuration that could not
		// be written still came back as "updated". Both settings are always
		// written, so nothing else under this name survives: what could be there is
		// `default_status`, left over from a version that no longer reads it.
		$conf = FreshRSS_Context::userConf();
		$extensions = $conf->hasParam('extensions') ? $conf->extensions : [];
		$extensions[$this->getName()] = $values;
		$conf->extensions = $extensions;

		$url = ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => urlencode($this->getName())]];
		if ($conf->save()) {
			Minz_Request::good(_t('feedback.conf.updated'), $url);
		} else {
			Minz_Request::bad(_t('feedback.conf.error'), $url);
		}
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
	 * @return array{track_clicks:bool, page_size:int}
	 */
	public function settings(): array {
		return [
			'track_clicks' => $this->getUserConfigurationBool('track_clicks') ?? self::DEFAULTS['track_clicks'],
			'page_size' => self::clampInt(
				$this->getUserConfigurationInt('page_size'),
				self::PAGE_SIZE_MIN, self::PAGE_SIZE_MAX, self::DEFAULTS['page_size'],
			),
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
	 * The shape rather than array<string,string>: every caller indexes it with one
	 * of the three states, so spelling the keys out is what lets that be a direct
	 * lookup instead of one guarded by a fallback that can never be reached.
	 *
	 * @return array{unrated:string, good:string, dropped:string}
	 */
	public static function statusLabels(): array {
		return [
			ClickHistoryDAO::STATUS_UNRATED => _t('ext.click_history.status.unrated'),
			ClickHistoryDAO::STATUS_GOOD => _t('ext.click_history.status.good'),
			ClickHistoryDAO::STATUS_DROPPED => _t('ext.click_history.status.dropped'),
		];
	}

	/** @return array{good:string, dropped:string} */
	public static function rateTitles(): array {
		return [
			ClickHistoryDAO::STATUS_GOOD => _t('ext.click_history.rate.good'),
			ClickHistoryDAO::STATUS_DROPPED => _t('ext.click_history.rate.dropped'),
		];
	}

	/**
	 * The link with every tracking parameter taken out of its query, for the
	 * history page's copy button. Everything else — the other parameters, their
	 * order and encoding, the fragment — stays exactly as the feed had it, which
	 * is why the query is cut up as a string: parse_str() and
	 * http_build_query() would re-encode values, turn dots in names into
	 * underscores and reorder arrays. A link without anything to remove comes
	 * back unchanged, down to a trailing `?`.
	 */
	public static function cleanUrl(string $url): string {
		$hash = strpos($url, '#');
		$fragment = $hash === false ? '' : substr($url, $hash);
		$rest = $hash === false ? $url : substr($url, 0, $hash);
		$question = strpos($rest, '?');
		if ($question === false) {
			return $url;
		}
		$pairs = explode('&', substr($rest, $question + 1));
		$kept = array_filter($pairs, static fn(string $pair): bool => !self::isTrackingParam($pair));
		if (count($kept) === count($pairs)) {
			return $url;
		}
		$query = implode('&', $kept);
		return substr($rest, 0, $question) . ($query === '' ? '' : '?' . $query) . $fragment;
	}

	/** `UTM_Source=…` and `%75tm_source=…` are the same parameter to the site that reads them. */
	private static function isTrackingParam(string $pair): bool {
		$name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
		if (in_array($name, self::TRACKING_PARAMS, true)) {
			return true;
		}
		foreach (self::TRACKING_PREFIXES as $prefix) {
			if (str_starts_with($name, $prefix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How much of what was judged from a feed turned out to be worth reading, as a
	 * value between 0 and 1 — or null when nothing has been judged yet.
	 *
	 * Null rather than 0: a feed nobody has rated has no ratio, and rendering that
	 * as 0% would read as "everything from it was a waste", which is a statement
	 * about the reader rather than about the feed. The unrated ones are deliberately
	 * not in the denominator either — they would drag every ratio towards zero and
	 * only recover as the backlog is worked through, so a feed's number would move
	 * without a single new judgement being made. The unrated column stands next to
	 * this one instead, where the size of that backlog can be seen directly.
	 *
	 * In PHP rather than in SQL because "does not exist" is not a number, and any
	 * SQL expression would have to pick one.
	 */
	public static function worthItRatio(int $good, int $dropped): ?float {
		$judged = $good + $dropped;
		return $judged === 0 ? null : $good / $judged;
	}

	/** A missing value falls back to $fallback; one out of range is pulled to the nearest bound. */
	private static function clampInt(?int $value, int $min, int $max, int $fallback): int {
		if ($value === null) {
			return $fallback;
		}
		return max($min, min($max, $value));
	}
}
