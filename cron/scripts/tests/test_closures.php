<?php
/**
 * Test-only helper (not a real cron script): prints what ill_fetch_wp_closures() and
 * ill_get_holidays() return, as JSON, so tests/run.php can assert against them the same way it
 * asserts against SQL/emails from the real scripts. Copied into the test's temp folder alongside
 * the real scripts; never installed on the server.
 */
require __DIR__ . '/cron_config.php';

$closures = ill_fetch_wp_closures();
sort($closures);

$holidays = ill_get_holidays();
sort($holidays);

echo json_encode(['closures' => $closures, 'holidays' => $holidays]);
