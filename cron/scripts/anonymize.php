<?php

require __DIR__ . '/cron_config.php';
/**
 * ILL / Data Anonymizer + Maintenance
 * ------------------------------------------------------------
 * Purpose (ported from SEAL-3 seal_anonymizer.php):
 *   1) Auto-expire requests still pending after ILL_ANON_EXPIRE_MONTHS (default 18).
 *   2) Clear the request note (reqNOTE) of expired requests after ILL_ANON_NOTE_DAYS (default 30).
 *   3) Anonymize the item and note details of every request older than ILL_ANON_MONTHS (default 60 = 5 years).
 *      The request row itself stays (ILL number, libraries, dates, outcome), so statistics still count it.
 *   Each limit is set under ILL System → Cron Jobs → Data retention in WordPress (read every run; falls back to the
 *   ILL_ANON_* values in cron_config.php when WordPress can't be read); 0 turns that step off.
 *
 * Differences from the SEAL script: auto-expired requests also get Fill = 4, which is what expire.php sets
 * and what the WordPress pages show as "Expired"; only columns that exist in the table are blanked, so one
 * missing column can't stop the whole step; failure alerts go to ILL_NOC_EMAIL in the usual alert layout.
 *
 * Usage:    php anonymize.php            (make the changes)
 *           php anonymize.php --dry-run  (only count what would change; nothing is written)
 * Location: /var/www/<code>_script/anonymize.php
 * Logs:     /var/log/ill_cron.log
 */

set_time_limit(900);
$logfile = ILL_LOG_FILE;
$dryRun  = in_array('--dry-run', $argv ?? [], true);

// Periods: ILL System → Cron Jobs → Data retention in WordPress; if that can't be read, cron_config.php's values.
$period = static function (string $option, string $const, int $default): int {
    $v = function_exists('ill_wp_option') ? ill_wp_option($option) : null;
    if (is_string($v) && trim($v) !== '' && ctype_digit(trim($v))) {
        return (int) $v;
    }
    return defined($const) ? (int) constant($const) : $default;
};
$expireMonths = $period('seal_anon_expire_months', 'ILL_ANON_EXPIRE_MONTHS', 18);
$noteDays     = $period('seal_anon_note_days', 'ILL_ANON_NOTE_DAYS', 30);
$anonMonths   = $period('seal_anon_months', 'ILL_ANON_MONTHS', 60);

$log = static function (string $msg) use ($logfile): void {
    echo $msg . "\n";
    error_log(date('c') . " - anonymize.php | $msg\n", 3, $logfile);
};

error_log(date('c') . " - ===== Starting anonymize.php" . ($dryRun ? ' (dry run)' : '') . " =====\n", 3, $logfile);

// ----------------------------------------------------
//  Connect to database
// ----------------------------------------------------
require __DIR__ . '/ill_db.inc';
$db = ill_db_connect($dbhost, $dbuser, $dbpass, $dbname);
if (!$db) {
    error_log(date('c') . " - DB connection failed in anonymize.php: " . mysqli_connect_error() . "\n", 3, $logfile);
    die("DB connection failed\n");
}
$db->set_charset("utf8mb4");

/** Emails the NOC that one step failed. */
$alert = static function (string $step, string $err): void {
    $headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    ill_mail(
        ILL_NOC_EMAIL,
        ill_branding()['system'] . " Anonymizer Failure — $step",
        ill_email_alert("Anonymizer step failed: $step", ['When' => date('Y-m-d H:i:s'), 'Error' => $err], 'Nothing was changed by this step. It will be tried again on the next run.'),
        $headers,
        "-f " . ILL_FROM_EMAIL
    );
};

/** Runs a COUNT(*) query; -1 on error. */
$count = static function (string $sql) use ($db): int {
    $res = mysqli_query($db, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ? (int) $row['total'] : -1;
};

/** Runs one step's UPDATE (unless dry run), logging the result and alerting on failure. */
$run = static function (string $step, int $found, string $sql) use ($db, $dryRun, $log, $alert): void {
    if ($found < 0) {
        $err = mysqli_error($db);
        $log("$step: count failed: $err");
        $alert($step, $err);
        return;
    }
    if ($dryRun || $found === 0) {
        $log("$step: " . ($dryRun ? 'dry run, nothing changed' : 'nothing to do'));
        return;
    }
    if (mysqli_query($db, $sql)) {
        $log("$step: done, " . mysqli_affected_rows($db) . " records updated");
    } else {
        $err = mysqli_error($db);
        $log("$step: FAILED: $err");
        $alert($step, $err);
    }
};

// "Expired": set by expire.php (Fill 4, emailsent 3), by step 1 below, or the older SEAL marker in responderNOTE.
$isExpired = "(s.`Fill` = '4' OR s.`emailsent` = '3' OR LOWER(COALESCE(s.`responderNOTE`, '')) LIKE '%expire%')";

// ======================================================================
// STEP 1: Auto-expire requests still pending after $expireMonths months
// Pending: Fill empty or 3 (no answer yet) and not already expired.
// ======================================================================
if ($expireMonths > 0) {
    $cut   = date('Y-m-d', strtotime("-$expireMonths months"));
    $where = "s.`Timestamp` < '$cut' AND (s.`Fill` IS NULL OR s.`Fill` = '3') AND NOT $isExpired";
    $found = $count("SELECT COUNT(*) AS total FROM `$illSTAT` s WHERE $where");
    $log("Step 1: $found pending requests older than $cut ($expireMonths months) to auto-expire");
    $run('Auto-expire', $found, "
        UPDATE `$illSTAT` s
        SET s.`Fill` = '4',
            s.`emailsent` = '3',
            s.`responderNOTE` = CONCAT(
                LEFT(COALESCE(s.`responderNOTE`, ''), 220),
                CASE WHEN COALESCE(s.`responderNOTE`, '') = '' THEN '' ELSE ' | ' END,
                'Expired automatically ($expireMonths months)'
            ),
            s.`fillNofillDate` = COALESCE(s.`fillNofillDate`, NOW())
        WHERE $where
    ");
} else {
    $log('Step 1: auto-expire is off (ILL_ANON_EXPIRE_MONTHS = 0)');
}

// ======================================================================
// STEP 2: Clear the request note of expired requests after $noteDays days
// Age: when it was expired (fillNofillDate), else when it was requested.
// ======================================================================
if ($noteDays > 0) {
    $cut   = date('Y-m-d', strtotime("-$noteDays days"));
    $where = "$isExpired AND COALESCE(DATE(s.`fillNofillDate`), DATE(s.`Timestamp`)) < '$cut' AND COALESCE(s.`reqNOTE`, '') <> ''";
    $found = $count("SELECT COUNT(*) AS total FROM `$illSTAT` s WHERE $where");
    $log("Step 2: $found expired requests older than $cut ($noteDays days) with a request note to clear");
    $run('Clear expired request notes', $found, "UPDATE `$illSTAT` s SET s.`reqNOTE` = '' WHERE $where");
} else {
    $log('Step 2: clearing expired request notes is off (ILL_ANON_NOTE_DAYS = 0)');
}

// ======================================================================
// STEP 3: Anonymize item and note details older than $anonMonths months
// ======================================================================
if ($anonMonths > 0) {
    $fields = ['Title', 'Author', 'pubdate', 'reqisbn', 'reqissn', 'itype', 'Call Number', 'article', 'needbydate',
               'patronnote', 'DueDate', 'returnNote', 'renewNote', 'renewNoteLender', 'responderNOTE', 'reqNOTE'];
    $have = [];
    if ($cols = mysqli_query($db, "SHOW COLUMNS FROM `$illSTAT`")) {
        while ($c = mysqli_fetch_assoc($cols)) {
            $have[strtolower($c['Field'])] = $c['Field'];
        }
    }
    $present = array_values(array_filter(array_map(static fn($f) => $have[strtolower($f)] ?? null, $fields)));
    $missing = array_diff(array_map('strtolower', $fields), array_keys($have));
    if ($missing) {
        $log('Step 3: columns not in the table, skipped: ' . implode(', ', $missing));
    }

    $cut = date('Y-m-d', strtotime("-$anonMonths months"));
    if (!$present) {
        $err = $have ? 'none of the columns to anonymize exist' : 'could not read the table columns: ' . mysqli_error($db);
        $log("Step 3: $err");
        $alert('Anonymize', $err);
    } else {
        // Only rows that still hold something, so the count means "still to do" rather than "every old row".
        $notBlank = implode(' OR ', array_map(static fn($f) => "COALESCE(`$f`, '') <> ''", $present));
        $where    = "`Timestamp` < '$cut' AND ($notBlank)";
        $found    = $count("SELECT COUNT(*) AS total FROM `$illSTAT` WHERE $where");
        $log("Step 3: $found requests older than $cut ($anonMonths months) to anonymize");
        $set = implode(', ', array_map(static fn($f) => "`$f` = ''", $present));
        $run('Anonymize', $found, "UPDATE `$illSTAT` SET $set WHERE $where");
    }
} else {
    $log('Step 3: anonymizing is off (ILL_ANON_MONTHS = 0)');
}

mysqli_close($db);
error_log(date('c') . " - ===== Completed anonymize.php =====\n", 3, $logfile);
