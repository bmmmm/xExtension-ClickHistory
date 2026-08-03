<?php

return array(
	'click_history' => array(
		'menu' => 'Klick-Verlauf',
		'title' => 'Klick-Verlauf',
		'empty' => 'Noch kein Artikel geöffnet. Links, die Sie aus dem Stream öffnen, erscheinen hier.',
		'empty_filtered' => 'Nichts in diesem Zustand. Wählen Sie oben einen anderen.',
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
		'status' => array(
			'unrated' => 'Unbewertet',
			'good' => 'Gut',
			'dropped' => 'Verworfen',
		),
		'rate' => array(
			'good' => 'Diesen Artikel als lesenswert markieren',
			'dropped' => 'Diesen Artikel als nicht lesenswert markieren',
			'undo' => 'Zurück auf unbewertet',
		),
		'filter' => array(
			'all' => 'Alle',
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
			'default_status' => 'Zustand eines neu geöffneten Artikels',
			'default_status_help' => 'Als was ein Artikel gilt, bis Sie ihn auf der Verlaufsseite bewerten. ' .
				'»Verworfen« hält ihn nur aus dem Blickfeld, bis Sie ihn hochstufen; ' .
				'um gar nichts aufzuzeichnen, schalten Sie die Einstellung darüber aus.',
		),
	),
);
