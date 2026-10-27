<?php

require __DIR__ . '/cron_config.php';
/**
 * ILL / Weekly Stats Email
 * ------------------------------------------------------------
 * Purpose:
 *   Email ILL activity stats for the last 7 days to staff.
 *
 * Location: /var/www/<code>_script/stats_weekly.php
 * Logs:     /var/log/ill_cron.log
 */

set_time_limit(300);
$logfile = ILL_LOG_FILE;
error_log(date('c') . " - ===== Starting stats_weekly.php =====\n", 3, $logfile);

// ----------------------------------------------------
//  DB Connect
// ----------------------------------------------------
require __DIR__ . '/ill_db.inc';
$db = ill_db_connect($dbhost, $dbuser, $dbpass, $dbname);
if (!$db) {
    $err = mysqli_connect_error();
    error_log(date('c') . " - DB connection failed: $err\n", 3, $logfile);
    die("DB connection failed\n");
}
$db->set_charset("utf8mb4");

// ----------------------------------------------------
//  Date Window
// ----------------------------------------------------
$startdate = date('Y-m-d', strtotime('-7 days'));
$curdate   = date('Y-m-d');
// To use “from start of service”, uncomment next line:
// $startdate = '2016-07-01';

$startTs = $startdate . ' 00:00:00';
$endTs   = $curdate   . ' 23:59:59';

error_log(date('c') . " - Stats window: $startTs → $endTs\n", 3, $logfile);

// ----------------------------------------------------
//  Helper for COUNT(*) queries
// ----------------------------------------------------
function count_query(mysqli $db, string $sql): int
{
    $res = mysqli_query($db, $sql);
    if (!$res) {
        return -1;
    }
    $row = mysqli_fetch_assoc($res);
    return (int)($row['cnt'] ?? 0);
}

// ----------------------------------------------------
//  Counts (use COUNT(*) for performance)
// ----------------------------------------------------
$total_req = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illSTAT` WHERE `Timestamp` BETWEEN '$startTs' AND '$endTs'");
$filled    = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illSTAT` WHERE `Timestamp` BETWEEN '$startTs' AND '$endTs' AND `Fill`=1");
$notfill   = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illSTAT` WHERE `Timestamp` BETWEEN '$startTs' AND '$endTs' AND `Fill`=0");
$noanswer  = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illSTAT` WHERE `Timestamp` BETWEEN '$startTs' AND '$endTs' AND `Fill`=3");
$expired   = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illSTAT` WHERE `Timestamp` BETWEEN '$startTs' AND '$endTs' AND `Fill`=4");
$canceled  = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illSTAT` WHERE `Timestamp` BETWEEN '$startTs' AND '$endTs' AND `Fill`=6");

$libs_active        = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illLIB` WHERE `participant`=1 AND `suspend`=0");
$libs_suspended     = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illLIB` WHERE `participant`=1 AND `suspend`=1");
$libs_nonparticipant = count_query($db, "SELECT COUNT(*) AS cnt FROM `$illLIB` WHERE `participant`=0 AND `suspend`=0");

// sanity log on any count error
$counts = [
  'total_req' => $total_req,'filled' => $filled,'notfill' => $notfill,'noanswer' => $noanswer,
  'expired' => $expired,'canceled' => $canceled,'libs_active' => $libs_active,
  'libs_suspended' => $libs_suspended,'libs_nonparticipant' => $libs_nonparticipant
];
foreach ($counts as $k => $v) {
    if ($v < 0) {
        error_log(date('c') . " - Count error for $k (query failed)\n", 3, $logfile);
    }
}

// ----------------------------------------------------
//  Derived metrics
// ----------------------------------------------------
$fill_rate   = ($total_req > 0) ? sprintf('%.1f%%', ($filled / $total_req) * 100) : '0.0%';
$expire_rate = ($total_req > 0) ? sprintf('%.1f%%', ($expired / $total_req) * 100) : '0.0%';

// ----------------------------------------------------
//  Build Email
// ----------------------------------------------------
$subject = ill_subject('cron_weekly_stats', '{system} Stats — {start} to {end}', ['start' => $startdate, 'end' => $curdate]);
$headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

$tv = ['start' => ill_email_date($startdate), 'end' => ill_email_date($curdate)];
$messagedest = ill_text('cron_weekly_stats', 'heading', 'Weekly statistics', $tv)
    . ill_text('cron_weekly_stats', 'message', 'Requests placed from **{start}** to **{end}**.', $tv)
    . ill_email_subheading('Requests')
    . ill_email_details([
        'Total requests' => (string) $total_req, 'Filled' => "$filled ($fill_rate)", 'Not filled' => (string) $notfill,
        'Expired' => "$expired ($expire_rate)", 'Not answered yet' => (string) $noanswer, 'Cancelled' => (string) $canceled,
    ])
    . ill_email_subheading('Libraries')
    . ill_email_details([
        'Active' => (string) $libs_active, 'Active but suspended' => (string) $libs_suspended, 'Not participating' => (string) $libs_nonparticipant,
    ])
    . ill_email_button(rtrim(ILL_BASE_URL, '/') . '/stats', 'Open the statistics page')
    . ill_text('cron_weekly_stats', 'closing', '', $tv);

// ----------------------------------------------------
//  Send Email
// ----------------------------------------------------
$email_to = ILL_STATS_TO;
$sent = ill_mail($email_to, $subject, $messagedest, $headers, "-f " . ILL_FROM_EMAIL);

if ($sent) {
    error_log(date('c') . " - Weekly stats email sent to $email_to\n", 3, $logfile);
} else {
    error_log(date('c') . " - FAILED to send weekly stats email to $email_to\n", 3, $logfile);
    // Optional: alert NOC on failure
    $noc_headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
    $noc_headers .= "MIME-Version: 1.0\r\n";
    $noc_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    @ill_mail(ILL_NOC_EMAIL, ill_branding()['system'] . " Weekly Stats: Send Failure", "Failed to send stats to $email_to for $startdate to $curdate.", $noc_headers, "-f " . ILL_FROM_EMAIL);
}

mysqli_close($db);
error_log(date('c') . " - ===== Completed stats_weekly.php =====\n", 3, $logfile);
echo "Weekly stats completed.\n";
