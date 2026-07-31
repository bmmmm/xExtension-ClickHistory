<?php
declare(strict_types=1);

/**
 * The view of the history page and of the JSON endpoint behind the click
 * listener. Declaring the properties (rather than setting them dynamically on a
 * plain FreshRSS_View) is what lets the templates be analysed at all.
 *
 * `currentPage` and `nbPage` are inherited from FreshRSS_View, which already
 * carries them for the core's own log page and means the same thing by them.
 */
final class ClickHistoryView extends FreshRSS_View {
	/**
	 * @var list<array{id_entry:string, url:string, title:string, feed_name:string,
	 *     id_feed:int|null, category_name:string, id_category:int|null,
	 *     clicked_at:int, first_clicked_at:int}>
	 */
	public array $history = [];
	public int $total = 0;
	public bool $byCategory = false;
	public string $exportFormat = 'json';
	/** @var array<string,mixed> */
	public array $result = [];
}

/**
 * The class name and the file name are both fixed by registerController('clickhistory'):
 * Minz_Dispatcher includes Controllers/<base>Controller.php and instantiates
 * FreshExtension_<base>_Controller (lib/Minz/Dispatcher.php). The base name has
 * to be alphanumeric because Minz_Request filters it with ctype_alnum, which is
 * why it is `clickhistory` and not `click_history`.
 *
 * No CSRF check anywhere in here: FreshRSS::initAuth() rejects every POST
 * without a valid token before a controller is ever reached, and its exemption
 * list holds only core's own login/register/refresh actions — no extension can
 * be on it.
 */
final class FreshExtension_clickhistory_Controller extends FreshRSS_ActionController {
	/**
	 * @var ClickHistoryView
	 * @phpstan-ignore property.phpDocType
	 */
	protected $view;

	public function __construct() {
		parent::__construct(ClickHistoryView::class);
	}

	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
	}

	public function indexAction(): void {
		$dao = new ClickHistoryDAO();
		$pageSize = $this->settings()['page_size'];
		$total = $dao->count();
		$nbPage = max(1, (int)ceil($total / $pageSize));
		// paramInt() yields 0 for a missing or non-numeric page, and a page past
		// the end would otherwise show an empty table rather than the last page.
		$currentPage = min(max(1, Minz_Request::paramInt('page')), $nbPage);
		$byCategory = Minz_Request::paramString('group') === 'category';

		$this->view->history = $dao->listEntries($pageSize, ($currentPage - 1) * $pageSize, $byCategory);
		$this->view->total = $total;
		$this->view->currentPage = $currentPage;
		$this->view->nbPage = $nbPage;
		$this->view->byCategory = $byCategory;

		FreshRSS_View::prependTitle(_t('ext.click_history.title') . ' · ');
	}

	/**
	 * Downloads the whole history. A plain authenticated download rather than an
	 * endpoint under /api/misc.php, because that one gates on
	 * systemConf()->extensions_enabled and so cannot see a user extension at all.
	 */
	public function exportAction(): void {
		$this->view->_layout(null);

		$format = Minz_Request::paramString('format') === 'csv' ? 'csv' : 'json';
		$rows = (new ClickHistoryDAO())->listAll(Minz_Request::paramString('group') === 'category');
		$filename = 'click-history-' . date('Y-m-d') . '.' . $format;

		header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8'));
		// The filename is built here, not taken from the request, so it needs no
		// escaping beyond the quotes it sits in.
		header('Content-Disposition: attachment; filename="' . $filename . '"');

		$this->view->exportFormat = $format;
		$this->view->history = $rows;
	}

	/**
	 * The endpoint the click listener posts to. The client sends nothing but an
	 * entry id: url, title and feed name are looked up here, so a hand-made POST
	 * cannot put arbitrary strings into the denormalised table that the history
	 * page later renders. The lookup runs in the logged-in user's own database
	 * context, so another user's entry id simply is not found.
	 */
	public function recordAction(): void {
		$this->view->_layout(null);
		header('Content-Type: application/json; charset=UTF-8');

		if (!Minz_Request::isPost()) {
			$this->fail(405, 'method_not_allowed');
			return;
		}
		if (!$this->settings()['track_clicks']) {
			// Recording is switched off. Not an error: the client caches its own
			// copy of the flag and may be a page load behind.
			$this->view->result = ['ok' => false, 'reason' => 'disabled'];
			return;
		}

		$id = Minz_Request::paramString('id');
		if (!ctype_digit($id)) {
			$this->fail(400, 'bad_id');
			return;
		}

		$entry = FreshRSS_Factory::createEntryDao()->searchById($id);
		if ($entry === null) {
			// Raced with a purge, or an id that was never this user's. Nothing is
			// recorded — deliberately no fallback to a URL supplied by the client,
			// which would reopen exactly the hole the server-side lookup closes.
			$this->fail(404, 'unknown_entry');
			return;
		}

		$feed = $entry->feed();
		// The category is copied in like the feed name, rather than resolved from
		// id_feed when the page is rendered: an entry has to keep grouping
		// correctly after its feed — or the whole category — has been deleted.
		$category = $feed?->category();
		$ok = (new ClickHistoryDAO())->record(
			$entry->id(),
			// link(), title() and name() all return values that core prints into
			// HTML unescaped, i.e. they are already HTML-encoded. The table holds
			// plain text and the view escapes on the way out, so they are decoded
			// once here — otherwise `&amp;` ends up in the stored URL and every
			// ampersand in a headline is shown doubly escaped.
			html_entity_decode($entry->link(), ENT_QUOTES, 'UTF-8'),
			html_entity_decode($entry->title(), ENT_QUOTES, 'UTF-8'),
			$feed === null ? '' : html_entity_decode($feed->name(), ENT_QUOTES, 'UTF-8'),
			$feed?->id(),
			$category === null ? '' : html_entity_decode($category->name(), ENT_QUOTES, 'UTF-8'),
			$category?->id(),
			time(),
		);

		if (!$ok) {
			$this->fail(500, 'not_recorded');
			return;
		}
		$this->view->result = ['ok' => true];
	}

	public function deleteAction(): void {
		if (!Minz_Request::isPost()) {
			Minz_Error::error(405);
			return;
		}
		$id = Minz_Request::paramString('id');
		if (ctype_digit($id)) {
			(new ClickHistoryDAO())->delete($id);
		}
		$this->backToIndex();
	}

	public function clearAction(): void {
		if (!Minz_Request::isPost()) {
			Minz_Error::error(405);
			return;
		}
		(new ClickHistoryDAO())->clear();
		$this->backToIndex();
	}

	/**
	 * A JSON endpoint answers with a status code and a JSON body. Minz_Error would
	 * redirect the request to an HTML error page instead, which the caller cannot
	 * do anything with.
	 */
	private function fail(int $status, string $reason): void {
		http_response_code($status);
		$this->view->result = ['ok' => false, 'reason' => $reason];
	}

	/**
	 * Back to the first page rather than to the page the deletion happened on:
	 * removing an entry shifts every later one forward, so the page number the
	 * form came from no longer points at what the user was looking at.
	 */
	private function backToIndex(): void {
		Minz_Request::forward(['c' => 'clickhistory', 'a' => 'index'], true);
	}

	/** @return array{track_clicks:bool, page_size:int} */
	private function settings(): array {
		$extension = ClickHistoryExtension::instance();
		// The controller is only reachable while the extension is enabled, so the
		// null branch is unreachable in practice; the defaults keep the page
		// working rather than fataling if that ever stops being true.
		return $extension === null ? ['track_clicks' => true, 'page_size' => 50] : $extension->settings();
	}
}
