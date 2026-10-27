<?php

require __DIR__ . '/cron_config.php';
/**
 * ILL / 3-Day Reminder Script (Business Day Logic)
 * ------------------------------------------------------------
 *  - Sends reminder emails if 3 business days (excl. weekends & holidays)
 *    have passed since an ILL request was created.
 *  - Uses live Nager.Date API + Winter Break (Dec 24 – Jan 1).
 */

set_time_limit(1800);
date_default_timezone_set('America/New_York');

$logfile = ILL_LOG_FILE;
error_log(date('c') . " - ===== Starting 3day_reminder.php =====\n", 3, $logfile);

// Holidays come from ILL -> Org Rules -> Closure Dates + auto-fetched federal holidays, via ill_get_holidays() (see cron_config.php).

// ----------------------------------------------------
//  Database connection
// ----------------------------------------------------
require __DIR__ . '/ill_db.inc';
$db = ill_db_connect($dbhost, $dbuser, $dbpass, $dbname);
if (!$db) {
    error_log(date('c') . " - DB connection failed: " . mysqli_connect_error() . "\n", 3, $logfile);
    die("DB connection failed\n");
}
$db->set_charset("utf8mb4");

// ----------------------------------------------------
//  Helper functions for business days
// ----------------------------------------------------
// isBusinessDay() / addBusinessDays() live in cron_config.php (one shared copy).


// ----------------------------------------------------
//  Load holiday list
// ----------------------------------------------------
$holidays = ill_get_holidays();

// ----------------------------------------------------
//  Query open requests that need reminders
// ----------------------------------------------------
$sqlselect = "SELECT * FROM `$illSTAT` WHERE `emailsent`='0' AND `fill`='3'";
$retval = mysqli_query($db, $sqlselect);
if (!$retval) {
    error_log(date('c') . " - SQL select failed: " . mysqli_error($db) . "\n", 3, $logfile);
    die("Query failed\n");
}

$count = mysqli_num_rows($retval);
echo "Found $count open requests\n";
error_log(date('c') . " - Found $count open requests\n", 3, $logfile);

// ----------------------------------------------------
//  Process each request
// ----------------------------------------------------
while ($row = mysqli_fetch_assoc($retval)) {
    $timestamp   = $row["Timestamp"];
    $destination = trim($row["Destination"]);
    $illnum      = trim($row["illNUB"]);
    $title       = $row["Title"];
    $author      = $row["Author"];
    $itype       = $row["Itype"];
    $pubdate     = $row["pubdate"];
    $isbn        = $row["reqisbn"];
    $issn        = $row["reqissn"];
    $itemcall    = $row["Call Number"];
    $itemavail   = $row["Available"];
    $article     = $row["article"];
    $inst        = $row["Requester lib"];
    $address     = $row["saddress"];
    $caddress    = $row["caddress"];
    $needbydate  = $row["needbydate"];
    $reqnote     = $row["reqnote"];
    $fname       = $row["Requester person"];
    $email       = $row["requesterEMAIL"];
// Normalize requester email list (supports comma or semicolon separated)
$reqEmails = preg_split('/[;,]+/', (string)$email);
$reqEmails = array_filter(array_map('trim', $reqEmails), fn($v) => $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL));
$email_to_requester = implode(',', array_values(array_unique($reqEmails)));


    $wphone      = $row["requesterPhone"];

    $reqdate = substr($timestamp, 0, 10);
    $today   = date("Y-m-d");
    $dueDate = addBusinessDays($reqdate, 3, $holidays, false); // Start counting next business day

    $logprefix = "ILL#$illnum ($destination)";
    error_log(date('c') . " - Checking $logprefix | req=$reqdate due=$dueDate (3 business days)\n", 3, $logfile);

    if ($today <= $dueDate) {
        continue; // Not yet due
    }

    // ------------------------------------------------
    // If Destination is missing, alert NOC and skip
    // ------------------------------------------------
    if ($destination === '' || $destination === null) {
        $noc_subject = ILL_ALERT_PREFIX . ": Missing Destination for ILL# $illnum";
        $noc_message = ill_email_alert('3-day reminder: request has no lending library', [
            'ILL #' => $illnum, 'Requesting library' => $inst, 'Title' => $title, 'Requester email' => $email,
        ], 'This request cannot be sent to a lender because its Destination field is empty.');

        $noc_headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
        $noc_headers .= "MIME-Version: 1.0\r\n";
        $noc_headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        ill_mail(ILL_NOC_EMAIL, $noc_subject, $noc_message, $noc_headers, "-f " . ILL_FROM_EMAIL);

        error_log(
            date('c') . " - $logprefix | Destination is EMPTY — sent NOC alert instead of reminder\n",
            3,
            $logfile
        );

        // Mark as handled so we don't keep retrying
        mysqli_query(
            $db,
            "UPDATE `$illSTAT`
             SET `emailsent`='2',
                 `responderNOTE`='REMINDER FAILED — NO DESTINATION. NOC ALERTED.'
             WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "'"
        );

        continue; // Next row
    }

    // ------------------------------------------------
    // Get destination ILL email
    // ------------------------------------------------
    $destemail = '';
    $destname  = '';
    $resDest = mysqli_query($db, "SELECT `ill_email`, `Name` FROM `$illLIB` WHERE `loc` = '" . mysqli_real_escape_string($db, $destination) . "' LIMIT 1");

    if (!$resDest) {
        // SQL error – alert NOC and skip
        $sqlErr = mysqli_error($db);
        $noc_subject = ILL_ALERT_PREFIX . ": SQL error looking up Destination $destination (ILL# $illnum)";
        $noc_message = ill_email_alert('3-day reminder not sent: database error', [
            'ILL #' => $illnum, 'Lending library code' => $destination, 'Requesting library' => $inst, 'Title' => $title,
            'Requester email' => $email, 'MySQL error' => $sqlErr,
        ]);

        $noc_headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
        $noc_headers .= "MIME-Version: 1.0\r\n";
        $noc_headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        ill_mail(ILL_NOC_EMAIL, $noc_subject, $noc_message, $noc_headers, "-f " . ILL_FROM_EMAIL);

        error_log(
            date('c') . " - $logprefix | SQL error on dest lookup: $sqlErr\n",
            3,
            $logfile
        );

        mysqli_query(
            $db,
            "UPDATE `$illSTAT`
             SET `emailsent`='2',
                 `responderNOTE`='REMINDER FAILED — DEST LOOKUP SQL ERROR. NOC ALERTED.'
             WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "'"
        );

        continue;
    }

    if (mysqli_num_rows($resDest) > 0) {
        $rowDest   = mysqli_fetch_assoc($resDest);
        $destemail = trim($rowDest['ill_email'] ?? '');
        $destname  = trim($rowDest['Name'] ?? '');
    }

    // Normalize + split multiple addresses on ';'
    $destemailarray = array_filter(
        array_map('trim', explode(';', $destemail)),
        fn($v) => $v !== ''
    );

    if (empty($destemailarray)) {
        // No usable email – alert NOC and skip
        $noc_subject = ILL_ALERT_PREFIX . ": No ILL email found for Destination $destination (ILL# $illnum)";
        $noc_message = ill_email_alert('3-day reminder not sent: lending library has no ILL email', [
            'ILL #' => $illnum, 'Lending library code' => $destination, 'Requesting library' => $inst, 'Title' => $title, 'Requester email' => $email,
        ], 'Add an ILL email address to this library\'s record in the library directory.');

        $noc_headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
        $noc_headers .= "MIME-Version: 1.0\r\n";
        $noc_headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        ill_mail(ILL_NOC_EMAIL, $noc_subject, $noc_message, $noc_headers, "-f " . ILL_FROM_EMAIL);

        error_log(
            date('c') . " - $logprefix | No dest email for $destination — NOC alerted\n",
            3,
            $logfile
        );

        mysqli_query(
            $db,
            "UPDATE `$illSTAT`
             SET `emailsent`='2',
                 `responderNOTE`='REMINDER FAILED — NO DEST EMAIL. NOC ALERTED.'
             WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "'"
        );

        continue;
    }

    $email_to = implode(',', $destemailarray);

    // ------------------------------------------------
    // Compose emails
    // ------------------------------------------------
    $subject = ill_subject('cron_reminder', 'REMINDER: ILL Request from {library} — ILL# {ill}', ['ill' => $illnum, 'library' => $inst]);
    $headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    $respond = rtrim(ILL_BASE_URL, '/') . '/respond?num=' . rawurlencode($illnum);
    $tv = ['ill' => $illnum, 'title' => $title, 'library' => $inst];
    $messagedest = ill_text('cron_reminder', 'heading', 'Reminder: a request is waiting for your answer', $tv)
        . ill_text('cron_reminder', 'message', '**{library}** asked your library to lend this item a few business days ago and has not had an answer yet. Please answer with one of the buttons below.', $tv)
        . ill_email_buttons([[$respond . '&a=1', 'Yes, we will fill it', '#0a6b35'], [$respond . '&a=0', 'No, we cannot fill it', '#b32d2e']])
        . ill_email_subheading('Requested item')
        . ill_email_details([
            'ILL #' => $illnum, 'Title' => $title, 'Author' => $author, 'Type' => $itype, 'Publication date' => $pubdate,
            'Call number' => $itemcall, 'ISBN' => $isbn, 'ISSN' => $issn, 'Article' => ill_email_article((string) $article),
            'Needed by' => ill_email_date((string) $needbydate), 'Note' => $reqnote,
        ])
        . ill_email_subheading('Requested by')
        . ill_email_details([
            'Name' => $fname, 'Library' => $inst, 'Email*' => ill_email_mailto((string) $email), 'Phone' => $wphone,
            'Ship to' => implode("\n", array_filter(array_map('trim', [(string) $address, (string) $caddress]), 'strlen')),
        ])
        . ill_text('cron_reminder', 'closing', '', $tv);

    $messagereq = ill_text('cron_reminder_borrower', 'heading', 'Still waiting for an answer', $tv)
        . ill_text('cron_reminder_borrower', 'message', 'The lending library has not answered your request yet, so it has been sent a reminder. You don\'t need to do anything.', $tv)
        . ill_email_details([
            'ILL #' => $illnum, 'Title' => $title, 'Lending library' => $destname !== '' ? $destname : $destination,
            'Lender contact*' => ill_email_mailto($email_to),
        ])
        . ill_email_button(rtrim(ILL_BASE_URL, '/') . '/requesthistory', 'View your borrowing requests')
        . ill_text('cron_reminder_borrower', 'closing', '', $tv);

    // ------------------------------------------------
    // Send emails
    // ------------------------------------------------
    $destSent = ill_mail($email_to, $subject, $messagedest, $headers, "-f " . ILL_FROM_EMAIL);
    $reqSent  = ill_mail($email_to_requester,    $subject, $messagereq, $headers, "-f " . ILL_FROM_EMAIL);

    if ($destSent || $reqSent) {
        $sqlupdate = "UPDATE `$illSTAT`
                      SET `emailsent`='2', `responderNOTE`='REMINDER MSG Sent'
                      WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "'";
        if (mysqli_query($db, $sqlupdate)) {
            error_log(date('c') . " - $logprefix | Reminder sent successfully\n", 3, $logfile);
        } else {
            $err = mysqli_error($db);
            error_log(date('c') . " - $logprefix | Reminder sent but DB update failed: $err\n", 3, $logfile);
        }
    } else {
        error_log(date('c') . " - $logprefix | Reminder email failed (to: $email_to, $email)\n", 3, $logfile);
    }
}

mysqli_close($db);
error_log(date('c') . " - ===== Completed 3day_reminder.php =====\n", 3, $logfile);
echo "3-day reminder processing complete.\n";
?>
