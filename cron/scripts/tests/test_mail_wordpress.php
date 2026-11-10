<?php
/**
 * Test-only helper: ILL_MAIL_VIA = 'wordpress' against a fake WordPress whose wp_mail() is defined by a plugin
 * (as FluentSMTP does). argv[1]: send | redirect | nopath | failing. Prints JSON: the wp_mail() calls, ill_mail()'s
 * results, the --check-system mail line, and whether WordPress's globals survived loading.
 */
$mode = $argv[1] ?? 'send';
$wp   = sys_get_temp_dir() . '/ill-fakewp-' . getmypid();
@mkdir("$wp/wp-content/plugins/fluent-smtp", 0700, true);
file_put_contents("$wp/wp-load.php", <<<'WP'
<?php
echo "output from WordPress that must not reach the log\n";
$table_prefix = 'wp_';                                    // like wp-config.php: only global if loaded in global scope
$GLOBALS['hooks'] = [];
function add_action($h, $cb) { $GLOBALS['hooks'][$h][] = $cb; }
function remove_action($h, $cb) { $GLOBALS['hooks'][$h] = array_filter($GLOBALS['hooks'][$h] ?? [], fn($x) => $x !== $cb); }
function has_action($h) { return !empty($GLOBALS['hooks'][$h]); }
function has_filter($h) { return has_action($h); }
class WP_Error { public function __construct(public $c = '', public $m = '') {} public function get_error_message() { return $this->m; } }
require __DIR__ . '/wp-content/plugins/fluent-smtp/mailer.php';
WP);
file_put_contents("$wp/wp-content/plugins/fluent-smtp/mailer.php", <<<'WP'
<?php
function wp_mail($to, $subject, $message, $headers = '', $att = []) {
    $GLOBALS['sent'][] = compact('to', 'subject', 'message', 'headers');
    if (str_contains($to, 'fail')) { foreach ($GLOBALS['hooks']['wp_mail_failed'] ?? [] as $cb) { $cb(new WP_Error('x', 'SMTP connect() failed')); } return false; }
    return true;
}
WP);
define('ILL_MAIL_VIA', 'wordpress');
define('ILL_WP_PATH', $mode === 'nopath' ? "$wp/missing" : $wp);
define('ILL_EMAIL_MODE', $mode === 'redirect' ? 'redirect' : 'send');
define('ILL_EMAIL_TEST_TO', 'tester@library.org');
define('ILL_LOG_FILE', __DIR__ . '/cron.log');
require __DIR__ . '/cron_config.php';
$GLOBALS['sent'] = [];
$html = ill_mail($mode === 'failing' ? 'fail@library.org' : 'lender@library.org', 'Reminder', '<p>Please answer</p>',
    "From: Town ILL <ill@library.org>\r\nCc: borrower@library.org\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit", '-f x');
$text = ill_mail('lender@library.org', 'Plain', 'Plain text', ['From' => 'ill@library.org']);
$check = array_values(array_filter(ill_system_findings(ill_system_facts()), fn($x) => $x[1] === 'Mail via WordPress'))[0] ?? null;
echo json_encode(['html_ok' => $html, 'text_ok' => $text, 'sent' => $GLOBALS['sent'], 'check' => $check,
    'prefix_global' => ($GLOBALS['table_prefix'] ?? null) === 'wp_', 'log' => (string) @file_get_contents(ILL_LOG_FILE)]);
array_map('unlink', ["$wp/wp-content/plugins/fluent-smtp/mailer.php", "$wp/wp-load.php"]);
