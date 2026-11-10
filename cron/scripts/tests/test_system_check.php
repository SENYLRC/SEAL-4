<?php
/** Test-only helper: ill_system_findings() for a server that has everything, and for one with no mail program and no mysqli. */
require __DIR__ . '/cron_config.php';
$good = ['php' => '8.3.6', 'ext' => array_fill_keys(['mysqli', 'openssl', 'sodium', 'json', 'ctype', 'curl'], true),
         'sendmail' => '/usr/sbin/sendmail -t -i', 'sendmail_ok' => true, 'email_mode' => 'wordpress', 'log' => '/var/log/ill_cron.log',
         'log_ok' => true, 'cache_dir' => '/x', 'cache_ok' => true, 'auto_fetch' => true, 'db_inc' => '/x/ill_db.inc', 'db_inc_ok' => true,
         'db_inc_world' => false, 'timezone' => 'America/New_York', 'tz_ini' => 'America/New_York', 'user' => 'root'];
$bad = ['ext' => array_fill_keys(['openssl', 'json', 'ctype'], true), 'sendmail_ok' => false, 'db_inc_world' => true, 'php' => '7.4.3'] + $good;
$levels = static function (array $f): array { $o = []; foreach (ill_system_findings($f) as [$l, $label, $t]) { $o[$label] = [$l, $t]; } return $o; };
echo json_encode(['good' => $levels($good), 'bad' => $levels($bad), 'off' => $levels(['email_mode' => 'off'] + $bad)]);
