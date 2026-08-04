<?php

return array(
	'click_history' => array(
		'menu' => 'Click history',
		'title' => 'Click history',
		'empty' => 'No article opened yet. Links you open from the stream show up here.',
		'empty_filtered' => 'Nothing in this state. Pick another one above.',
		'columns' => array(
			'article' => 'Article',
			'feed' => 'Feed',
			'category' => 'Category',
			'opened' => 'Last opened',
			'actions' => 'Actions',
		),
		'first_opened' => 'First opened on %s',
		'no_category' => 'No category',
		'group' => array(
			'by_category' => 'Group by category',
			'by_date' => 'Sort by date',
		),
		'status' => array(
			'unrated' => 'Unrated',
			'good' => 'Good',
			'dropped' => 'Dropped',
		),
		'rate' => array(
			'good' => 'Mark this article as worth reading',
			'dropped' => 'Mark this article as not worth reading',
			'undo' => 'Back to unrated',
		),
		'filter' => array(
			'all' => 'All',
		),
		'action' => array(
			'delete' => 'Delete',
			'clear' => 'Delete the whole history',
			'export_json' => 'Download as JSON',
			'export_csv' => 'Download as CSV',
		),
		'conf' => array(
			'where_help' => 'The history is a page of its own, in the header menu (top right, gear icon) next to “Logs” and “About” — or open it directly:',
			'track_clicks' => 'Record opened articles',
			'track_clicks_help' => 'While this is off, nothing is recorded. The history that already exists is kept.',
			'page_size' => 'Entries per page',
			'page_size_help' => 'Between %d and %d.',
		),
	),
);
