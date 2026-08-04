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
			'where_help' => 'Der Verlauf ist eine eigene Seite und über das Kopfmenü (oben rechts, Zahnrad) erreichbar — oder direkt öffnen:',
			'track_clicks' => 'Geöffnete Artikel aufzeichnen',
			'track_clicks_help' => 'Solange dies aus ist, wird nichts aufgezeichnet. Der bereits vorhandene Verlauf bleibt erhalten.',
			'page_size' => 'Einträge pro Seite',
			'page_size_help' => 'Zwischen %d und %d.',
		),
	),
);
