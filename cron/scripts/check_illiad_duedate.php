<?php

require __DIR__ . '/cron_config.php';
/**
 * ILL / ILLiad Due Date Checker
 * Purpose: For shipped items, poll ILLiad for updated DueDate; update ILL DB and notify requester if changed.
 * Location: /var/www/<code>_script/check_illiad_duedate.php
 */

set_time_limit(1800); // 30-minute max runtime

// === DB connect ===
require __DIR__ . '/ill_db.inc';
$db = ill_db_connect($dbhost, $dbuser, $dbpass, $dbname);
if (!$db) {
    error_log(date('c') . " - DB connection failed: " . mysqli_connect_error() . "\n", 3, ILL_LOG_FILE);
    die("DB connection failed\n");
}
$db->set_charset("utf8mb4");

error_log(date('c') . " - ===== Starting check_illiad_duedate.php =====\n", 3, ILL_LOG_FILE);

// === Fetch requests currently marked as Shipped ===
$sqlselect = "
    SELECT *
    FROM `$illSTAT`
    WHERE `IlliadStatus` LIKE '%Shipped%'
      AND `IlliadTransID` <> ''
      AND `Title` <> ''
";
$retval = mysqli_query($db, $sqlselect);
if (!$retval) {
    error_log(date('c') . " - Query failed: " . mysqli_error($db) . "\n", 3, ILL_LOG_FILE);
    exit;
}
$GETLISTCOUNT = mysqli_num_rows($retval);
echo "Found $GETLISTCOUNT shipped requests to verify due date\n";
error_log(date('c') . " - Found $GETLISTCOUNT shipped requests\n", 3, ILL_LOG_FILE);

while ($row = mysqli_fetch_assoc($retval)) {
    $Illiadid        = trim((string)$row["IlliadTransID"]);
    $sqlidnumb       = (int)$row["index"];
    $reqnumb         = trim((string)$row['illNUB']);
    $destloc         = trim((string)$row['Destination']);
    $title           = trim((string)$row['Title']);
    $origDueDate     = trim((string)$row['DueDate']);
    $requesterEMAIL  = trim((string)$row['requesterEMAIL']);

    if ($Illiadid === '') continue;

    // --- Destination library info ---
    $destloc_safe = mysqli_real_escape_string($db, $destloc);
    $GETLISTSQLDEST = "
        SELECT `APIkey`, `IlliadURL`, `Name`, `ill_email`
        FROM `$illLIB`
        WHERE `loc` LIKE '$destloc_safe'
        LIMIT 1
    ";
    $resultdest = mysqli_query($db, $GETLISTSQLDEST);
    if (!$resultdest || mysqli_num_rows($resultdest) === 0) {
        error_log(date('c') . " - Missing destination record for loc=$destloc (index $sqlidnumb)\n", 3, ILL_LOG_FILE);
        continue;
    }

    $rowdest     = mysqli_fetch_assoc($resultdest);
    $destlibname = $rowdest["Name"]     ?? '';
    $apikey      = $rowdest["APIkey"]   ?? '';
    $illiadURL   = $rowdest["IlliadURL"]?? '';
    $destemail   = $rowdest["ill_email"]?? '';

    // --- Build and execute ILLiad API request ---
    $url = rtrim($illiadURL, '/') . '/' . rawurlencode($Illiadid);
    $output = ill_illiad_get($url, (string) $apikey);   // no shell (see cron_config.php)
    $data = json_decode($output, true);

    if (!is_array($data)) {
        error_log(date('c') . " - Invalid JSON for ILLiadID $Illiadid | URL=$url | Output=" . substr((string)$output, 0, 200) . "\n", 3, ILL_LOG_FILE);
        continue;
    }

    $status        = $data['TransactionStatus']     ?? '';
    $reasonCancel  = $data['ReasonForCancellation'] ?? '';
    $dueDateApi    = $data['DueDate']               ?? '';
    $dueDate       = $dueDateApi ? (strstr($dueDateApi, 'T', true) ?: $dueDateApi) : '';

    $logprefix = "ILL#$reqnumb ($destlibname / index $sqlidnumb)";
    error_log(date('c') . " - Checking $logprefix | Status: $status | DueDate: $dueDate\n", 3, ILL_LOG_FILE);

    // === Handle Cancelled ===
    if ((stripos($status, 'cancelled') !== false) && !empty($reasonCancel)) {
        $reasontxt = 'Not specified'; $nofillreason = "0";
        if (stripos($reasonCancel, 'In use') !== false)        { $reasontxt='In Use'; $nofillreason="20"; }
        elseif (stripos($reasonCancel, 'Lost') !== false)      { $reasontxt='Lost'; $nofillreason="21"; }
        elseif (stripos($reasonCancel, 'non') !== false)       { $reasontxt='Non-Circulating'; $nofillreason="22"; }
        elseif (stripos($reasonCancel, 'Not on shelf') !== false){ $reasontxt='Not on shelf'; $nofillreason="23"; }
        elseif (stripos($reasonCancel, 'Poor condition') !== false){ $reasontxt='Poor condition'; $nofillreason="24"; }

        $status_sql = mysqli_real_escape_string($db, $status);
        $sqlupdate  = "UPDATE `$illSTAT` SET `reasonNotFilled`='$nofillreason', `Fill`='0', `IlliadStatus`='$status_sql' WHERE `index`=$sqlidnumb";
        if (!mysqli_query($db, $sqlupdate)) {
            $err = mysqli_error($db);
            error_log(date('c') . " - $logprefix | Cancel update failed: $err\n", 3, ILL_LOG_FILE);
        } else {
            error_log(date('c') . " - $logprefix | Marked cancelled ($reasontxt)\n", 3, ILL_LOG_FILE);
        }
        continue;
    }

    // === Handle Shipped (due date changes) ===
    $is_shipped = stripos($status, 'shipped') !== false;
    if ($is_shipped) {
        $origNorm = preg_replace('/\s+/', '', strtolower($origDueDate));
        // Stored dates may be legacy MM/DD/YYYY; ILLiad and the plugin use YYYY-MM-DD.
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $origNorm, $m)) {
            $origNorm = sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
        }
        if ($origNorm === 'none') $origNorm = '';
        $newNorm = preg_replace('/\s+/', '', strtolower($dueDate));

        if ($newNorm !== $origNorm && strlen($newNorm) >= 4) {
            $due_sql = mysqli_real_escape_string($db, $dueDate);
            $status_sql = mysqli_real_escape_string($db, $status);
            $sqlupdate = "UPDATE `$illSTAT` SET `DueDate`='$due_sql', `IlliadStatus`='$status_sql' WHERE `index`=$sqlidnumb";
            if (mysqli_query($db, $sqlupdate)) {
                error_log(date('c') . " - $logprefix | Due date updated to $dueDate\n", 3, ILL_LOG_FILE);

                // Email borrower on due date change
                if (filter_var($requesterEMAIL, FILTER_VALIDATE_EMAIL)) {
                    $headers  = "From: " . ILL_FROM_NAME . " <" . ILL_FROM_EMAIL . ">\r\n";
                    $headers .= "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $subject  = ill_subject('cron_new_due_date', 'Request {ill} has a new due date', ['ill' => $reqnumb]);
                    $tv       = ['ill' => $reqnumb, 'title' => $title];
                    $message  = ill_text('cron_new_due_date', 'heading', 'New due date', $tv)
                        . ill_text('cron_new_due_date', 'message', 'The lending library has changed the due date of an item you borrowed.', $tv)
                        . ill_email_details(['ILL #' => (string) $reqnumb, 'Title' => (string) $title, 'Lending library' => (string) $destlibname, 'New due date' => ill_email_date((string) $dueDate)])
                        . ill_email_button(rtrim(ILL_BASE_URL, '/') . '/requesthistory', 'View your borrowing requests')
                        . ill_text('cron_new_due_date', 'closing', '', $tv);
                    ill_mail($requesterEMAIL, $subject, $message, $headers, "-f " . ILL_FROM_EMAIL);
                    error_log(date('c') . " - $logprefix | Email sent to requester ($requesterEMAIL)\n", 3, ILL_LOG_FILE);
                }
            } else {
                $err = mysqli_error($db);
                error_log(date('c') . " - $logprefix | DB update failed: $err\n", 3, ILL_LOG_FILE);
            }
        } else {
            error_log(date('c') . " - $logprefix | No due date change ($origDueDate)\n", 3, ILL_LOG_FILE);
        }
    }
}

// === Auto-close very old shipped requests (ILL_AUTOCLOSE_MONTHS; 0 = off) ===
if (ILL_AUTOCLOSE_MONTHS > 0) {
$sqlselect_old = "
    SELECT `index`
    FROM `$illSTAT`
    WHERE `IlliadStatus` LIKE '%Shipped%'
      AND `TimeStamp` < DATE_SUB(NOW(), INTERVAL " . (int) ILL_AUTOCLOSE_MONTHS . " MONTH)
";
$retval_old = mysqli_query($db, $sqlselect_old);
if ($retval_old) {
    while ($row = mysqli_fetch_assoc($retval_old)) {
        $sqlidnumb = (int)$row['index'];
        $sqlupdate = "
            UPDATE `$illSTAT`
            SET `IlliadStatus` = 'Assumed Complete after " . (int) ILL_AUTOCLOSE_MONTHS . " months'
            WHERE `index` = $sqlidnumb
        ";
        if (mysqli_query($db, $sqlupdate)) {
            error_log(date('c') . " - Index $sqlidnumb | Auto-closed (" . (int) ILL_AUTOCLOSE_MONTHS . "-month rule)\n", 3, ILL_LOG_FILE);
        } else {
            error_log(date('c') . " - Index $sqlidnumb | Auto-close failed: " . mysqli_error($db) . "\n", 3, ILL_LOG_FILE);
        }
    }
}
}

mysqli_close($db);
error_log(date('c') . " - ===== Completed check_illiad_duedate.php =====\n", 3, ILL_LOG_FILE);
echo "Due date check complete.\n";
