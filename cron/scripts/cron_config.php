<?php
/**
 * Shared settings for the scheduled (cron) scripts.
 *
 * Every script in this folder requires this file first. The values below are generic placeholders:
 * an organization sets its own addresses, links and paths in cron_local.php next to this file
 * (see cron_local.php.example; it is not in the repository), and the database logins in ill_db.inc
 * (never committed). The script and file names are the same for every organization; only the
 * folder is named after the org's short code.
 *
 * Location: /var/www/<code>_script/cron_config.php   (e.g. /var/www/town_script)
 */

// Cron scripts must never run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

// This organization's settings: any ILL_* constant below that cron_local.php defines wins.
if (is_file(__DIR__ . '/cron_local.php')) {
    require __DIR__ . '/cron_local.php';
}

defined('ILL_TIMEZONE') || define('ILL_TIMEZONE', 'America/New_York');
date_default_timezone_set(ILL_TIMEZONE);

// PHP 8.1+ makes mysqli throw an exception on any database error. These scripts were written to
// check each result themselves (log it, alert NOC, skip that request), so switch the exceptions off:
// a missing optional table (e.g. ILL-ROTA before Automatic Fallback is first used) or one failed
// update must not stop the whole run.
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

// Where every script appends its log lines (the user running cron must be able to write to it).
defined('ILL_LOG_FILE') || define('ILL_LOG_FILE', '/var/log/ill_cron.log');

// Email sent to lenders/requesters is From this name/address (also the envelope sender, mail -f).
defined('ILL_FROM_NAME') || define('ILL_FROM_NAME', 'Town Library ILL');
defined('ILL_FROM_EMAIL') || define('ILL_FROM_EMAIL', 'donotreply@library.org');

// Envelope sender (Return-Path, where bounces go; mail -f). It must be in a domain your mail relay accepts:
// Google Workspace's SMTP relay rejects an envelope domain that isn't registered to the Workspace holding the
// relay ("Invalid credentials for relay"). Use the same address the WordPress site's emails leave with.
// The visible From stays ILL_FROM_NAME <ILL_FROM_EMAIL>. Blank = let the server choose.
defined('ILL_ENVELOPE_FROM') || define('ILL_ENVELOPE_FROM', '');

// Start of the subject of alert emails to ILL_NOC_EMAIL.
defined('ILL_ALERT_PREFIX') || define('ILL_ALERT_PREFIX', 'ILL ALERT');

// Who is told when something cannot be processed (database update failed, no lender email, ...).
defined('ILL_NOC_EMAIL') || define('ILL_NOC_EMAIL', 'noc@library.org');

// Weekly statistics recipients (comma separated).
defined('ILL_STATS_TO') || define('ILL_STATS_TO', 'ill@library.org');

// Optional: a copy of each "request expired" notice for the ILL office. Empty string = do not send.
defined('ILL_OFFICE_EMAIL') || define('ILL_OFFICE_EMAIL', '');

// Public address of the site, used for the links inside emails (no trailing slash).
defined('ILL_BASE_URL') || define('ILL_BASE_URL', 'https://ill.library.org');

// How items filled through ILLiad are shipped, shown in the "will be filled" email (check_illiad.php).
defined('ILL_ILLIAD_SHIP_TEXT') || define('ILL_ILLIAD_SHIP_TEXT', 'Library Delivery');

// check_illiad_duedate.php: shipped requests older than this many months stop being checked
// with ILLiad and get the status "Assumed Complete after N months". 0 = never close them.
defined('ILL_AUTOCLOSE_MONTHS') || define('ILL_AUTOCLOSE_MONTHS', 7);

// anonymize.php (data retention; 0 turns a step off):
//   requests still unanswered after this many months are marked Expired
defined('ILL_ANON_EXPIRE_MONTHS') || define('ILL_ANON_EXPIRE_MONTHS', 18);
//   the request note of an expired request is cleared this many days after it expired
defined('ILL_ANON_NOTE_DAYS') || define('ILL_ANON_NOTE_DAYS', 30);
//   item details and notes of requests older than this many months are blanked (60 = 5 years)
defined('ILL_ANON_MONTHS') || define('ILL_ANON_MONTHS', 60);

// Set to false to turn off the live federal-holiday fetch below (e.g. the server has no outbound
// internet access, or you'd rather not depend on a third-party API). See ill_fetch_holidays_auto().
defined('ILL_HOLIDAY_AUTO_FETCH') || define('ILL_HOLIDAY_AUTO_FETCH', true);

// ISO country code passed to the Nager.Date API.
defined('ILL_HOLIDAY_COUNTRY') || define('ILL_HOLIDAY_COUNTRY', 'US');

// The API also returns state-only days (Good Friday in some states, Truman Day in Missouri) and
// observances (Lincoln's Birthday). Only nationwide holidays and holidays for this state count.
// Keep in step with SEAL_Org_Rules::HOLIDAY_REGION in the WordPress plugin.
defined('ILL_HOLIDAY_REGION') || define('ILL_HOLIDAY_REGION', 'US-NY');

/**
 * Keep only real closure days from a Nager.Date response: nationwide holidays, or holidays for
 * ILL_HOLIDAY_REGION, of type Public or Bank. Observances, school-only and optional days, and
 * other states' holidays are dropped.
 *
 * @return string[] YYYY-MM-DD dates
 */
function ill_filter_nager(array $data, string $region = ILL_HOLIDAY_REGION): array
{
    $out = [];
    foreach ($data as $h) {
        $d = trim((string) ($h['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
            continue;
        }
        $types = (array) ($h['types'] ?? []);
        if (!array_intersect($types, ['Public', 'Bank'])) {
            continue;
        }
        $counties = $h['counties'] ?? null;
        $applies  = !empty($h['global']) || (is_array($counties) && in_array($region, $counties, true));
        if ($applies) {
            $out[] = $d;
        }
    }
    return array_values(array_unique($out));
}

// Some settings live in WordPress (ILL -> Org Rules -> Closure Dates, ILL -> Contacts & Email ->
// Email test mode), so the scripts also read the WordPress database. Its login goes in ill_db.inc,
// next to the ILL database login (one file, never committed):
//   $wpDbHost, $wpDbUser, $wpDbPass, $wpDbName   (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME from wp-config.php)
//   $wpTablePrefix                                ($table_prefix from wp-config.php, usually 'wp_')
// A separate wp_db.inc holding the same variables is still read if ill_db.inc doesn't have them.
const ILL_DB_INC    = __DIR__ . '/ill_db.inc';
const ILL_WP_DB_INC = __DIR__ . '/wp_db.inc';

// Email test mode. 'wordpress' = follow WordPress: ILL -> Contacts & Email -> Email test mode (read
// with the WordPress login in ill_db.inc). Or force it here: 'send', 'redirect' (everything to ILL_EMAIL_TEST_TO) or 'off'.
// If WordPress can't be reached while following it, the scripts send normally (so reminders never
// stop silently in production) - while testing, set 'off' or 'redirect' here to be certain.
defined('ILL_EMAIL_MODE') || define('ILL_EMAIL_MODE', 'wordpress');
defined('ILL_EMAIL_TEST_TO') || define('ILL_EMAIL_TEST_TO', '');

/** @return array{mode:string,to:string} mode is send | redirect | off */
function ill_email_settings(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    if (ILL_EMAIL_MODE !== 'wordpress') {
        $mode = in_array(ILL_EMAIL_MODE, ['redirect', 'off'], true) ? ILL_EMAIL_MODE : 'send';
        return $s = ['mode' => $mode, 'to' => ILL_EMAIL_TEST_TO];
    }
    $mode = ill_wp_option('seal_email_mode');
    if ($mode === false) {
        error_log(date('c') . " - WARNING: could not read Email test mode from WordPress; sending email normally this run.\n", 3, ILL_LOG_FILE);
        return $s = ['mode' => 'send', 'to' => ''];
    }
    $to = ill_wp_option('seal_email_test_to');
    return $s = [
        'mode' => in_array($mode, ['redirect', 'off'], true) ? $mode : 'send',
        'to'   => is_string($to) ? $to : '',
    ];
}

/**
 * Every script sends email through this instead of mail(), so Email test mode applies to all of them.
 * Same arguments as mail().
 *
 * @param string|array $headers
 */
function ill_mail(string $to, string $subject, string $message, $headers = '', string $params = ''): bool
{
    // Envelope sender: the scripts used to force "-f <ILL_FROM_EMAIL>". Google's SMTP relay rejects that when the
    // domain isn't the Workspace the relay is registered to, so the envelope now comes only from ILL_ENVELOPE_FROM.
    $params = ILL_ENVELOPE_FROM !== '' ? '-f ' . ILL_ENVELOPE_FROM : '';
    $cfg = ill_email_settings();
    if ($cfg['mode'] === 'off') {
        error_log(date('c') . " - EMAIL TEST MODE (off): not sent to $to - $subject\n", 3, ILL_LOG_FILE);
        return true;
    }
    if ($cfg['mode'] === 'redirect') {
        // One or more test addresses, comma-separated.
        $test = implode(',', array_filter(array_map('trim', explode(',', (string) $cfg['to'])), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if ($test === '') {
            error_log(date('c') . " - EMAIL TEST MODE (redirect): no valid test address set, not sent to $to - $subject\n", 3, ILL_LOG_FILE);
            return true;
        }
        $lines = [];
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                $lines[] = is_string($k) ? "$k: $v" : (string) $v;
            }
        } else {
            $lines = preg_split('/\r\n|\n/', (string) $headers);
        }
        $cc = [];
        $keep = [];
        $html = false;
        foreach ($lines as $h) {
            if ($h === '') {
                continue;
            }
            if (preg_match('/^(Cc|Bcc):\s*(.*)$/i', $h, $m)) {
                $cc[] = trim($m[2]);
                continue;
            }
            $html = $html || stripos($h, 'text/html') !== false;
            $keep[] = $h;
        }
        $who = $to . ($cc ? ' (Cc: ' . implode(', ', $cc) . ')' : '');
        $message = $html
            ? ill_email_layout('<div style="border:2px solid #b32d2e;padding:8px 12px;margin:0 0 16px;color:#1d2327;">'
              . '<strong>TEST MODE</strong> - this email would have gone to: ' . ill_h($who) . '</div>' . $message, $subject)
            : "TEST MODE - this email would have gone to: $who\n\n" . $message;
        error_log(date('c') . " - EMAIL TEST MODE (redirect): sent to $test instead of $to - $subject\n", 3, ILL_LOG_FILE);
        [$keep, $message] = ill_mail_encode($keep, $message);
        return mail($test, '[TEST] ' . $subject, $message, implode("\r\n", $keep), $params);
    }
    $isHtml = stripos(is_array($headers) ? implode("\n", $headers) : (string) $headers, 'text/html') !== false;
    $lines  = ill_header_lines($headers);
    [$lines, $message] = ill_mail_encode($lines, $isHtml ? ill_email_layout($message, $subject) : $message);
    return mail($to, $subject, $message, implode("\r\n", $lines), $params);
}

/** Headers given as a string or an array (either "Name: value" lines or Name => value) as a list of lines. */
function ill_header_lines($headers): array
{
    $lines = [];
    if (is_array($headers)) {
        foreach ($headers as $k => $v) {
            $lines[] = is_string($k) ? "$k: $v" : (string) $v;
        }
    } else {
        $lines = preg_split('/\r\n|\n/', (string) $headers);
    }
    return array_values(array_filter($lines, static fn($h) => trim((string) $h) !== ''));
}

/**
 * Send the body base64-encoded (76-character lines). mail() passes the body through as-is, and the HTML layout is
 * one long line; mail servers break lines longer than 998 characters wherever they fall, which turned "</a>" into
 * "< /a>" in reminder emails. Any Content-Transfer-Encoding the script set is replaced.
 */
function ill_mail_encode(array $lines, string $body): array
{
    $lines   = array_values(array_filter($lines, static fn($h) => stripos(ltrim($h), 'Content-Transfer-Encoding:') !== 0));
    $has = static fn(string $name) => (bool) array_filter($lines, static fn($h) => stripos(ltrim($h), $name . ':') === 0);
    if (!$has('MIME-Version')) {
        $lines[] = 'MIME-Version: 1.0';
    }
    if (!$has('Content-Type')) {
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
    }
    $lines[] = 'Content-Transfer-Encoding: base64';
    return [$lines, rtrim(chunk_split(base64_encode($body), 76, "\r\n"))];
}

// ---------------------------------------------------------------------------------------------
// Email layout - the same look as the WordPress plugin's emails (SEAL_Functions::email_layout() and
// helpers in includes/class-seal-functions.php; keep the two in step). ill_mail() wraps every HTML
// message in it. Branding (colour, logo, names, footer) is read from WordPress (ILL -> Branding) with
// the WordPress login in ill_db.inc; if WordPress can't be reached the fallbacks below are used.
// ---------------------------------------------------------------------------------------------

/**
 * GET an ILLiad Web Platform URL with the library's API key and return the body ('' on any failure). Done in PHP,
 * never through the shell: the URL and key come from the library record, and a quote in either must not be able
 * to run commands (the old `curl` command lines could), and the key must not show in the process list.
 * Uses the curl extension when present, otherwise PHP's HTTPS streams. Certificates are verified; 20 s timeout.
 */
/**
 * An ILLiad API key as stored in the library table, ready to use: values encrypted by the plugin ("ik1:" sodium, "ik2:"
 * AES-256-GCM, key ILL_DATA_KEY) are decrypted with `$illDataKey` from ill_db.inc (the same base64 value as in
 * wp-config.php); an old plain-text value is returned as it is. '' (and a log line) when it can't be decrypted.
 */
function ill_secret_open(string $v): string
{
    if (strncmp($v, 'ik1:', 4) !== 0 && strncmp($v, 'ik2:', 4) !== 0) {
        return $v;
    }
    $key = base64_decode((string) ($GLOBALS['illDataKey'] ?? ''), true);
    $raw = base64_decode(substr($v, 4), true);
    if ($key === false || strlen($key) !== 32 || $raw === false) {
        error_log(date('c') . " - ILLiad key is encrypted but \$illDataKey in ill_db.inc is missing or not valid\n", 3, ILL_LOG_FILE);
        return '';
    }
    $p = false;
    if (strncmp($v, 'ik1:', 4) === 0 && function_exists('sodium_crypto_secretbox_open') && strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    } elseif (strncmp($v, 'ik2:', 4) === 0 && strlen($raw) > 28) {
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    }
    if ($p === false) {
        error_log(date('c') . " - ILLiad key could not be decrypted (\$illDataKey differs from ILL_DATA_KEY in wp-config.php?)\n", 3, ILL_LOG_FILE);
        return '';
    }
    return $p;
}

// The test runner (tests/shim.php) supplies its own ill_illiad_get(), so this one is only defined when it isn't.
if (!function_exists('ill_illiad_get')) {
    function ill_illiad_get(string $url, string $apikey): string
    {
        $apikey = ill_secret_open($apikey);   // stored encrypted by the plugin
        if ($apikey === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            error_log(date('c') . " - ILLiad URL refused (not http/https): " . substr($url, 0, 120) . "\n", 3, ILL_LOG_FILE);
            return '';
        }
        $apikey = str_replace(["\r", "\n"], '', $apikey);   // a header value must stay one line
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['ApiKey: ' . $apikey, 'Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return is_string($body) ? $body : '';
        }
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'header' => "ApiKey: $apikey\r\nAccept: application/json\r\n", 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }
}

/**
 * Opens a MySQL connection for the scripts. When a CA certificate is configured — `$dbSslCa = '/path/global-bundle.pem';`
 * in ill_db.inc (or `$wpDbSslCa` for the WordPress database, which otherwise uses the same file) — the connection is
 * encrypted and the server's certificate is verified, the same as the plugin's "Require Encrypted Connection" + CA path;
 * if that file is missing the script refuses to connect rather than fall back to plain text. With no CA set it connects
 * as before (unencrypted unless the server insists). Returns the mysqli connection or false (mysqli_connect_error()
 * has the reason).
 */
function ill_db_connect(string $host, string $user, string $pass, string $name, ?string $ca = null)
{
    if ($ca === null) {
        $ca = (string) ($GLOBALS['dbSslCa'] ?? '');
    }
    $ca = trim($ca);
    if ($ca === '') {
        return mysqli_connect($host, $user, $pass, $name);
    }
    if (!is_readable($ca)) {
        error_log(date('c') . " - DB connection refused: CA certificate $ca (\$dbSslCa in ill_db.inc) is missing or not readable\n", 3, ILL_LOG_FILE);
        return false;
    }
    $db = mysqli_init();
    mysqli_options($db, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    mysqli_ssl_set($db, null, null, $ca, null, null);
    if (!@mysqli_real_connect($db, $host, $user, $pass, $name, null, null, MYSQLI_CLIENT_SSL)) {
        return false;
    }
    return $db;
}

function ill_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** @return array{brand:string,system:string,full:string,logo:string,footer:string,site:string} */
/**
 * The subject for one email: the template saved under ILL → Email Subjects in WordPress (option
 * seal_subject_<key>), or $default when it is blank or WordPress can't be read. {system} and each $vars key in
 * braces are filled in. $default must match the default for that key in the plugin's SEAL_Emails::all().
 */
function ill_subject(string $key, string $default, array $vars = []): string
{
    $tpl = function_exists('ill_wp_option') ? ill_wp_option('seal_subject_' . $key) : null;
    $tpl = is_string($tpl) && trim($tpl) !== '' ? trim($tpl) : $default;
    $map = ['{system}' => ill_branding()['system']];
    foreach ($vars as $k => $v) {
        $map['{' . $k . '}'] = strip_tags((string) $v);
    }
    return trim(preg_replace('/\s+/', ' ', strtr($tpl, $map)));
}

/**
 * Saved email text (ILL → Email Subjects & Text in WordPress) as HTML, the same rules as the plugin's
 * SEAL_Emails::render(): everything escaped, tokens filled in, **bold**, *italic*, a blank line = new paragraph.
 * $part is 'heading', 'message' or 'closing'. Blank or unreadable = $default; "-" = leave the part out.
 * $default must match the plugin's SEAL_Emails::texts() for that key.
 */
function ill_text(string $key, string $part, string $default, array $vars = []): string
{
    $v = function_exists('ill_wp_option') ? ill_wp_option('seal_emailtext_' . $key . '_' . $part) : null;
    $v = is_string($v) ? trim($v) : '';
    $text = $v === '-' ? '' : ($v !== '' ? $v : $default);
    $text = trim(str_replace("\r", '', $text));
    if ($text === '') {
        return '';
    }
    $map = ['{system}' => ill_h(ill_branding()['system'])];
    foreach ($vars as $k => $val) {
        $map['{' . $k . '}'] = ill_h((string) $val);
    }
    $html = strtr(ill_h($text), $map);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $html);
    if ($part === 'heading') {
        return '<h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#1d2327;">' . $html . '</h1>';
    }
    $out = '';
    foreach (preg_split('/\n\s*\n/', $html) as $para) {
        $out .= '<p style="margin:0 0 14px;">' . nl2br(trim($para), false) . '</p>';
    }
    return $out;
}

function ill_branding(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $get = static function (string $key, string $default): string {
        $v = function_exists('ill_wp_option') ? ill_wp_option('seal_' . $key) : null;
        return is_string($v) && trim($v) !== '' ? trim($v) : $default;
    };
    $brand = $get('brand_primary', '#4a4180');
    return $b = [
        'brand'  => preg_match('/^#[0-9a-f]{6}$/i', $brand) ? $brand : '#4a4180',
        'system' => $get('system_name', ILL_FROM_NAME),
        'full'   => $get('system_full_name', ''),
        'org'    => $get('org_full_name', $get('org_name', '')),   // Org Full Name, else Org Short Name
        'logo'   => $get('header_logo_url', ''),
        'footer' => $get('footer_text', ''),
        'site'   => rtrim(ILL_BASE_URL, '/') . '/',
    ];
}

/**
 * Wraps an email body in the shared layout (header with logo or system name, white card, footer with "Sent by
 * …, a service hosted by …"). Mirrors SEAL_Functions::email_layout() in the plugin.
 */
function ill_email_layout(string $body, string $subject = ''): string
{
    $b      = ill_branding();
    $body   = preg_replace('#</?(html|body)[^>]*>#i', '', $body);
    $logoOk = $b['logo'] !== '' && preg_match('#^https?://#i', $b['logo']);
    $head   = $logoOk
        ? '<img src="' . ill_h($b['logo']) . '" alt="' . ill_h($b['system']) . '" style="max-height:48px;max-width:240px;border:0;display:block;">'
        : '<span style="font-size:20px;font-weight:bold;color:#ffffff;">' . ill_h($b['system']) . '</span>';
    $headBg = $logoOk ? '#ffffff' : $b['brand'];
    $host   = preg_replace('#^https?://#', '', rtrim($b['site'], '/'));
    return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . ill_h($subject) . '</title></head>'
         . '<body style="margin:0;padding:0;background:#f2f3f5;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f3f5;"><tr><td align="center" style="padding:24px 12px;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #dcdcde;border-radius:6px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;">'
         . '<tr><td style="background:' . $headBg . ';padding:16px 24px;border-bottom:4px solid ' . $b['brand'] . ';border-radius:6px 6px 0 0;">' . $head . '</td></tr>'
         . '<tr><td style="padding:24px;">' . $body . '</td></tr>'
         . '<tr><td style="padding:14px 24px;border-top:1px solid #dcdcde;font-size:12px;color:#50575e;">'
         . ($b['footer'] !== '' ? ill_h($b['footer']) . '<br>' : '')
         . 'Sent by ' . ill_h($b['system']) . ($b['org'] !== '' ? ', a service hosted by ' . ill_h($b['org']) : '')
         . ' &middot; <a href="' . ill_h($b['site']) . '" style="color:' . $b['brand'] . ';">' . ill_h($host) . '</a>'
         . '</td></tr></table></td></tr></table></body></html>';
}

/** The email's main heading (escaped). */
function ill_email_heading(string $text): string
{
    return '<h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#1d2327;">' . ill_h($text) . '</h1>';
}

/** A section heading inside an email (escaped). */
function ill_email_subheading(string $text): string
{
    return '<h2 style="margin:18px 0 8px;font-size:16px;line-height:1.3;color:#1d2327;">' . ill_h($text) . '</h2>';
}

/** A paragraph of already-built HTML (escape values with ill_h()). */
function ill_email_p(string $html): string
{
    return '<p style="margin:0 0 14px;">' . $html . '</p>';
}

/** Label => value rows; values are escaped unless the label ends with '*' (pre-built HTML). Empty values are skipped. */
function ill_email_details(array $rows): string
{
    $out = '';
    foreach ($rows as $label => $value) {
        $html  = substr((string) $label, -1) === '*';
        $label = rtrim((string) $label, '*');
        if (trim(strip_tags((string) $value)) === '') {
            continue;
        }
        $out .= '<tr><th align="left" valign="top" style="padding:8px 12px 8px 0;border-bottom:1px solid #eeeeee;font-weight:bold;white-space:nowrap;width:1%;">' . ill_h($label) . '</th>'
              . '<td valign="top" style="padding:8px 0;border-bottom:1px solid #eeeeee;">' . ($html ? $value : nl2br(ill_h($value))) . '</td></tr>';
    }
    return $out !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-top:1px solid #eeeeee;">' . $out . '</table>' : '';
}

/** Buttons side by side: [[url, label, colour or '' for the brand colour], ...]. */
function ill_email_buttons(array $buttons): string
{
    $brand = ill_branding()['brand'];
    $out   = '';
    foreach ($buttons as [$url, $label, $bg]) {
        $out .= '<a href="' . ill_h($url) . '" style="display:inline-block;background:' . ($bg !== '' ? $bg : $brand) . ';color:#ffffff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:4px;margin:0 10px 8px 0;">' . ill_h($label) . '</a>';
    }
    return '<p style="margin:18px 0 6px;">' . $out . '</p>';
}

/** One button-style link in the brand color. */
function ill_email_button(string $url, string $label): string
{
    return ill_email_buttons([[$url, $label, '']]);
}

/** Addresses (comma or semicolon separated) as mailto links, one per line. */
function ill_email_mailto(string $emails): string
{
    $out = [];
    foreach (array_filter(array_map('trim', preg_split('/[,;]/', $emails))) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $out[] = '<a href="mailto:' . ill_h($e) . '">' . ill_h($e) . '</a>';
        }
    }
    return implode('<br>', $out);
}

/** YYYY-MM-DD or MM/DD/YYYY as "November 1, 2026"; anything unreadable comes back unchanged. */
function ill_email_date(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? strtotime($date . ' 12:00:00') : strtotime($date);
    return $ts ? date('F j, Y', $ts) : $date;
}

/** The article details stored on a request ("Article Title: x<br>Volume: y<br>...") as plain lines. */
function ill_email_article(string $article): string
{
    return trim(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $article)));
}

/** A system alert for the NOC / ILL office: red label, heading, details, optional closing sentence. */
function ill_email_alert(string $heading, array $rows, string $closing = ''): string
{
    return '<p style="margin:0 0 6px;font-size:12px;font-weight:bold;letter-spacing:.05em;text-transform:uppercase;color:#b32d2e;">System alert</p>'
         . ill_email_heading($heading)
         . '<div style="border-left:4px solid #b32d2e;padding:2px 0 2px 14px;">' . ill_email_details($rows)
         . ($closing !== '' ? ill_email_p(ill_h($closing)) : '') . '</div>';
}

/**
 * The organization's own closures entered under ILL → Org Rules → Closure Dates (WordPress), read directly
 * from the WordPress database's wp_options table — this script never loads WordPress itself. These
 * are the dates Nager.Date's public-holiday API does not know about: winter break, a staff day,
 * and so on. See ill_get_holidays() for how this is combined with the auto-fetched federal list.
 *
 * Never throws: no WordPress login in ill_db.inc, a bad connection, or a missing/empty option all just mean no
 * own closures this run — the auto-fetched federal holidays still apply.
 *
 * @return string[] YYYY-MM-DD dates, not yet deduped/sorted
 */
/**
 * One WordPress setting, read straight from the WordPress database's options table using the WordPress login in ill_db.inc
 * (these scripts never load WordPress). The connection is opened once per run and reused.
 *
 * @return string|null|false the value; null if the setting has never been saved; false if the
 *                           WordPress database can't be reached (details go to the cron log)
 */
function ill_wp_option(string $name)
{
    static $wp = null, $table = '', $failed = false;
    if ($failed) {
        return false;
    }
    if ($wp === null) {
        $wpDbHost = $wpDbUser = $wpDbPass = $wpDbName = $wpTablePrefix = '';
        $dbSslCa = $wpDbSslCa = null;
        if (is_readable(ILL_DB_INC)) {
            require ILL_DB_INC;           // plain variables only; safe to read again here
        }
        if ($wpDbHost === '' && is_readable(ILL_WP_DB_INC)) {
            require ILL_WP_DB_INC;        // older layout: WordPress login in its own file
        }
        if ($wpDbHost === '' || $wpDbName === '') {
            error_log(date('c') . ' - NOTE: no WordPress database login ($wpDbHost ... $wpDbName) in ill_db.inc, so WordPress settings (Closure Dates, Email test mode) cannot be read this run. See cron/scripts/README.md.' . "\n", 3, ILL_LOG_FILE);
            $failed = true;
            return false;
        }
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $wpTablePrefix !== '' ? $wpTablePrefix : 'wp_') . 'options';
        try {
            $wp = ill_db_connect($wpDbHost, $wpDbUser, $wpDbPass, $wpDbName, (string) ($wpDbSslCa ?? $dbSslCa ?? ''));
            if (!$wp) {
                throw new RuntimeException((string) mysqli_connect_error());
            }
        } catch (Throwable $e) {
            error_log(date('c') . ' - WARNING: could not connect to the WordPress database (check the $wpDb... lines in ill_db.inc): ' . $e->getMessage() . "\n", 3, ILL_LOG_FILE);
            $wp = null;
            $failed = true;
            return false;
        }
    }
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $name);
    try {
        $result = mysqli_query($wp, "SELECT `option_value` FROM `$table` WHERE `option_name` = '$safe' LIMIT 1");
        if (!$result) {
            throw new RuntimeException((string) mysqli_error($wp));
        }
        $row = mysqli_fetch_assoc($result);
        return $row ? (string) $row['option_value'] : null;
    } catch (Throwable $e) {
        error_log(date('c') . " - WARNING: could not read WordPress setting $safe: " . $e->getMessage() . "\n", 3, ILL_LOG_FILE);
        return false;
    }
}

/**
 * The Closure Dates list from WordPress (Org Rules → Closure Dates) as YYYY-MM-DD dates. Returns [] and logs a
 * warning when WordPress can't be read.
 */
function ill_fetch_wp_closures(): array
{
    $raw = ill_wp_option('seal_rules_closures');
    if ($raw === false) {
        error_log(date('c') . " - WARNING: could not read Closure Dates from the WordPress database (check the \$wpDb... lines in ill_db.inc) - continuing with US holidays only.\n", 3, ILL_LOG_FILE);
        return [];
    }
    $raw = (string) $raw;

    // Same line format as every other Org Rules list: "YYYY-MM-DD|Label", "#" comments, blank
    // lines skipped, malformed dates silently dropped (the WordPress admin page validates cells
    // in the browser, but this script re-checks independently rather than trusting stored data).
    $clean = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $date = trim(explode('|', $line, 2)[0]);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $clean[] = $date;
        }
    }
    return $clean;
}

/**
 * Which years' holidays to load on a given day: last year and this year, plus next year in December.
 * Last year matters in early January (a request from late December is still being counted);
 * next year matters in December (a count that starts before New Year's runs into January).
 *
 * @return int[]
 */
function ill_holiday_years(int $now): array
{
    $year  = (int) date('Y', $now);
    $years = [$year - 1, $year];
    if ((int) date('n', $now) === 12) {
        $years[] = $year + 1;
    }
    return $years;
}

/**
 * Winter Break for the break that STARTS in $year: Dec 24 of $year through Jan 1 of $year + 1.
 * Always part of the closure calendar, whether or not the online holiday fetch is on.
 *
 * @return string[] YYYY-MM-DD
 */
function ill_winter_break(int $year): array
{
    $out = [];
    $d   = new DateTime("{$year}-12-24", new DateTimeZone('America/New_York'));
    $end = new DateTime(($year + 1) . '-01-01', new DateTimeZone('America/New_York'));
    while ($d <= $end) {
        $out[] = $d->format('Y-m-d');
        $d->modify('+1 day');
    }
    return $out;
}

/** Saturday and Sunday are never business days, and neither is any date in $holidays. */
function isBusinessDay(string $ymd, array $holidays): bool
{
    $dow = (int) (new DateTime($ymd, new DateTimeZone('America/New_York')))->format('N'); // 1=Mon .. 7=Sun
    return $dow < 6 && !in_array($ymd, $holidays, true);
}

/**
 * The date that is exactly $days business days after $startDate. Counting starts the next day
 * (unless $includeStart), so a request made on a Saturday counts Monday as day 1. Works purely on
 * calendar dates in New York time, so daylight-saving changes and New Year's can't shift it.
 */
function addBusinessDays(string $startDate, int $days, array $holidays, bool $includeStart = false): string
{
    $date = new DateTime(substr($startDate, 0, 10), new DateTimeZone('America/New_York'));
    if (!$includeStart) {
        $date->modify('+1 day');
    }
    $added = 0;
    while (true) {
        if (isBusinessDay($date->format('Y-m-d'), $holidays) && ++$added >= $days) {
            return $date->format('Y-m-d');
        }
        $date->modify('+1 day');
    }
}

/**
 * US federal holidays fetched live from the Nager.Date API (https://date.nager.at), cached to a
 * JSON file per year for up to 7 days. (Winter Break is added separately by ill_get_holidays().)
 * Ported at the user's request from SEAL-3's seal_3day_reminder.php getHolidaysAuto() — same
 * source, same caching window, same Winter Break dates.
 *
 * Never throws: a network failure (or ILL_HOLIDAY_AUTO_FETCH being off) falls back to the last
 * cached copy for that year, or an empty list for it — ill_get_holidays() still has the Closure
 * Dates list from WordPress either way.
 *
 * @return string[] YYYY-MM-DD dates, not yet deduped/sorted
 */
function ill_fetch_holidays_auto(string $country = ILL_HOLIDAY_COUNTRY): array
{
    if (!ILL_HOLIDAY_AUTO_FETCH) {
        return [];
    }

    $years = ill_holiday_years(time());

    $holidays = [];
    foreach ($years as $year) {
        $cacheFile = __DIR__ . "/holidays_{$year}.json";
        $url       = "https://date.nager.at/api/v3/PublicHolidays/{$year}/{$country}";
        $data      = [];

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
            // Cached JSON, valid for up to 7 days.
            $data = json_decode((string) file_get_contents($cacheFile), true);
            if (!is_array($data)) {
                $data = [];
            }
        } else {
            $context = stream_context_create(['http' => ['timeout' => 10]]);
            $json    = @file_get_contents($url, false, $context);
            if ($json === false) {
                error_log(date('c') . " - Holiday fetch failed for $year, using cache/fallback\n", 3, ILL_LOG_FILE);
                $data = file_exists($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : [];
                if (!is_array($data)) {
                    $data = [];
                }
            } else {
                file_put_contents($cacheFile, $json);
                $data = json_decode($json, true);
                if (!is_array($data)) {
                    $data = [];
                }
            }
        }

        // Only nationwide / this state's holidays, and only well-formed dates (ill_filter_nager).
        foreach (ill_filter_nager($data) as $d) {
            $holidays[] = $d;
        }

    }

    error_log(
        date('c') . ' - Loaded ' . count(array_unique($holidays)) . ' auto-fetched holidays for years: ' . implode(',', $years) . "\n",
        3,
        ILL_LOG_FILE
    );
    return $holidays;
}

/**
 * The full closure calendar used by the reminder, expire and rota jobs to compute business days:
 * the organization's own closures entered under ILL → Org Rules → Closure Dates in WordPress, plus (unless
 * ILL_HOLIDAY_AUTO_FETCH is off) auto-fetched US federal holidays and Winter Break. Warns (cron
 * log, and echoed to stdout) once fewer than 30 days of future closures remain in the combined
 * list.
 *
 * @return string[] sorted, unique YYYY-MM-DD dates
 */
function ill_get_holidays(): array
{
    $manual = ill_fetch_wp_closures();
    $auto   = ill_fetch_holidays_auto();
    $winter = [];
    foreach (ill_holiday_years(time()) as $y) {
        $winter = array_merge($winter, ill_winter_break($y));
    }

    $clean = array_values(array_unique(array_merge($manual, $auto, $winter)));
    sort($clean);

    $last = $clean ? end($clean) : '';
    if ($last === '' || $last < date('Y-m-d', strtotime('+30 days'))) {
        $msg = 'WARNING: holiday calendar has no closure dates beyond ' . ($last ?: 'today') . ' - check ILL -> Org Rules -> Closure Dates in WordPress'
             . (ILL_HOLIDAY_AUTO_FETCH ? ' (auto-fetch is on, so this should be rare).' : '.');
        error_log(date('c') . ' - ' . $msg . "\n", 3, ILL_LOG_FILE);
        echo $msg . "\n";
    }
    return $clean;
}

// `php cron_config.php --check-db` (from the scripts folder): connect to both databases the way the jobs do and show
// whether each connection is encrypted. Changes nothing.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && in_array('--check-db', $argv ?? [], true)) {
    $cipher = static function ($db): string {
        $r = $db ? mysqli_query($db, "SHOW SESSION STATUS LIKE 'Ssl_cipher'") : false;
        $row = $r ? mysqli_fetch_row($r) : null;
        return ($row && $row[1] !== '') ? 'encrypted (' . $row[1] . ')' : 'NOT encrypted';
    };
    require ILL_DB_INC;
    $ill = ill_db_connect($dbhost, $dbuser, $dbpass, $dbname);
    echo 'ILL database:       ' . ($ill ? $cipher($ill) : 'connection failed: ' . mysqli_connect_error()) . "\n";
    echo 'CA certificate:     ' . (trim((string) ($dbSslCa ?? '')) !== '' ? $dbSslCa . ' (server certificate verified)' : 'none set ($dbSslCa in ill_db.inc)') . "\n";
    if (($wpDbHost ?? '') !== '') {
        $wpc = ill_db_connect($wpDbHost, $wpDbUser, $wpDbPass, $wpDbName, (string) ($wpDbSslCa ?? $dbSslCa ?? ''));
        echo 'WordPress database: ' . ($wpc ? $cipher($wpc) : 'connection failed: ' . mysqli_connect_error()) . "\n";
    }
    if ($ill) {   // ILLiad API keys: can this script read the encrypted ones?
        $n = ['plain' => 0, 'ok' => 0, 'bad' => 0];
        $r = mysqli_query($ill, "SELECT `APIkey` FROM `" . str_replace('`', '', $illLIB) . "` WHERE `APIkey` IS NOT NULL AND `APIkey`<>''");
        while ($r && ($row = mysqli_fetch_row($r))) {
            $v = (string) $row[0];
            if (strncmp($v, 'ik1:', 4) !== 0 && strncmp($v, 'ik2:', 4) !== 0) { $n['plain']++; continue; }
            ill_secret_open($v) !== '' ? $n['ok']++ : $n['bad']++;
        }
        echo 'ILLiad API keys:    ' . $n['ok'] . ' encrypted and readable, ' . $n['bad'] . ' encrypted but NOT readable, ' . $n['plain'] . ' plain text'
            . (($illDataKey ?? '') === '' ? '  ($illDataKey not set in ill_db.inc)' : '') . "\n";
    }
}
