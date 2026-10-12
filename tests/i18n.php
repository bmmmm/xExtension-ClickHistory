<?php
declare(strict_types=1);

// Every language defines exactly the translation keys the extension uses: none
// missing, none left over. A missing key does not fail loudly at runtime —
// FreshRSS renders the key itself, so an untranslated page looks like a typo
// rather than a bug — and a key nothing uses any more, or that only one language
// has, is a translation that has drifted from the code.
//
// The views also borrow a few of core's own strings (gen.action.*, the log
// pagination). Those are looked up in core's English file, so a key core renames
// or drops fails here instead of showing up as a raw key on the page. Needs
// FreshRSS core in .freshrss-core, the same checkout PHPStan uses.
//
//   php tests/i18n.php

require_once __DIR__ . '/bootstrap.php';

/**
 * @param array<mixed> $node
 * @return list<string> the keys of the leaves, dot-joined
 */
function clickHistoryFlatKeys(array $node, string $prefix = ''): array {
	$keys = [];
	foreach ($node as $key => $value) {
		if (is_array($value)) {
			array_push($keys, ...clickHistoryFlatKeys($value, $prefix . $key . '.'));
		} else {
			$keys[] = $prefix . $key;
		}
	}
	return $keys;
}

$root = dirname(__DIR__);
$source = '';
// Both depths: views/<controller>/<action>.phtml and the helpers, which sit one
// level deeper under views/helpers/<controller>/.
$files = array_merge(
	[$root . '/extension.php', $root . '/configure.phtml'],
	glob($root . '/Controllers/*.php') ?: [],
	glob($root . '/views/*/*.phtml') ?: [],
	glob($root . '/views/*/*/*.phtml') ?: [],
);
foreach ($files as $file) {
	$source .= (string)file_get_contents($file);
}
preg_match_all('/_t\(.ext\.click_history\.([a-z_.]+)./', $source, $matches);
$used = array_values(array_unique($matches[1]));
sort($used);
if (count($used) < 10) {
	fwrite(STDERR, 'only ' . count($used) . " keys found - did the pattern stop matching?\n");
	exit(1);
}
echo count($used), " keys used\n";

$languages = glob($root . '/i18n/*/ext.php') ?: [];
if (count($languages) < 2) {
	fwrite(STDERR, 'only ' . count($languages) . " language file(s) found\n");
	exit(1);
}

$failures = 0;
foreach ($languages as $file) {
	$name = substr($file, strlen($root) + 1);
	$strings = require $file;
	$tree = is_array($strings) && is_array($strings['click_history'] ?? null) ? $strings['click_history'] : [];
	$defined = clickHistoryFlatKeys($tree);
	$missing = array_diff($used, $defined);
	$unused = array_diff($defined, $used);
	foreach ($missing as $key) {
		echo "FAIL {$name} is missing click_history.{$key}\n";
	}
	foreach ($unused as $key) {
		echo "FAIL {$name} defines click_history.{$key}, which nothing uses\n";
	}
	if ($missing === [] && $unused === []) {
		echo "ok   {$name}\n";
	} else {
		$failures++;
	}
}

// --- Core's strings -------------------------------------------------------------

$coreI18n = $root . '/.freshrss-core/app/i18n/en';
if (!is_dir($coreI18n)) {
	fwrite(STDERR, "FreshRSS core is missing: git clone --depth 1 --branch 1.29.0 https://github.com/FreshRSS/FreshRSS .freshrss-core\n");
	exit(1);
}
preg_match_all('/_t\(.((?!ext\.)[a-z]+)\.([a-zA-Z0-9_.]+)./', $source, $matches, PREG_SET_ORDER);
$coreKeys = [];
foreach ($matches as $match) {
	$coreKeys[$match[1] . '.' . $match[2]] = [$match[1], $match[2]];
}
ksort($coreKeys);
if (count($coreKeys) < 5) {
	fwrite(STDERR, 'only ' . count($coreKeys) . " core keys found - did the pattern stop matching?\n");
	exit(1);
}
$coreFiles = [];
foreach ($coreKeys as $key => [$file, $path]) {
	$coreFiles[$file] ??= is_file("{$coreI18n}/{$file}.php") ? require "{$coreI18n}/{$file}.php" : [];
	$defined = is_array($coreFiles[$file]) && in_array($path, clickHistoryFlatKeys($coreFiles[$file]), true);
	echo $defined ? 'ok   ' : 'FAIL ', "core defines {$key}\n";
	if (!$defined) {
		$failures++;
	}
}

exit($failures === 0 ? 0 : 1);
