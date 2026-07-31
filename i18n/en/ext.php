<?php

return array(
	'click_history' => array(
		'menu' => 'Click history',
		'title' => 'Click history',
		'empty' => 'No article opened yet. Links you open from the stream show up here.',
		'columns' => array(
			'article' => 'Article',
			'feed' => 'Feed',
			'opened' => 'Last opened',
			'actions' => 'Actions',
		),
		'first_opened' => 'First opened on %s',
		'action' => array(
			'delete' => 'Delete',
			'clear' => 'Delete the whole history',
			'clear_confirm' => 'Delete the whole click history? This cannot be undone.',
		),
		'conf' => array(
			'track_clicks' => 'Record opened articles',
			'track_clicks_help' => 'While this is off, nothing is recorded. The history that already exists is kept.',
			'page_size' => 'Entries per page',
			'page_size_help' => 'Between %d and %d.',
		),
	),
);
