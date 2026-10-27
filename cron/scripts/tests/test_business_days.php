<?php
/** Test-only helper: prints business-day results for tests/run.php. Holidays = real 2026/2027 federal list + Winter Breaks. */
require __DIR__ . '/cron_config.php';
$federal = ['2026-01-01','2026-01-19','2026-02-16','2026-05-25','2026-06-19','2026-07-03','2026-09-07','2026-10-12','2026-11-11','2026-11-26','2026-12-25',
            '2027-01-01','2027-01-18','2027-02-15','2027-05-31','2027-06-18','2027-07-05','2027-09-06','2027-10-11','2027-11-11','2027-11-25','2027-12-24'];
$h = array_merge($federal, ill_winter_break(2025), ill_winter_break(2026), ill_winter_break(2027));
$cases = [];
foreach ([['2026-10-05',3],['2026-10-08',3],['2026-10-02',3],['2026-10-03',3],['2026-10-04',3],['2026-12-22',5],['2026-12-31',3],['2026-12-23',3],
          ['2026-11-25',3],['2026-10-30',3],['2027-03-12',3],['2028-02-25',3],['2026-12-31 23:59:59',3],['2027-12-22',5]] as [$d, $n]) {
    $cases["$d+$n"] = addBusinessDays($d, $n, $h);
}
echo json_encode([
    'cases'    => $cases,
    'weekend'  => [isBusinessDay('2026-10-03', []), isBusinessDay('2026-10-04', []), isBusinessDay('2026-10-05', [])],
    'years'    => [ill_holiday_years(strtotime('2026-12-15 12:00')), ill_holiday_years(strtotime('2027-01-04 12:00')), ill_holiday_years(strtotime('2026-11-30 12:00'))],
    'winter'   => ill_winter_break(2026),
    'calendar' => ill_get_holidays(),   // online fetch is off in the test config
    'thisyear' => (int) date('Y'),
]);
