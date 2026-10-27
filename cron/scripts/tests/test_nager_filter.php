<?php
/** Test-only helper: runs ill_filter_nager() on a trimmed copy of the real 2026 Nager.Date response. */
require __DIR__ . '/cron_config.php';
$fixture = [
    ['date' => '2026-01-01', 'name' => "New Year's Day",     'global' => true,  'counties' => null,                 'types' => ['Public', 'Bank']],
    ['date' => '2026-02-12', 'name' => "Lincoln's Birthday", 'global' => false, 'counties' => ['US-NY', 'US-CT'],   'types' => ['Observance']],
    ['date' => '2026-04-03', 'name' => 'Good Friday',        'global' => false, 'counties' => ['US-CT', 'US-NJ'],   'types' => ['Public']],
    ['date' => '2026-05-08', 'name' => 'Truman Day',         'global' => false, 'counties' => ['US-MO'],            'types' => ['School', 'Authorities']],
    ['date' => '2026-10-12', 'name' => 'Columbus Day',       'global' => false, 'counties' => ['US-NY', 'US-PA'],   'types' => ['Public']],
    ['date' => '2026-10-12', 'name' => 'Columbus Day',       'global' => true,  'counties' => null,                 'types' => ['Bank']],
    ['date' => 'garbage',    'name' => 'Bad row',            'global' => true,  'counties' => null,                 'types' => ['Public']],
];
echo json_encode(ill_filter_nager($fixture));
