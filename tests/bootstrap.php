<?php
declare(strict_types=1);

// Required first by every PHP test: a notice, warning or deprecation raised in
// this repository's own code fails the run instead of scrolling past in its
// output. CI runs the tests on the lowest and on the highest PHP the extension
// claims (phpstan.neon), and a deprecation is how the newer one speaks up about
// code the older one accepts.
//
// FreshRSS core, loaded from .freshrss-core by tests/dao.php, is left to PHP's
// own handler: what a PHP release thinks of core is not this extension's finding.

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
	$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
	$ours = str_starts_with($file, $root) && !str_starts_with($file, $root . '.freshrss-core' . DIRECTORY_SEPARATOR);
	if (!$ours || (error_reporting() & $severity) === 0) {
		return false;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});
