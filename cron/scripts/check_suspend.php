<?php

require __DIR__ . '/cron_config.php';
/**
 * ILL / Suspension Auto-Release Script
 * ------------------------------------------------------------
 * Purpose:
 *   Automatically re-enables libraries whose suspension end date
 *   has passed, and logs daily check activity.
 *
 * Location: /var/www/<code>_script/check_suspend.php
 * Logs:     /var/log/ill_cron.log
 */

set_time_limit(600);
$logfile = ILL_LOG_FILE;
error_log(date('c') . " - ===== Starting check_suspend.php =====\n", 3, $logfile);

// ----------------------------------------------------
//  Connect to database
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
//  Query suspended participants
// ----------------------------------------------------
$today = date("Y-m-d");
$sqlselect = "
    SELECT `loc`, `ill_email`, `SuspendDateEnd`, `SuspendCheckDate`
    FROM `$illLIB`
    WHERE `participant` = 1 AND `suspend` = 1
";
$result = mysqli_query($db, $sqlselect);
if (!$result) {
    $err = mysqli_error($db);
    error_log(date('c') . " - Query failed: $err\n", 3, $logfile);
    die("Query failed\n");
}

$count = mysqli_num_rows($result);
echo "Checking $count suspended libraries...\n";
error_log(date('c') . " - Checking $count suspended libraries\n", 3, $logfile);

// ----------------------------------------------------
//  Process each suspended library
// ----------------------------------------------------
while ($row = mysqli_fetch_assoc($result)) {
    $loc             = $row["loc"];
    $illemail        = $row["ill_email"];
    $suspendEndDate  = $row["SuspendDateEnd"];
    $suspendCheck    = $row["SuspendCheckDate"];

    $logprefix = "LOC $loc";

    // --- Expired suspension ---
    if (!empty($suspendEndDate) && $today > $suspendEndDate) {
        $updatesql = "
            UPDATE `$illLIB`
            SET `suspend` = '0',
                `SuspendCheckDate` = '$today'
            WHERE `loc` = '" . mysqli_real_escape_string($db, $loc) . "'
        ";
        if (mysqli_query($db, $updatesql)) {
            echo "Ended suspension for $loc\n";
            error_log(date('c') . " - $logprefix | Suspension ended\n", 3, $logfile);
        } else {
            $err = mysqli_error($db);
            error_log(date('c') . " - $logprefix | Update failed: $err\n", 3, $logfile);
            // Optional email alert on error
            $headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $subject  = ill_branding()['system'] . " Suspension Update Failed — $loc";
            $message  = ill_email_alert('Ending a library suspension failed', ['Library code' => $loc, 'Suspended until' => (string) $suspendEndDate, 'MySQL error' => $err],
                'The library is still marked suspended. Clear it under Manage All Libraries.');
            ill_mail(ILL_NOC_EMAIL, $subject, $message, $headers, "-f " . ILL_FROM_EMAIL);
        }
    }
    // --- Still suspended ---
    else {
        $updatesql = "
            UPDATE `$illLIB`
            SET `SuspendCheckDate` = '$today'
            WHERE `loc` = '" . mysqli_real_escape_string($db, $loc) . "'
        ";
        if (mysqli_query($db, $updatesql)) {
            echo "Checked (still suspended): $loc\n";
            error_log(date('c') . " - $logprefix | Still suspended, check date updated\n", 3, $logfile);
        } else {
            $err = mysqli_error($db);
            error_log(date('c') . " - $logprefix | Failed to update check date: $err\n", 3, $logfile);
        }
    }
}

mysqli_close($db);
error_log(date('c') . " - ===== Completed check_suspend.php =====\n", 3, $logfile);
echo "Suspension check completed.\n";
?>
