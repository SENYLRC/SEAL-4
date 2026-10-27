<?php

require __DIR__ . '/cron_config.php';
/**
 * ILL / 5-Day Expiration Script (Business Day Logic)
 * ------------------------------------------------------------
 * Purpose:
 *   Automatically expire ILL requests that remain unfilled
 *   after 5 business days (excluding weekends & holidays).
 *
 * Location: /var/www/<code>_script/expire.php
 * Logs:     /var/log/ill_cron.log
 */

set_time_limit(1800);
date_default_timezone_set('America/New_York');

$logfile = ILL_LOG_FILE;
error_log(date('c') . " - ===== Starting expire.php =====\n", 3, $logfile);

// Holidays come from ILL -> Org Rules -> Closure Dates + auto-fetched federal holidays, via ill_get_holidays() (see cron_config.php).

// ----------------------------------------------------
//  Helper: get library name by LOC code
// ----------------------------------------------------
function libsbycode(mysqli $db, string $illLIB, string $loccode): string
{
    $loccode = trim($loccode);
    if ($loccode === '') {
        return '';
    }

    $sql = "
        SELECT `Name`
        FROM `{$illLIB}`
        WHERE `loc` = '" . mysqli_real_escape_string($db, $loccode) . "'
        LIMIT 1
    ";

    $result = mysqli_query($db, $sql);
    if (!$result) {
        return '';
    }

    $row = mysqli_fetch_assoc($result);
    return isset($row['Name']) ? trim($row['Name']) : '';
}



// ----------------------------------------------------
//  Business-day helpers (same as 3-day reminder)
// ----------------------------------------------------
// isBusinessDay() / addBusinessDays() live in cron_config.php (one shared copy).


// ----------------------------------------------------
//  Rota (automatic fallback) support
//  Mirrors includes/class-seal-rota.php in the plugin — see that file's header comment for the
//  full design. The table ($illROTA) only exists once an install has turned the module on, so
//  every lookup here is guarded and a missing table is treated as "not enrolled" (unchanged
//  behavior). $illROTA is set in ill_db.inc alongside $illSTAT / $illLIB; a sensible default
//  is applied below if that variable was not added yet.
// ----------------------------------------------------
function ill_rota_get(mysqli $db, string $table, string $illnum): ?array
{
    $res = @mysqli_query($db, "SELECT * FROM `$table` WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "' LIMIT 1");
    if (!$res) {
        return null; // table does not exist (module never enabled) or query failed
    }
    $row = mysqli_fetch_assoc($res);
    if (!$row) {
        return null;
    }
    $row['queue']   = json_decode((string)($row['queue'] ?? ''), true) ?: [];
    $row['history'] = json_decode((string)($row['history'] ?? ''), true) ?: [];
    $row['hop']     = (int)($row['hop'] ?? 1);
    return $row;
}

/**
 * Try to move a rota-enrolled request to its next candidate library because the current one never
 * answered in 5 business days. Returns ['advanced'=>bool,'next'=>array|null]; when the queue is
 * empty this marks the rota row 'exhausted' and returns advanced=false so the caller falls through
 * to the normal expire code below (same STATS Fill code, same emails as an unenrolled request).
 */
function ill_rota_advance_on_timeout(mysqli $db, string $illSTAT, string $illLIB, string $illROTA, array $rota, string $illnum, string $logfile): array
{
    $now     = date('Y-m-d H:i:s');
    $history = $rota['history'];
    $last    = count($history) - 1;
    if ($last >= 0) {
        $history[$last]['outcome']    = 'expired';
        $history[$last]['outcome_at'] = $now;
        $history[$last]['note']       = 'No response within 5 business days.';
    }

    $queue = $rota['queue'];
    $next  = null;
    while ($queue) {
        $cand = array_shift($queue);
        $loc  = strtoupper(trim((string)($cand['loc'] ?? '')));
        if ($loc === '') {
            continue;
        }
        $chk = mysqli_query(
            $db,
            "SELECT `loc`,`Name`,`ill_email`,`system`,`Illiad` FROM `$illLIB`
             WHERE UPPER(TRIM(`loc`))='" . mysqli_real_escape_string($db, $loc) . "' AND `participant`=1 AND `suspend`=0 LIMIT 1"
        );
        $lib = $chk ? mysqli_fetch_assoc($chk) : null;
        if ($lib) {
            $next = $cand + [
                'name' => $lib['Name'], 'system' => $lib['system'],
                'email' => $lib['ill_email'] ?? '', 'illiad' => (string)($lib['Illiad'] ?? ''),
            ];
            break;
        }
        // no longer eligible (closed / suspended since the request was made) — try the next one
    }

    if (!$next) {
        mysqli_query(
            $db,
            "UPDATE `$illROTA` SET `status`='exhausted', `queue`='[]',
             `history`='" . mysqli_real_escape_string($db, json_encode($history)) . "', `updated`='$now'
             WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "'"
        );
        error_log(date('c') . " - ILL#$illnum | Rota exhausted at timeout — falling through to normal expire\n", 3, $logfile);
        return ['advanced' => false, 'next' => null];
    }

    $history[] = ['loc' => $next['loc'], 'name' => $next['name'], 'sent' => $now, 'outcome' => null, 'outcome_at' => null, 'note' => ''];
    mysqli_query(
        $db,
        "UPDATE `$illROTA` SET `hop`=" . ((int)$rota['hop'] + 1) . ", `hop_started`='$now',
         `queue`='" . mysqli_real_escape_string($db, json_encode(array_values($queue))) . "',
         `history`='" . mysqli_real_escape_string($db, json_encode($history)) . "', `updated`='$now'
         WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "'"
    );
    mysqli_query(
        $db,
        "UPDATE `$illSTAT` SET
            `Destination`='" . mysqli_real_escape_string($db, $next['loc']) . "',
            `DestSystem`='" . mysqli_real_escape_string($db, (string)$next['system']) . "',
            `Call Number`='" . mysqli_real_escape_string($db, (string)($next['call'] ?? '')) . "',
            `Location`='" . mysqli_real_escape_string($db, (string)($next['alias'] ?? '')) . "',
            `Available`='" . mysqli_real_escape_string($db, (string)($next['avail'] ?? '')) . "',
            `Fill`='3', `emailsent`='0', `responderNOTE`='', `DueDate`='', `ReasonNotFilled`='',
            `IlliadTransID`='', `IlliadStatus`=''
         WHERE `illNUB`='" . mysqli_real_escape_string($db, $illnum) . "'"
    );

    error_log(
        date('c') . " - ILL#$illnum | Rota advanced on timeout: now {$next['name']} ({$next['loc']}), hop " . ((int)$rota['hop'] + 1) . "\n",
        3,
        $logfile
    );
    return ['advanced' => true, 'next' => $next];
}

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

// See the "Rota (automatic fallback) support" comment above. Falls back to the plugin's default
// table name if ill_db.inc has not been updated with $illROTA yet.
$illROTA = $illROTA ?? 'ILL-ROTA';

// ----------------------------------------------------
//  Cleanup: if something got FILLED after being marked EXPIRED,
//  remove expired flags (responderNOTE) and set emailsent=1
// ----------------------------------------------------
$cleanup_sql = "
    UPDATE `$illSTAT`
    SET
        `emailsent` = '1',
        `responderNOTE` = TRIM(
            REPLACE(
              REPLACE(
                REPLACE(
                  REPLACE(
                    COALESCE(`responderNOTE`, ''),
                    'EXPIRE MSG Sent', ''
                  ),
                  'Expire Msg Sent', ''
                ),
                'expire msg sent', ''
              ),
              'EXPIRED', ''
            )
        )
    WHERE `Fill` = '1'
      AND (
            `emailsent` = '3'
            OR LOWER(COALESCE(`responderNOTE`, '')) LIKE '%expire%'
          )
";

if (mysqli_query($db, $cleanup_sql)) {
    $fixed = mysqli_affected_rows($db);
    if ($fixed > 0) {
        error_log(date('c') . " - Cleanup: cleared expired flags on $fixed filled requests (emailsent->1, responderNOTE cleaned)\n", 3, $logfile);
    }
} else {
    error_log(date('c') . " - Cleanup WARNING: failed to clear expired flags: " . mysqli_error($db) . "\n", 3, $logfile);
}



// ----------------------------------------------------
//  Load holidays
// ----------------------------------------------------
$holidays = ill_get_holidays();

// ----------------------------------------------------
//  Select requests that are still unfilled
// ----------------------------------------------------
$sqlselect = "SELECT * FROM `$illSTAT` WHERE `fill`='3' AND `emailsent` IN ('0','2')";
$res = mysqli_query($db, $sqlselect);
if (!$res) {
    error_log(date('c') . " - Query failed: " . mysqli_error($db) . "\n", 3, $logfile);
    die("Query failed\n");
}

$count = mysqli_num_rows($res);
echo "Found $count open requests\n";
error_log(date('c') . " - Found $count open requests for expiration check\n", 3, $logfile);

// ----------------------------------------------------
//  Process each record
// ----------------------------------------------------
while ($row = mysqli_fetch_assoc($res)) {
    $timestamp   = $row["Timestamp"];
    $illnum      = trim($row["illNUB"]);
    $title       = $row["Title"];
    $requester   = $row["Requester lib"];
    $email       = trim($row["requesterEMAIL"]);
// Normalize requester email list (supports comma or semicolon separated)
$reqEmails = preg_split('/[;,]+/', (string)$email);
$reqEmails = array_filter(array_map('trim', $reqEmails), fn($v) => $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL));
$email_to_requester = implode(',', array_values(array_unique($reqEmails)));
 
   $destination = trim($row["Destination"]);

    // Build a nice display for the lending library: "Name (CODE)" or just CODE if name missing
    $lenderCode = $destination; // LOC code from illSTAT
    $lenderName = libsbycode($db, $illLIB, $lenderCode);

    if ($lenderName !== '' && $lenderCode !== '') {
        $lenderDisplay = "$lenderName ";
    } else {
        $lenderDisplay = $lenderCode; // fallback
    }

    // ============================================================
    // GET LENDER RESPONDER EMAIL (from illLIB using loc code)
    // ============================================================

    // $destination or $destill should contain the LOC code.
    // If your script uses a different variable name, replace it here.
    $loccode = trim($destination ?? $destill ?? '');

    // Default to empty
    $lender_email = '';

    if ($loccode !== '') {
        // Query illLIB for the ILL email address
        $sql = "
        SELECT `ill_email`
        FROM `$illLIB`
        WHERE `loc` = '" . mysqli_real_escape_string($db, $loccode) . "'
        LIMIT 1
    ";

        $resultEmail = mysqli_query($db, $sql);

        if ($resultEmail && mysqli_num_rows($resultEmail) > 0) {
            $rowEmail = mysqli_fetch_assoc($resultEmail);

            if (!empty($rowEmail['ill_email'])) {
                // Handle multiple emails separated by semicolons
                $emails = array_map('trim', explode(';', $rowEmail['ill_email']));
                // Convert to comma-separated list for mail()
                $lender_email = implode(',', $emails);
            }
        }


    }

    // Optional debug logging
    if ($lender_email === '') {
        error_log(
            date('c') . " - WARNING: No responder email found in illLIB for LOC '$loccode' during 5-day expire.\n",
            3,
            $logfile
        );
    }


    // Rota-enrolled requests: the 5-business-day clock runs from when the CURRENT lender was sent
    // the request, not from the original submission (for the first lender these are the same
    // moment, so unenrolled requests are unaffected either way).
    $rota = ill_rota_get($db, $illROTA, $illnum);
    $rota_active = ($rota && $rota['status'] === 'active');
    $reqdate = $rota_active ? substr((string)$rota['hop_started'], 0, 10) : substr($timestamp, 0, 10);
    $today   = date("Y-m-d");

    $expireDate = addBusinessDays($reqdate, 5, $holidays, false);
    $logprefix  = "ILL#$illnum ($lenderDisplay)";
    error_log(date('c') . " - Checking $logprefix | req=$reqdate expire=$expireDate (5 business days)" . ($rota_active ? " [rota hop {$rota['hop']}]" : "") . "\n", 3, $logfile);

    if ($today <= $expireDate) {
        continue;
    } // not yet expired

    // ----------------------------------------------------
    //  Rota-enrolled: try the next library before expiring
    // ----------------------------------------------------
    if ($rota_active) {
        $result = ill_rota_advance_on_timeout($db, $illSTAT, $illLIB, $illROTA, $rota, $illnum, $logfile);
        if ($result['advanced']) {
            $next = $result['next'];
            $rheaders  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
            $rheaders .= "MIME-Version: 1.0\r\n";
            $rheaders .= "Content-Type: text/html; charset=UTF-8\r\n";

            if (!empty($next['email'])) {
                $rSubject = ill_subject('rota_new_lender', '{system} ILL Request (auto-routed) — ILL# {ill} for "{title}"', ['ill' => $illnum, 'title' => $title]);
                $respond = rtrim(ILL_BASE_URL, '/') . '/respond?num=' . rawurlencode($illnum);
                $tv = ['ill' => $illnum, 'title' => $title];
                $rMsg = ill_text('cron_rota_new_lender', 'heading', 'Interlibrary loan request (automatically routed)', $tv)
                    . ill_text('cron_rota_new_lender', 'message', 'This request was **automatically routed to your library** because the previous library on the borrower\'s list did not answer within 5 business days. Please answer with one of the buttons below.', $tv)
                    . ill_email_buttons([[$respond . '&a=1', 'Yes, we will fill it', '#0a6b35'], [$respond . '&a=0', 'No, we cannot fill it', '#b32d2e']])
                    . ill_email_details([
                        'ILL #' => $illnum, 'Title' => (string) $title, 'Call number' => (string) ($next['call'] ?? ''),
                        'Requested by' => (string) $requester, 'Requester email*' => ill_email_mailto((string) $email),
                    ])
                    . ill_text('cron_rota_new_lender', 'closing', '', $tv);
                ill_mail(str_replace(';', ',', $next['email']), $rSubject, $rMsg, $rheaders, "-f " . ILL_FROM_EMAIL);
            } else {
                error_log(date('c') . " - ILL#$illnum | Rota WARNING: no ILL email for {$next['loc']} — contact them directly.\n", 3, $logfile);
            }
            if ($next['illiad'] === '1') {
                error_log(date('c') . " - ILL#$illnum | Rota NOTE: {$next['loc']} uses ILLiad — this script does not auto-submit on a timeout hop; send it from WordPress: ILL Admin -> Resend a Request (it is listed under 'Waiting to be sent to ILLiad').\n", 3, $logfile);
            }

            $tv = ['ill' => $illnum, 'title' => $title];
            $bMsg = ill_text('cron_rota_update', 'heading', 'Your request is being tried at another library', $tv)
                . ill_text('cron_rota_update', 'message', 'The previous library did not answer within 5 business days, so your request has automatically moved to the next library on your list. You don\'t need to do anything.', $tv)
                . ill_email_details(['ILL #' => $illnum, 'Title' => (string) $title, 'No answer from' => $lenderDisplay, 'Now trying' => (string) $next['name']])
                . ill_email_button(rtrim(ILL_BASE_URL, '/') . '/requesthistory', 'View your borrowing requests')
                . ill_text('cron_rota_update', 'closing', '', $tv);
            ill_mail($email_to_requester, ill_subject('rota_update', '{system} ILL Request Update — ILL# {ill}', ['ill' => $illnum, 'title' => $title]), $bMsg, $rheaders, "-f " . ILL_FROM_EMAIL);

            error_log(date('c') . " - $logprefix | Rota advanced instead of expiring\n", 3, $logfile);
            continue;
        }
        // exhausted — fall through to the normal expire code below, same as an unenrolled request
    }

    error_log(date('c') . " - $logprefix | Expiring now after 5 business days\n", 3, $logfile);

    // ----------------------------------------------------
    //  Mark as expired
    // ----------------------------------------------------
    $sqlupdate = "
        UPDATE `$illSTAT`
        SET `Fill` = '4',
            `emailsent` = '3',
            `responderNOTE` = 'EXPIRE MSG Sent'
        WHERE `illNUB` = '" . mysqli_real_escape_string($db, $illnum) . "'
    ";

    if (mysqli_query($db, $sqlupdate)) {
        error_log(date('c') . " - $logprefix | Marked expired (Fill=4, emailsent=3)\n", 3, $logfile);
    } else {
        $err = mysqli_error($db);
        error_log(date('c') . " - $logprefix | DB update failed: $err\n", 3, $logfile);
        continue;
    }

    // ----------------------------------------------------
    //  Email notifications
    // ----------------------------------------------------
    $subject = ill_subject('cron_expired', 'ILL Request Expired — ILL# {ill}', ['ill' => $illnum, 'title' => $title]);
    $headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    $tv = ['ill' => $illnum, 'title' => $title, 'lender_library' => $lenderDisplay];
    $message = ill_text('cron_expired', 'heading', 'Your request has expired', $tv)
        . ill_text('cron_expired', 'message', 'The lending library did not answer your request within five business days, so it has expired.', $tv)
        . ill_email_details(['ILL #' => $illnum, 'Title' => (string) $title, 'Lending library' => $lenderDisplay, 'Lender contact*' => ill_email_mailto((string) $lender_email)])
        . ill_text('cron_expired', 'closing', 'You can search the catalog again and request the item from another library.', $tv)
        . ill_email_button(rtrim(ILL_BASE_URL, '/') . '/', 'Search the catalog');

    // Notify requester
    ill_mail($email_to_requester, $subject, $message, $headers, "-f " . ILL_FROM_EMAIL);

    // ----------------------------------------------------
    //  Notify the LENDING library
    // ----------------------------------------------------
    if (!empty($lender_email)) {
        $lenderSubject = ill_subject('cron_expired_lender', '{system} ILL Expired — ILL# {ill}', ['ill' => $illnum, 'title' => $title]);
        $lenderMsg = ill_text('cron_expired_lender', 'heading', 'A request to your library has expired', $tv)
            . ill_text('cron_expired_lender', 'message', 'Your library did not answer this request within five business days, so it has expired and is now closed. No further action is needed.', $tv)
            . ill_email_details(['ILL #' => $illnum, 'Title' => (string) $title, 'Requested by' => (string) $requester, 'Requester email*' => ill_email_mailto((string) $email)])
            . ill_text('cron_expired_lender', 'closing', '', $tv);

        ill_mail($lender_email, $lenderSubject, $lenderMsg, $headers, "-f " . ILL_FROM_EMAIL);

        error_log(date('c') . " - $logprefix | Notified lending library at $lender_email\n", 3, $logfile);
    }

    // ----------------------------------------------------
    //  Notify ILL admin
    // ----------------------------------------------------
    $nocMessage = ill_email_heading('Request expired after 5 business days')
        . ill_email_details([
            'ILL #' => $illnum, 'Title' => (string) $title, 'Requested by' => (string) $requester, 'Requester email*' => ill_email_mailto((string) $email),
            'Lending library' => $lenderDisplay, 'Lending library email*' => ill_email_mailto((string) $lender_email), 'Expired' => ill_email_date($today),
        ]);

    // Optional copy for the ILL office (set ILL_OFFICE_EMAIL in cron_config.php to enable).
    if (ILL_OFFICE_EMAIL !== '') {
        ill_mail(
            ILL_OFFICE_EMAIL,
            ill_branding()['system'] . " ILL Expired $illnum ($lenderDisplay)",
            $nocMessage,
            $headers,
            "-f " . ILL_FROM_EMAIL
        );
    }


}

mysqli_close($db);
error_log(date('c') . " - ===== Completed expire.php =====\n", 3, $logfile);
echo "5-day expiration processing complete.\n";
