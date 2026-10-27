<?php

require __DIR__ . '/cron_config.php';
/**
 * ILL / Due Date Reminder
 * ------------------------------------------------------------
 * Purpose:
 *   Sends an email reminder 5 days before an ILL item’s due date.
 *   (Works for items received but not yet returned/checked in.)
 *
 * Location: /var/www/<code>_script/due_date.php
 * Logs:     /var/log/ill_cron.log
 */

set_time_limit(900);
$logfile = ILL_LOG_FILE;
error_log(date('c') . " - ===== Starting due_date.php =====\n", 3, $logfile);

// ----------------------------------------------------
//  Connect to database
// ----------------------------------------------------
require __DIR__ . '/ill_db.inc';
$db = ill_db_connect($dbhost, $dbuser, $dbpass, $dbname);
if (!$db) {
    error_log(date('c') . " - DB connection failed: " . mysqli_connect_error() . "\n", 3, $logfile);
    die("DB connection failed\n");
}
$db->set_charset("utf8mb4");

// ----------------------------------------------------
//  Prepare date range (5 days from today)
// ----------------------------------------------------
$today = date("Y-m-d");
$fivedays = date('m/d/Y', strtotime('+5 days'));      // legacy stored format (MM/DD/YYYY)
$fivedaysIso = date('Y-m-d', strtotime('+5 days'));   // format saved by the WordPress plugin and by ILLiad
error_log(date('c') . " - Checking for due dates on $fivedays / $fivedaysIso\n", 3, $logfile);

// ----------------------------------------------------
//  Query items due in 5 days
// ----------------------------------------------------
$sqlselect = "
    SELECT *
    FROM `$illSTAT`
    WHERE `DueDate` IN ('$fivedays', '$fivedaysIso')
      AND `receiveAccount` IS NOT NULL
      AND `returnAccount` IS NULL
      AND `checkinAccount` IS NULL
    ORDER BY `index` ASC
";
$retval = mysqli_query($db, $sqlselect);
if (!$retval) {
    error_log(date('c') . " - SQL query failed: " . mysqli_error($db) . "\n", 3, $logfile);
    die("Query failed\n");
}

$count = mysqli_num_rows($retval);
echo "Found $count items with due date $fivedays\n";
error_log(date('c') . " - Found $count items with due date $fivedays\n", 3, $logfile);

// ----------------------------------------------------
//  Process and send reminders
// ----------------------------------------------------
while ($row = mysqli_fetch_assoc($retval)) {
    $illnum     = $row["illNUB"];
    $title      = $row["Title"];
    $author     = $row["Author"];
    $itype      = $row["Itype"];
    $pubdate    = $row["pubdate"];
    $isbn       = $row["reqisbn"];
    $issn       = $row["reqissn"];
    $article    = $row["article"];
    $duedate    = $row["DueDate"];
    $email      = trim($row["requesterEMAIL"]);

    // --- clean missing values ---
    if (empty($title))   $title = '(no title)';
    if (empty($author))  $author = '(unknown author)';
    if (empty($itype))   $itype = '';
    if (empty($pubdate)) $pubdate = '';
    if (empty($isbn))    $isbn = '';
    if (empty($issn))    $issn = '';
    if (empty($article)) $article = '';

    // --- Compose message ---
    $subject = ill_subject('cron_due_date', '{system} Approaching Due Date — ILL# {ill}', ['ill' => $illnum, 'title' => $title ?? '']);
    $headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    $tv = ['ill' => $illnum, 'title' => $title];
    $message = ill_text('cron_due_date', 'heading', 'An item you borrowed is due soon', $tv)
        . ill_text('cron_due_date', 'message', 'This interlibrary loan item is due back in five days. Please return it on time, or ask the lending library for a renewal.', $tv)
        . ill_email_details([
            'ILL #' => $illnum, 'Title' => $title, 'Author' => $author, 'Due date' => ill_email_date((string) $duedate),
            'Type' => $itype, 'Publication date' => $pubdate, 'ISBN' => $isbn, 'ISSN' => $issn, 'Article' => ill_email_article((string) $article),
        ])
        . ill_email_button(rtrim(ILL_BASE_URL, '/') . '/requesthistory', 'Renew or view your borrowing requests')
        . ill_text('cron_due_date', 'closing', '', $tv);

    // --- Send email ---
    $sent = ill_mail($email, $subject, $message, $headers, "-f " . ILL_FROM_EMAIL);
    if ($sent) {
        error_log(date('c') . " - ILL#$illnum | Due date reminder sent to $email ($duedate)\n", 3, $logfile);
    } else {
        error_log(date('c') . " - ILL#$illnum | FAILED to send due date reminder to $email\n", 3, $logfile);
    }
}

mysqli_close($db);
error_log(date('c') . " - ===== Completed due_date.php =====\n", 3, $logfile);
echo "Due date reminder processing complete.\n";
?>
