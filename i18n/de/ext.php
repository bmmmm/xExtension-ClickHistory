<?php

return array(
	'click_history' => array(
		'menu' => 'Klick-Verlauf',
		'title' => 'Klick-Verlauf',
		'empty' => 'Noch kein Artikel geöffnet. Links, die Sie aus dem Stream öffnen, erscheinen hier.',
		'columns' => array(
			'article' => 'Artikel',
			'feed' => 'Feed',
			'opened' => 'Zuletzt geöffnet',
			'actions' => 'Aktionen',
		),
		'first_opened' => 'Zuerst geöffnet am %s',
		'action' => array(
			'delete' => 'Löschen',
			'clear' => 'Gesamten Verlauf löschen',
		),
		'conf' => array(
			'track_clicks' => 'Geöffnete Artikel aufzeichnen',
			'track_clicks_help' => 'Solange dies aus ist, wird nichts aufgezeichnet. Der bereits vorhandene Verlauf bleibt erhalten.',
			'page_size' => 'Einträge pro Seite',
			'page_size_help' => 'Zwischen %d und %d.',
		),
	),
);
