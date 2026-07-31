<?php

return array(
	'click_history' => array(
		'menu' => 'Klick-Verlauf',
		'title' => 'Klick-Verlauf',
		'empty' => 'Noch kein Artikel geöffnet. Links, die Sie aus dem Stream öffnen, erscheinen hier.',
		'columns' => array(
			'article' => 'Artikel',
			'feed' => 'Feed',
			'category' => 'Kategorie',
			'opened' => 'Zuletzt geöffnet',
			'actions' => 'Aktionen',
		),
		'first_opened' => 'Zuerst geöffnet am %s',
		'no_category' => 'Keine Kategorie',
		'group' => array(
			'by_category' => 'Nach Kategorie gruppieren',
			'by_date' => 'Nach Datum sortieren',
		),
		'action' => array(
			'delete' => 'Löschen',
			'clear' => 'Gesamten Verlauf löschen',
			'export_json' => 'Als JSON herunterladen',
			'export_csv' => 'Als CSV herunterladen',
		),
		'conf' => array(
			'track_clicks' => 'Geöffnete Artikel aufzeichnen',
			'track_clicks_help' => 'Solange dies aus ist, wird nichts aufgezeichnet. Der bereits vorhandene Verlauf bleibt erhalten.',
			'page_size' => 'Einträge pro Seite',
			'page_size_help' => 'Zwischen %d und %d.',
		),
	),
);
