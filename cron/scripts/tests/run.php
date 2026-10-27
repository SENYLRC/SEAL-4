<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }   // test runner: command line only (it runs shell commands)
/**
 * Runs every scheduled script end to end against a fake database (shim.php), a fake sendmail
 * (capture_mail.sh) and a fake curl, then checks the SQL it issued and the emails it sent.
 * No MySQL, mail server or network is needed.   Usage:  php tests/run.php
 */
$root = dirname(__DIR__);   // the cron/scripts folder
$S = __DIR__;
$T = sys_get_temp_dir().'/ill_crontest_'.getmypid();
mkdir($T); mkdir("$T/curl");
foreach (glob("$root/*.php") as $f) copy($f, "$T/".basename($f));
// test settings (replace any real cron_local.php): log to temp, offline holidays, fixed organization values
file_put_contents("$T/cron_local.php", "<?php\n"
    . "define('ILL_LOG_FILE', " . var_export("$T/cron.log", true) . ");\n"
    . "define('ILL_HOLIDAY_AUTO_FETCH', false);\n"   // keep the suite offline/deterministic
    . "define('ILL_FROM_NAME', 'Town Library ILL');\n"
    . "define('ILL_FROM_EMAIL', 'donotreply@library.org');\n"
    . "define('ILL_ENVELOPE_FROM', 'bounces@library.org');\n"
    . "define('ILL_ALERT_PREFIX', 'ILL ALERT');\n"
    . "define('ILL_NOC_EMAIL', 'noc@library.org');\n"
    . "define('ILL_STATS_TO', 'stats@library.org,ill@library.org');\n"
    . "define('ILL_BASE_URL', 'https://ill.library.org');\n"
    . "define('ILL_ILLIAD_SHIP_TEXT', 'Library Delivery');\n");
// One file holds both logins: the ILL database and the WordPress database (Closure Dates, Email test
// mode). The shim's mysqli_connect() always "succeeds" and mysqli_query() is routed by SQL pattern.
$ILL_DB = "<?php\n\$dbhost='h';\$dbuser='u';\$dbpass='p';\$dbname='d';\$illSTAT='STATS';\$illLIB='LIB';\$illILLiadMapping='MAP';\n";
$WP_DB  = "\$wpDbHost='h';\$wpDbUser='u';\$wpDbPass='p';\$wpDbName='wp';\$wpTablePrefix='wp_';\n";
file_put_contents("$T/ill_db.inc", $ILL_DB . $WP_DB);
copy("$S/curl", "$T/curl/curl"); chmod("$T/curl/curl", 0755);
copy("$S/capture_mail.sh", "$T/capture_mail.sh"); chmod("$T/capture_mail.sh", 0755);   // (exec bit is set on the temp copy)
copy("$S/test_closures.php", "$T/test_closures.php");
copy("$S/test_nager_filter.php", "$T/test_nager_filter.php");
copy("$S/test_business_days.php", "$T/test_business_days.php");

$fail = 0;
function t($n, $ok, $extra = '') { global $fail; if (!$ok) $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok ? '' : "  $extra") . "\n"; }

function run(string $script, array $routes = [], array $curl = [], array $args = []): array {
    global $T, $S;
    foreach (glob("$T/curl/*.json") as $j) unlink($j);
    foreach ($curl as $id => $json) file_put_contents("$T/curl/$id.json", json_encode($json));
    file_put_contents("$T/scenario.json", json_encode($routes));
    foreach (['sql.log', 'mail.log', 'cron.log'] as $f) @unlink("$T/$f");
    $cmd = 'cd ' . escapeshellarg($T) . ' && env ' . (getenv('SHIM_FAIL_DB') !== false ? 'SHIM_FAIL_DB=' . escapeshellarg(getenv('SHIM_FAIL_DB')) . ' ' : '') . 'SHIM_SCENARIO=' . escapeshellarg("$T/scenario.json") . ' SHIM_LOG=' . escapeshellarg("$T/sql.log")
         . ' MAIL_LOG=' . escapeshellarg("$T/mail.log") . ' FAKE_CURL_DIR=' . escapeshellarg("$T/curl") . ' PATH=' . escapeshellarg("$T/curl:" . getenv('PATH'))
         . ' php -d auto_prepend_file=' . escapeshellarg("$S/shim.php") . ' -d sendmail_path=' . escapeshellarg("$T/capture_mail.sh")
         . ' -d display_errors=1 -d error_reporting=E_ALL ' . escapeshellarg("$T/$script") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $code);
    $sql = []; if (is_file("$T/sql.log")) foreach (file("$T/sql.log") as $l) { $j = json_decode($l, true); if ($j) $sql[] = $j['sql']; }
    $raw   = is_file("$T/mail.log") ? array_values(array_filter(array_map('trim', explode('=====END-OF-MAIL=====', file_get_contents("$T/mail.log"))))) : [];
    $GLOBALS['ALL_RAW_MAIL'] = array_merge($GLOBALS['ALL_RAW_MAIL'] ?? [], $raw);
    $mails = array_map('decode_mail', $raw);
    return ['out' => implode("\n", $out), 'code' => $code, 'sql' => $sql, 'mails' => $mails, 'log' => is_file("$T/cron.log") ? file_get_contents("$T/cron.log") : ''];
}
// Bodies go out base64-encoded (ill_mail_encode); decode them so the checks can read the text.
function decode_mail(string $m): string {
    $parts = preg_split('/\r?\n\r?\n/', $m, 2);
    if (count($parts) === 2 && preg_match('/^Content-Transfer-Encoding:\s*base64/mi', $parts[0])) { return $parts[0] . "\n\n" . base64_decode($parts[1]); }
    return $m;
}
function has(array $list, string $needle): bool { foreach ($list as $x) if (stripos($x, $needle) !== false) return true; return false; }
function last_json(array $r) { $lines = explode("\n", trim($r['out'])); return json_decode((string) end($lines), true); }
function count_has(array $list, string $needle): int { $n = 0; foreach ($list as $x) if (stripos($x, $needle) !== false) $n++; return $n; }
function clean($r) { return !preg_match('/(Warning|Notice|Fatal error|Parse error|Deprecated|Undefined)/', $r['out']); }
function req(array $o = []): array { return $o + ['Timestamp'=>date('Y-m-d H:i:s', strtotime('-12 days')),'Destination'=>'LIBA','illNUB'=>'X-1','Title'=>'Test Title','Author'=>'An Author','Itype'=>'book','pubdate'=>'2020','reqisbn'=>'123','reqissn'=>'','Call Number'=>'PS3 .G7','Available'=>'Available','article'=>'','Requester lib'=>'Test Library','saddress'=>'1 Main St','caddress'=>'Albany NY 12110','needbydate'=>'','reqnote'=>'','Requester person'=>'Pat Patron','requesterEMAIL'=>'patron@test.org','requesterPhone'=>'555-1212','index'=>1]; }

echo "=== 3day_reminder.php ===\n";
$r = run('3day_reminder.php', [
  ['match'=>"emailsent`='0' AND `fill`='3'", 'rows'=>[ req(['illNUB'=>'A-1']), req(['illNUB'=>'B-1','Timestamp'=>date('Y-m-d H:i:s')]), req(['illNUB'=>'C-1','Destination'=>'']) ]],
  ['match'=>"SELECT `ill_email`, `Name` FROM `LIB` WHERE `loc` = 'LIBA'", 'rows'=>[['ill_email'=>'a@lib.org;b@lib.org','Name'=>'Library A']]],
]);
t('runs clean (no PHP warnings)', clean($r) && $r['code']===0, $r['out']);
t('reminder emailed to BOTH lender addresses (comma list)', has($r['mails'], 'To: a@lib.org,b@lib.org'));
t('lender email has response links (a=1 / a=0)', has($r['mails'], "https://ill.library.org/respond?num=A-1&amp;a=1") && has($r['mails'], "respond?num=A-1&amp;a=0"));
t('emails use the shared layout (header, footer, site link)', has($r['mails'], 'Sent by Town Library ILL') && has($r['mails'], 'ill.library.org</a>') && has($r['mails'], '<h1 '));
t('requester copy names the lending library, not its code', has($r['mails'], 'Library A'));
t('requester copy sent', has($r['mails'], 'To: patron@test.org'));
t('From is Town Library ILL <donotreply@library.org>', has($r['mails'], 'From: Town Library ILL <donotreply@library.org>'));
t('marks A-1 as reminded', has($r['sql'], "SET `emailsent`='2', `responderNOTE`='REMINDER MSG Sent' WHERE `illNUB`='A-1'"));
t('does NOT touch request created today (B-1)', !has($r['sql'], "illNUB`='B-1'") && !has($r['mails'], 'B-1'));
t('missing destination alerts NOC (noc@library.org)', has($r['mails'], 'To: noc@library.org') && has($r['mails'], 'ILL ALERT: Missing Destination for ILL# C-1'));
t('missing destination marked failed, not retried', has($r['sql'], "REMINDER FAILED"));
t('no SEAL wording or addresses left in any email', !has($r['mails'], 'SEAL') && !has($r['mails'], 'senylrc.org>') && !has($r['mails'], 'seal.senylrc'));
t('log written with count', str_contains($r['log'], 'Found 3 open requests'));

echo "=== expire.php ===\n";
$r = run('expire.php', [
  ['match'=>"SET\\s+`emailsent` = '1'", 'affected'=>0],
  ['match'=>"`Fill`\\s*=\\s*'3'|`fill`\\s*=\\s*'3'", 'rows'=>[ req(['illNUB'=>'E-1','Timestamp'=>date('Y-m-d H:i:s', strtotime('-25 days'))]), req(['illNUB'=>'E-2','Timestamp'=>date('Y-m-d H:i:s')]) ]],
  ['match'=>"SELECT\\s+`Name`", 'rows'=>[['Name'=>'Lender A Library']]],
  ['match'=>"SELECT\\s+`ill_email`", 'rows'=>[['ill_email'=>'a@lib.org;b@lib.org']]],
]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
t('expires E-1 (Fill=4, emailsent=3)', has($r['sql'], "SET `Fill` = '4', `emailsent` = '3', `responderNOTE` = 'EXPIRE MSG Sent' WHERE `illNUB` = 'E-1'"));
t('leaves E-2 (created today) alone', !has($r['sql'], "illNUB` = 'E-2'"));
t('requester told it expired', has($r['mails'], 'To: patron@test.org') && has($r['mails'], 'Your request has expired'));
t('lender told (both addresses)', has($r['mails'], 'To: a@lib.org,b@lib.org'));
t('links back to the site (not SEAL)', has($r['mails'], 'https://ill.library.org/') && !has($r['mails'], 'seal'));
t('no ILL-office copy by default (ILL_OFFICE_EMAIL empty)', count($r['mails'])===2, count($r['mails']).' mails');
t('selects both never-reminded (0) and reminded (2) requests', has($r['sql'], "IN ('0','2')"));

echo "=== expire.php (rota: advance instead of expiring) ===\n";
$rotaRow = [
  'illNUB'=>'R-1','status'=>'active','hop'=>1,
  'hop_started'=>date('Y-m-d H:i:s', strtotime('-12 days')),
  'queue'=>json_encode([['loc'=>'LIBB','avail'=>'Available','call'=>'PS3 .G7','alias'=>'Main Branch']]),
  'history'=>json_encode([['loc'=>'LIBA','name'=>'Lender A Library','sent'=>date('Y-m-d H:i:s', strtotime('-12 days')),'outcome'=>null,'outcome_at'=>null,'note'=>'']]),
];
$r = run('expire.php', [
  ['match'=>"SET\\s+`emailsent` = '1'", 'affected'=>0],
  ['match'=>"`Fill`\\s*=\\s*'3'|`fill`\\s*=\\s*'3'", 'rows'=>[ req(['illNUB'=>'R-1','Destination'=>'LIBA']) ]],
  ['match'=>"FROM `ILL-ROTA`", 'rows'=>[ $rotaRow ]],
  ['match'=>"SELECT `loc`,`Name`,`ill_email`,`system`,`Illiad` FROM `LIB`", 'rows'=>[['loc'=>'LIBB','Name'=>'Lender B Library','ill_email'=>'b@lib.org','system'=>'RC','Illiad'=>'0']]],
  ['match'=>"SELECT\\s+`Name`", 'rows'=>[['Name'=>'Lender A Library']]],
]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
t('does not mark it expired (no Fill=4)', !has($r['sql'], "`Fill` = '4'"));
t('moves the STATS row to LIBB and reopens it (Fill back to 3)', has($r['sql'], "`Destination`='LIBB'") && has($r['sql'], "`Fill`='3'"));
t('rota row advances to hop 2 with an empty queue', has($r['sql'], '`hop`=2') && has($r['sql'], "`queue`='[]'"));
t('new lender (LIBB) is emailed', has($r['mails'], 'To: b@lib.org') && has($r['mails'], 'auto-routed'));
t('borrower is told it moved to the new lender', has($r['mails'], 'To: patron@test.org') && has($r['mails'], 'Lender B Library'));

echo "=== expire.php (rota: queue exhausted, falls through to expire) ===\n";
$rotaRowEmpty = [
  'illNUB'=>'R-2','status'=>'active','hop'=>2,
  'hop_started'=>date('Y-m-d H:i:s', strtotime('-12 days')),
  'queue'=>'[]',
  'history'=>json_encode([
    ['loc'=>'LIBA','name'=>'Lender A Library','sent'=>date('Y-m-d H:i:s', strtotime('-20 days')),'outcome'=>'declined','outcome_at'=>date('Y-m-d H:i:s', strtotime('-12 days')),'note'=>'In Use'],
    ['loc'=>'LIBB','name'=>'Lender B Library','sent'=>date('Y-m-d H:i:s', strtotime('-12 days')),'outcome'=>null,'outcome_at'=>null,'note'=>''],
  ]),
];
$r = run('expire.php', [
  ['match'=>"SET\\s+`emailsent` = '1'", 'affected'=>0],
  ['match'=>"`Fill`\\s*=\\s*'3'|`fill`\\s*=\\s*'3'", 'rows'=>[ req(['illNUB'=>'R-2','Destination'=>'LIBB']) ]],
  ['match'=>"FROM `ILL-ROTA`", 'rows'=>[ $rotaRowEmpty ]],
  ['match'=>"SELECT\\s+`Name`", 'rows'=>[['Name'=>'Lender B Library']]],
  ['match'=>"SELECT\\s+`ill_email`", 'rows'=>[['ill_email'=>'b@lib.org']]],
]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
t('rota row marked exhausted', has($r['sql'], "`status`='exhausted'"));
t('still expires normally once the fallback list is used up (Fill=4)', has($r['sql'], "SET `Fill` = '4', `emailsent` = '3', `responderNOTE` = 'EXPIRE MSG Sent' WHERE `illNUB` = 'R-2'"));
t('borrower still gets the normal expire email', has($r['mails'], 'To: patron@test.org') && has($r['mails'], 'Your request has expired'));

echo "=== check_suspend.php ===\n";
$r = run('check_suspend.php', [
  // Fixed, unambiguous past/future dates rather than "yesterday" / "+30 days" — a relative date
  // computed here and then compared against the script's own date() call a moment later is exactly
  // the kind of thing a day boundary (or, in a sandbox with an accelerated clock, more than one) can
  // land on either side of. 2000 will never stop being in the past; 2099 will not be "soon" for a
  // very long time, so this row's "don't touch a future suspension" intent stays valid regardless.
  ['match'=>"`participant` = 1 AND `suspend` = 1", 'rows'=>[
     ['loc'=>'ENDED','ill_email'=>'x@x.org','SuspendDateEnd'=>'2000-01-01','SuspendCheckDate'=>null],
     ['loc'=>'FUTURE','ill_email'=>'x@x.org','SuspendDateEnd'=>'2099-01-01','SuspendCheckDate'=>null],
     ['loc'=>'OPEN','ill_email'=>'x@x.org','SuspendDateEnd'=>null,'SuspendCheckDate'=>null] ]],
]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
// Read back the "today" the script itself used (from the OPEN row, which it always touches) instead
// of comparing against a value this test computes separately — two independent date() calls in two
// PHP processes could otherwise land on opposite sides of a midnight rollover and fail spuriously.
preg_match("/SET `SuspendCheckDate` = '(\d{4}-\d{2}-\d{2})' WHERE `loc` = 'OPEN'/", implode("\n", $r['sql']), $m);
$scriptToday = $m[1] ?? null;
t('script recorded a check date', $scriptToday !== null, implode("\n", $r['sql']));
t("ends the suspension whose end date passed", $scriptToday && has($r['sql'], "SET `suspend` = '0', `SuspendCheckDate` = '$scriptToday' WHERE `loc` = 'ENDED'"));
t("leaves SuspendDateEnd alone (a required DATE on some installs' tables: NULL would be rejected)", !has($r['sql'], "`SuspendDateEnd` = NULL"));
t('keeps a future-dated suspension', !has($r['sql'], "`suspend` = '0'") || count_has($r['sql'], "`suspend` = '0'")===1);
t('keeps a suspension with NO end date (old script would have ended it)', $scriptToday && has($r['sql'], "SET `SuspendCheckDate` = '$scriptToday' WHERE `loc` = 'OPEN'") && !has($r['sql'], "`suspend` = '0', `SuspendDateEnd` = NULL, `SuspendCheckDate` = '$scriptToday' WHERE `loc` = 'OPEN'"));

echo "=== due_date.php ===\n";
// The mock row's DueDate has to be set before the script runs, so it still needs its own
// date('m/d/Y', strtotime('+5 days')) — but only this one input value is computed independently;
// everything the test asserts afterwards is read back from what the script itself logged, so a
// midnight rollover between "build the mock row" and "the script runs" can't cause a mismatch.
$five = date('m/d/Y', strtotime('+5 days'));
$r = run('due_date.php', [
  ['match'=>"`DueDate` IN", 'rows'=>[ req(['illNUB'=>'D-1','DueDate'=>$five]) ]],
]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
preg_match('/Checking for due dates on (\d{2}\/\d{2}\/\d{4}) \/ (\d{4}-\d{2}-\d{2})/', $r['log'], $m);
[$scriptFive, $scriptFiveIso] = [$m[1] ?? null, $m[2] ?? null];
t('script logged the two due-date formats it is checking for', $scriptFive !== null, $r['log']);
t('queries items due in 5 days in BOTH stored formats (m/d/Y and Y-m-d)', $scriptFive && has($r['sql'], "`DueDate` IN ('$scriptFive', '$scriptFiveIso')"));
t('requester IS emailed (the old script mailed an undefined variable)', has($r['mails'], 'To: patron@test.org') && has($r['mails'], "ILL# D-1"));
t('subject/from/branding: system name + shared layout', has($r['mails'], 'Approaching Due Date — ILL#') && has($r['mails'], 'Sent by Town Library ILL') && has($r['mails'], 'An item you borrowed is due soon'));

echo "=== stats_weekly.php ===\n";
$r = run('stats_weekly.php', [
  ['match'=>"FROM `STATS`.*`Fill`=1", 'rows'=>[['cnt'=>6]]],
  ['match'=>"FROM `STATS`.*`Fill`=4", 'rows'=>[['cnt'=>2]]],
  ['match'=>"FROM `STATS`", 'rows'=>[['cnt'=>10]]],
  ['match'=>"FROM `LIB`", 'rows'=>[['cnt'=>5]]],
]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
t('sent to both recipients', has($r['mails'], 'To: stats@library.org,ill@library.org'));
t('fill rate and totals computed', has($r['mails'], '(60.0%)') && has($r['mails'], 'Total requests</th>') && has($r['mails'], '>10</td>') && has($r['mails'], '(20.0%)'));
t('subject says Stats', has($r['mails'], 'Stats — '));

echo "=== check_illiad.php ===\n";
$dest = ['match'=>"SELECT `APIkey`", 'rows'=>[['APIkey'=>'KEY','IlliadURL'=>'https://illiad.example.org/api/Transaction','Name'=>'Lender A','ill_email'=>'a@lib.org;b@lib.org']]];
$row = fn($id,$n,$st) => req(['IlliadTransID'=>$id,'index'=>$n,'illNUB'=>"I-$n",'Fill'=>'3','IlliadStatus'=>$st]);
$r = run('check_illiad.php', [
  ['match'=>"IlliadStatus` LIKE '%Awaiting%'", 'rows'=>[ $row('T1',1,'Awaiting Lending Request Processing'), $row('T2',2,'Awaiting Lending Request Processing'), $row('T3',3,'Awaiting Lending Request Processing') ]],
  $dest,
], [
  'T1'=>['TransactionNumber'=>'11','TransactionStatus'=>'Item Shipped','DueDate'=>'2026-11-30T00:00:00'],
  'T2'=>['TransactionNumber'=>'12','TransactionStatus'=>'Cancelled by Lending Library','ReasonForCancellation'=>'Lost'],
  'T3'=>['TransactionNumber'=>'13','TransactionStatus'=>'Awaiting Lending Request Processing'],
]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
t('shipped -> Fill=1 with ILLiad due date', has($r['sql'], "`DueDate` = '2026-11-30'") && has($r['sql'], "`Fill` = '1'"));
t('requester told it will be filled, with due date', has($r['mails'], 'ILL Request Filled ILL# I-1') && has($r['mails'], 'November 30, 2026') && has($r['mails'], 'Library Delivery'));
t('cancelled "Lost" -> reasonNotFilled 21, Fill=0', has($r['sql'], "`reasonNotFilled` = '21'") && has($r['sql'], "`Fill` = '0'"));
t('not-filled email points back to the site', has($r['mails'], 'ILL Request Not Filled ILL# I-2') && has($r['mails'], 'href="https://ill.library.org/"'));
t('still-pending request gets no email', !has($r['mails'], 'I-3'));
t('includes the "Searching" status the old script missed', has($r['sql'], "%Searching%"));

echo "=== illiad_return.php ===\n";
$r = run('illiad_return.php', [
  ['match'=>"IlliadStatus` LIKE '%Awaiting%'", 'rows'=>[ req(['IlliadTransID'=>'R1','index'=>5,'illNUB'=>'R-5','IlliadStatus'=>'Item Shipped']), req(['IlliadTransID'=>'R2','index'=>6,'illNUB'=>'R-6','IlliadStatus'=>'Item Shipped']) ]],
  $dest,
], [ 'R1'=>['TransactionStatus'=>'Request Finished'], 'R2'=>['TransactionStatus'=>'Item Shipped'] ]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
t('finished request marked returned + checked in', has($r['sql'], "`checkinAccount` = 'ILLiad'") && has($r['sql'], "`returnAccount` = 'ILLiad'") && has($r['sql'], "WHERE `index` = 5"));
t('still-shipped request untouched', !has($r['sql'], "WHERE `index` = 6"));

echo "=== check_illiad_duedate.php ===\n";
$r = run('check_illiad_duedate.php', [
  ['match'=>"`IlliadStatus` LIKE '%Shipped%'\\s+AND `IlliadTransID`", 'rows'=>[
      req(['IlliadTransID'=>'S1','index'=>7,'illNUB'=>'S-7','DueDate'=>'2026-10-30','IlliadStatus'=>'Item Shipped']),
      req(['IlliadTransID'=>'S2','index'=>8,'illNUB'=>'S-8','DueDate'=>'2026-10-30','IlliadStatus'=>'Item Shipped']),
      req(['IlliadTransID'=>'S3','index'=>9,'illNUB'=>'S-9','DueDate'=>'10/30/2026','IlliadStatus'=>'Item Shipped']) ]],
  ['match'=>"SELECT\\s+`index`\\s+FROM `STATS`\\s+WHERE `IlliadStatus` LIKE '%Shipped%'\\s+AND `TimeStamp`", 'rows'=>[['index'=>99]]],
  $dest,
], [ 'S1'=>['TransactionStatus'=>'Item Shipped','DueDate'=>'2026-11-15T00:00:00'], 'S2'=>['TransactionStatus'=>'Item Shipped','DueDate'=>'2026-10-30T00:00:00'], 'S3'=>['TransactionStatus'=>'Item Shipped','DueDate'=>'2026-10-30T00:00:00'] ]);
t('runs clean', clean($r) && $r['code']===0, $r['out']);
t('changed due date saved', has($r['sql'], "SET `DueDate`='2026-11-15'") && has($r['sql'], "WHERE `index`=7"));
t('borrower told about the new due date', has($r['mails'], 'Request S-7 has a new due date'));
t('unchanged due date left alone (no update, no email)', !has($r['sql'], "WHERE `index`=8") && !has($r['mails'], 'S-8'));
t('legacy m/d/Y stored date equal to ILLiad date -> no update, no spurious email', !has($r['sql'], "WHERE `index`=9") && !has($r['mails'], 'S-9'));
t('old shipped requests auto-closed after 7 months (setting)', has($r['sql'], "INTERVAL 7 MONTH") && has($r['sql'], "'Assumed Complete after 7 months'") && has($r['sql'], "WHERE `index` = 99"));


echo "=== Closure Dates (ILL -> Org Rules, read from wp_options) ===\n";
$closureOption = <<<TXT
2026-12-24|Winter Break start
2026-12-31|Winter Break end
not-a-date|Bad row, should be skipped
# a comment line, should be skipped

2027-01-19|MLK Day (observed)
TXT;
$r = run('test_closures.php', [
  ['match' => "option_name`\\s*=\\s*'seal_rules_closures'", 'rows' => [['option_value' => $closureOption]]],
]);
$j = last_json($r);
t('runs clean', clean($r) && $r['code'] === 0, $r['out']);
t('valid dates read from the WordPress option', ($j['closures'] ?? null) === ['2026-12-24', '2026-12-31', '2027-01-19'], $r['out']);
t('malformed date and comment/blank lines skipped, not crashed on', count($j['closures'] ?? []) === 3);
t('queried the WordPress options table by name, not by guessing an ID', has($r['sql'], "SELECT `option_value` FROM `wp_options` WHERE `option_name` = 'seal_rules_closures'"));
t('feeds into ill_get_holidays() (with Winter Break always added; online fetch is off in this test config)', is_array($j['holidays'] ?? null) && !array_diff(['2026-12-24', '2026-12-31', '2027-01-19'], $j['holidays']) && in_array(date('Y') . '-12-28', $j['holidays'], true));

file_put_contents("$T/ill_db.inc", $ILL_DB);   // no WordPress login at all
$r = run('test_closures.php');
$j = last_json($r);
t('no WordPress login in ill_db.inc: no fatal, empty closures list (US holidays still apply)', clean($r) && $r['code'] === 0 && ($j['closures'] ?? null) === [], $r['out']);
t('...and the log says what is missing', str_contains($r['log'], 'no WordPress database login'));

file_put_contents("$T/wp_db.inc", "<?php\n" . $WP_DB);   // older layout: separate wp_db.inc still works
$r = run('test_closures.php', [ ['match' => "option_name`\\s*=\\s*'seal_rules_closures'", 'rows' => [['option_value' => "2026-12-28|x"]]] ]);
$j = last_json($r);
t('older separate wp_db.inc is still read if ill_db.inc has no WordPress login', ($j['closures'] ?? null) === ['2026-12-28'], $r['out']);
unlink("$T/wp_db.inc");
file_put_contents("$T/ill_db.inc", $ILL_DB . $WP_DB);

echo "=== expire.php with the Rota table not created yet (PHP 8.1+ mysqli) ===\n";
$r = run('expire.php', [
  ['match'=>"FROM `ILL-ROTA`", 'error'=>"Table 'ill_test.ILL-ROTA' doesn't exist"],
  ['match'=>"SET\\s+`emailsent` = '1'", 'affected'=>0],
  ['match'=>"`Fill`\\s*=\\s*'3'|`fill`\\s*=\\s*'3'", 'rows'=>[ req(['illNUB'=>'N-1','Timestamp'=>date('Y-m-d H:i:s', strtotime('-25 days'))]) ]],
  ['match'=>"SELECT\\s+`Name`", 'rows'=>[['Name'=>'Lender A Library']]],
  ['match'=>"SELECT\\s+`ill_email`", 'rows'=>[['ill_email'=>'a@lib.org']]],
]);
t('runs clean when the Rota table does not exist yet', clean($r) && $r['code']===0, $r['out']);
t('still expires the request normally', has($r['sql'], "WHERE `illNUB` = 'N-1'") && has($r['mails'], 'Your request has expired'));

echo "=== WordPress database unreachable (strict mysqli, PHP 8.1+) ===\n";
putenv('SHIM_FAIL_DB=wp');
$r = run('3day_reminder.php', [
  ['match'=>"emailsent`='0' AND `fill`='3'", 'rows'=>[ req(['illNUB'=>'W-1']) ]],
  ['match'=>"SELECT `ill_email`, `Name` FROM `LIB` WHERE `loc` = 'LIBA'", 'rows'=>[['ill_email'=>'a@lib.org','Name'=>'Library A']]],
]);
putenv('SHIM_FAIL_DB');
t('reminder job still runs when wp_db.inc cannot connect', clean($r) && $r['code']===0 && has($r['mails'], 'To: a@lib.org'), $r['out']);
t('the failure is logged, not fatal', str_contains($r['log'], 'could not read Closure Dates'));

echo "=== Email test mode (follows WordPress ILL -> Contacts & Email) ===\n";
$remRoutes = [
  ['match'=>"emailsent`='0' AND `fill`='3'", 'rows'=>[ req(['illNUB'=>'T-1']) ]],
  ['match'=>"SELECT `ill_email`, `Name` FROM `LIB` WHERE `loc` = 'LIBA'", 'rows'=>[['ill_email'=>'a@lib.org;b@lib.org','Name'=>'Library A']]],
];
$r = run('3day_reminder.php', array_merge([['match'=>"option_name` = 'seal_email_mode'", 'rows'=>[['option_value'=>'off']]]], $remRoutes));
t('off: reminder job runs but sends nothing', clean($r) && $r['code']===0 && count($r['mails'])===0, $r['out']);
t('off: still marks the request as reminded (only email is suppressed)', has($r['sql'], "WHERE `illNUB`='T-1'"));
t('off: every held email is recorded in the cron log', str_contains($r['log'], 'EMAIL TEST MODE (off): not sent to a@lib.org,b@lib.org') && str_contains($r['log'], 'not sent to patron@test.org'));

$r = run('3day_reminder.php', array_merge([
  ['match'=>"option_name` = 'seal_email_mode'", 'rows'=>[['option_value'=>'redirect']]],
  ['match'=>"option_name` = 'seal_email_test_to'", 'rows'=>[['option_value'=>'tester@library.org']]],
], $remRoutes));
t('redirect: every email goes only to the test address', count($r['mails'])===2 && count(array_filter($r['mails'], fn($m) => preg_match('/^To: tester@library\.org\r?$/m', $m) && !preg_match('/^(To|Cc|Bcc): .*(lib\.org|test\.org)/mi', $m)))===2, implode("\n----\n", $r['mails']));
$r = run('3day_reminder.php', array_merge([
  ['match'=>"option_name` = 'seal_email_mode'", 'rows'=>[['option_value'=>'redirect']]],
  ['match'=>"option_name` = 'seal_email_test_to'", 'rows'=>[['option_value'=>'tester@library.org, second@library.org']]],
], $remRoutes));
t('redirect: several test addresses (comma list) all receive it, none mangled', count($r['mails'])===2 && has($r['mails'], 'To: tester@library.org,second@library.org') && !has($r['mails'], 'library.orgsecond'));
t('envelope sender is ILL_ENVELOPE_FROM (not the From address)', has($r['mails'], 'X-Sendmail-Args: -t -i -f bounces@library.org') || has($r['mails'], '-f bounces@library.org'));
t('visible From is still Town Library ILL <donotreply@library.org>', has($r['mails'], 'From: Town Library ILL <donotreply@library.org>'));
t('redirect: subject marked [TEST], original recipients listed in the message', has($r['mails'], '[TEST]') && has($r['mails'], 'would have gone to: a@lib.org,b@lib.org') && has($r['mails'], 'would have gone to: patron@test.org'));

$r = run('3day_reminder.php', array_merge([['match'=>"option_name` = 'seal_email_mode'", 'rows'=>[['option_value'=>'redirect']]]], $remRoutes));
t('redirect with no test address: sends nothing rather than to real people', count($r['mails'])===0 && str_contains($r['log'], 'no valid test address'));

$r = run('3day_reminder.php', $remRoutes);
t('setting never saved = send normally', has($r['mails'], 'To: a@lib.org,b@lib.org'));

echo "=== Email footer (System Short Name + Org Full Name from ILL -> Branding) ===\n";
$r = run('3day_reminder.php', array_merge([
  ['match'=>"option_name` = 'seal_system_name'",   'rows'=>[['option_value'=>'Town ILL']]],
  ['match'=>"option_name` = 'seal_org_full_name'", 'rows'=>[['option_value'=>'Town Library Council']]],
  ['match'=>"option_name` = 'seal_org_name'",      'rows'=>[['option_value'=>'TLC']]],
], $remRoutes));
t('footer: Sent by Town ILL, a service hosted by Town Library Council', has($r['mails'], 'Sent by Town ILL, a service hosted by Town Library Council &middot;'));
$r = run('3day_reminder.php', array_merge([
  ['match'=>"option_name` = 'seal_system_name'", 'rows'=>[['option_value'=>'Town ILL']]],
  ['match'=>"option_name` = 'seal_org_name'",    'rows'=>[['option_value'=>'TLC']]],
], $remRoutes));
t('footer: no Org Full Name -> Org Short Name', has($r['mails'], 'Sent by Town ILL, a service hosted by TLC &middot;'));
$r = run('3day_reminder.php', $remRoutes);
t('footer: no org names at all -> "Sent by Town Library ILL" with no "hosted by"', has($r['mails'], 'Sent by Town Library ILL &middot;') && !has($r['mails'], 'hosted by'));

echo "=== Business days (weekends, holidays, New Year's, daylight saving, leap day) ===\n";
$r = run('test_business_days.php');
$j = last_json($r);
$c = $j['cases'] ?? [];
t('runs clean', $r['code'] === 0 && is_array($j), $r['out']);
t('Saturday and Sunday are not business days, Monday is', ($j['weekend'] ?? null) === [false, false, true]);
t('Mon + 3 = Thu', ($c['2026-10-05+3'] ?? '') === '2026-10-08');
t('Thu + 3 skips the weekend and Columbus Day = next Wed', ($c['2026-10-08+3'] ?? '') === '2026-10-14');
t('Fri + 3 = next Wed', ($c['2026-10-02+3'] ?? '') === '2026-10-07');
t('request made Saturday or Sunday counts from Monday', ($c['2026-10-03+3'] ?? '') === '2026-10-07' && ($c['2026-10-04+3'] ?? '') === '2026-10-07');
t('Thanksgiving skipped (Day after Thanksgiving counts unless listed in Closure Dates)', ($c['2026-11-25+3'] ?? '') === '2026-12-01');
t('New Year: Dec 22 + 5 skips Winter Break and the weekend = Jan 7', ($c['2026-12-22+5'] ?? '') === '2027-01-07', json_encode($c));
t('New Year: Dec 31 + 3 = Jan 6', ($c['2026-12-31+3'] ?? '') === '2027-01-06');
t('New Year: Dec 23 + 3 = Jan 6', ($c['2026-12-23+3'] ?? '') === '2027-01-06');
t('New Year the next year too: Dec 22 2027 + 5 = Jan 6 2028', ($c['2027-12-22+5'] ?? '') === '2028-01-06');
t('a late-evening timestamp on Dec 31 still counts from Dec 31', ($c['2026-12-31 23:59:59+3'] ?? '') === '2027-01-06');
t('daylight saving ends (Nov 1): Fri Oct 30 + 3 = Wed Nov 4', ($c['2026-10-30+3'] ?? '') === '2026-11-04');
t('daylight saving starts (Mar 14): Fri Mar 12 + 3 = Wed Mar 17', ($c['2027-03-12+3'] ?? '') === '2027-03-17');
t('leap day counts: Fri Feb 25 2028 + 3 = Wed Mar 1', ($c['2028-02-25+3'] ?? '') === '2028-03-01');
t('holiday years: December loads next year too', ($j['years'][0] ?? null) === [2025, 2026, 2027]);
t('holiday years: early January still loads last year (for December requests)', ($j['years'][1] ?? null) === [2026, 2027]);
t('holiday years: November = last + this year', ($j['years'][2] ?? null) === [2025, 2026]);
t('Winter Break 2026 = Dec 24 2026 through Jan 1 2027 (9 days)', count($j['winter'] ?? []) === 9 && ($j['winter'][0] ?? '') === '2026-12-24' && end($j['winter']) === '2027-01-01');
t('Winter Break is in the calendar even with the online holiday fetch turned off', in_array(($j['thisyear'] ?? 0) . '-12-24', $j['calendar'] ?? [], true) && in_array(($j['thisyear'] ?? 0) . '-12-31', $j['calendar'] ?? [], true));

echo "=== Federal holiday filter (Nager.Date response) ===\n";
$r = run('test_nager_filter.php');
$j = last_json($r);
t('keeps nationwide and New York holidays only, one entry per date', $j === ['2026-01-01', '2026-10-12'], $r['out']);
t('drops observances, other states\' holidays, school-only days and malformed dates', is_array($j) && !array_intersect($j, ['2026-02-12', '2026-04-03', '2026-05-08', 'garbage']));

echo "=== safety ===\n";
$web = shell_exec('cd '.escapeshellarg($T).' && REQUEST_METHOD=GET php-cgi -v 2>&1 | head -1');
$r = shell_exec('cd '.escapeshellarg($T).' && php -r \'$_SERVER["argv"]=[]; ob_start(); include "cron_config.php"; echo "cli-ok";\' 2>&1');
t('config loads in CLI', str_contains((string)$r, 'cli-ok'));

echo "=== generic names (same scripts for every organization) ===\n";
$named = [];
foreach (glob("$root/*.php") as $f) {
    if (in_array(basename($f), ['cron_config.php', 'cron_local.php'], true)) continue;   // the per-organization settings files
    if (preg_match('/cdlc|senylrc|duenorth/i', basename($f) . "\n" . file_get_contents($f))) $named[] = basename($f);
}
t('no organization name in the script names or code (only cron_config.php / cron_local.php hold org settings)', !$named, implode(', ', $named));
$gone = array_filter(['cdlc_cron_config.php', 'cdlc_expire.php', 'cdlc_db.inc'], fn($n) => is_file("$root/$n"));
t('old cdlc_* file names are gone', !$gone, implode(', ', $gone));

// Mail servers break lines over 998 characters wherever they fall (it turned "</a>" into "< /a>" in reminders).
$raw_all = $GLOBALS['ALL_RAW_MAIL'] ?? [];
$long = array_filter($raw_all, fn($m) => max(array_map('strlen', preg_split('/\r?\n/', $m))) > 998);
t('every email sent has lines under 998 characters (' . count($raw_all) . ' emails)', $raw_all && !$long, count($long) . ' too long');
t('every email sent is base64 with MIME headers', $raw_all && !array_filter($raw_all, fn($m) => !preg_match('/^Content-Transfer-Encoding: base64/mi', $m) || !preg_match('/^MIME-Version: 1\.0/mi', $m) || preg_match_all('/^Content-Transfer-Encoding:/mi', $m) !== 1));
t('decoded reminder buttons close their links properly', has(array_map('decode_mail', $raw_all), 'No, we cannot fill it</a>') && !has(array_map('decode_mail', $raw_all), '< /a>'));

echo "\nFAILURES: $fail\n";
exec('rm -rf '.escapeshellarg($T));
