<?php
declare(strict_types=1);

// MUTANT: implicitly nullable parameter, deprecated from PHP 8.4 on.
function clickHistoryMutant(string $x = null): string {
	return (string)$x;
}
