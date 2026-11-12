<?php
if (PHP_SAPI !== 'cli') { exit; } // Test code: never runs over the web.
/**
 * Minimal WordPress / WooCommerce stubs for exercising the NFT Checkout
 * plugin's payment paths without WordPress, a database or a network.
 */
define('ABSPATH', __DIR__ . '/');
define('WP_UNINSTALL_PLUGIN', false);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');
define('NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_PHP_VER', '8.0');
define('NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER', '6.5.4');
define('NFT_CHECKOUT_PAYMENT_GATEWAY_FILE', $GLOBALS['plugin_dir'] . '/nft-checkout-payment-gateway.php');
define('NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR', $GLOBALS['plugin_dir'] . '/');
define('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP', 'nft_checkout_payment_gateway');

$GLOBALS['options']    = [];
$GLOBALS['transients'] = [];
$GLOBALS['cache']      = [];
$GLOBALS['orders']     = [];
$GLOBALS['http_log']   = [];
$GLOBALS['http']       = function ($url, $args) { return ['response' => ['code' => 404], 'body' => '{}']; };
$GLOBALS['notices']    = [];
$GLOBALS['mails']      = [];
$GLOBALS['hooks']      = [];
$GLOBALS['log']        = [];

class RedirectException extends Exception { public $url; public function __construct($url) { parent::__construct('redirect'); $this->url = $url; } }

function plugin_dir_path($f) { return rtrim(dirname($f), '/\\') . '/'; }
function plugin_dir_url($f) { return 'https://shop.test/wp-content/plugins/nft-checkout-payment-gateway/'; }
function plugin_basename($f) { return 'nft-checkout-payment-gateway/nft-checkout-payment-gateway.php'; }
function add_action($hook, $cb, $p = 10, $a = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function add_filter($hook, $cb, $p = 10, $a = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function apply_filters($hook, $value) { return $value; }
function did_action($hook) { return 0; }
function current_filter() { return ''; }
function register_rest_route($ns, $route, $args) { $GLOBALS['routes'][$ns . $route] = $args; }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function add_option($k, $v, $d = '', $autoload = 'yes') { if (array_key_exists($k, $GLOBALS['options'])) return false; $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function get_transient($k) { if (!isset($GLOBALS['transients'][$k])) return false; [$v, $exp] = $GLOBALS['transients'][$k]; if ($exp && $exp < time()) { unset($GLOBALS['transients'][$k]); return false; } return $v; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['transients'][$k] = [$v, $ttl ? time() + $ttl : 0]; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_cache_get($k, $g = '') { return $GLOBALS['cache'][$g . '|' . $k] ?? false; }
function wp_cache_set($k, $v, $g = '', $ttl = 0) { $GLOBALS['cache'][$g . '|' . $k] = $v; return true; }
function wp_cache_delete($k, $g = '') { unset($GLOBALS['cache'][$g . '|' . $k]); return true; }
function sanitize_text_field($s) { return is_scalar($s) ? trim(strip_tags((string) $s)) : ''; }
function sanitize_textarea_field($s) { return sanitize_text_field($s); }
function sanitize_email($s) { return is_scalar($s) ? trim((string) $s) : ''; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_html_class($s) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return $s; }
function esc_url_raw($s, $p = null) { return is_string($s) ? $s : ''; }
function esc_sql($s) { return $s; }
function esc_html__($s, $d = '') { return esc_html($s); }
function esc_html_e($s, $d = '') { echo esc_html($s); }
function esc_attr_e($s, $d = '') { echo esc_attr($s); }
function __($s, $d = '') { return $s; }
function _x($s, $c, $d = '') { return $s; }
function wp_kses_post($s) { return $s; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int) $v); }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); }
function home_url($p = '') { return 'https://shop.test' . $p; }
function rest_url($p = '') { return 'https://shop.test/wp-json/' . ltrim($p, '/'); }
function admin_url($p = '') { return 'https://shop.test/wp-admin/' . $p; }
function get_bloginfo($k) { return $k === 'name' ? 'Shop' : '6.6'; }
function wp_specialchars_decode($s, $q = 0) { return $s; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $subject, $body) { $GLOBALS['mails'][] = compact('to', 'subject', 'body'); return true; }
function current_time($type, $gmt = 0) { return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : ($type === 'timestamp' ? time() : date($type)); }
function status_header($c) {}
function wp_safe_redirect($url) { throw new RedirectException($url); }
function wc_add_notice($m, $t = 'notice') { $GLOBALS['notices'][] = [$t, $m]; }
function wc_get_checkout_url() { return 'https://shop.test/checkout/'; }
function wc_get_logger() { return new class { public function info($m, $c = []) { $GLOBALS['log'][] = ['info', $m]; } public function warning($m, $c = []) { $GLOBALS['log'][] = ['warning', $m]; } public function error($m, $c = []) { $GLOBALS['log'][] = ['error', $m]; } }; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_remote_post($url, $args = []) { $GLOBALS['http_log'][] = ['POST', $url, $args]; return ($GLOBALS['http'])($url, $args); }
function wp_remote_get($url, $args = []) { $GLOBALS['http_log'][] = ['GET', $url, $args]; return ($GLOBALS['http'])($url, $args); }
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header($r, $h) { return $r['headers'][$h] ?? ''; }
function wp_clear_scheduled_hook($h) {}
function wp_schedule_event($t, $i, $h) {}
function wp_next_scheduled($h, $a = []) { return false; }
function wp_schedule_single_event($t, $h, $a = []) { $GLOBALS['scheduled'][] = [$h, $a]; }
function wp_generate_uuid4() { return sprintf('%08x-%04x-4%03x-%04x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0x8000, 0xbfff), mt_rand()); }
function is_admin() { return false; }
function current_user_can($c) { return true; }
function get_current_user_id() { return 0; }
function wp_privacy_anonymize_ip($ip) { return '***'; }
function maybe_unserialize($v) { return is_string($v) && @unserialize($v) !== false ? unserialize($v) : $v; }
function wc_get_orders($args) { $out = []; foreach ($GLOBALS['orders'] as $o) { if (in_array($o->get_status(), (array) $args['status'], true) && $o->get_payment_method() === $args['payment_method']) $out[] = ($args['return'] ?? '') === 'ids' ? $o->get_id() : $o; } return $out; }
function wc_get_order($id) { return $GLOBALS['orders'][(int) $id] ?? false; }
function WC() { static $wc = null; if (!$wc) { $wc = new class { public $session; public $cart = null; public $version = '9.8.7'; public function __construct() { $this->session = new class { private $d = []; public function get($k) { return $this->d[$k] ?? null; } public function set($k, $v) { $this->d[$k] = $v; } public function get_customer_id() { return 'sess1'; } }; } public function is_store_api_request() { return false; } }; } return $wc; }

class WP_Error { private $code; private $msg; public function __construct($c, $m = '') { $this->code = $c; $this->msg = $m; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->msg; } }
class WP_REST_Request {
	private $method; private $route; private $headers = []; private $body = ''; private $query = [];
	public function __construct($method, $route = '/nft-checkout/v1/data') { $this->method = $method; $this->route = $route; }
	public function set_header($k, $v) { $this->headers[strtolower(str_replace('_', '-', $k))] = $v; }
	public function set_body($b) { $this->body = $b; }
	public function set_query_params($q) { $this->query = $q; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_header($k) { return $this->headers[strtolower(str_replace('_', '-', $k))] ?? null; }
	public function get_body() { return $this->body; }
	public function get_query_params() { return $this->query; }
	public function get_params() { return array_merge($this->query, json_decode($this->body, true) ?: []); }
	public function get_json_params() { return json_decode($this->body, true); }
}
class WP_REST_Response { public $data; public $status; public function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } public function get_status() { return $this->status; } public function get_data() { return $this->data; } }

class WC_Order {
	private $id; private $status = 'pending'; private $meta = []; private $notes = []; private $paid = false; private $txn = '';
	public $total = '90.00'; public $currency = 'USD'; public $key = 'wc_order_key_123'; public $method = 'nft-checkout'; public $email = 'shopper@example.test';
	public function __construct($id) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function has_status($s) { return in_array($this->status, (array) $s, true); }
	public function update_status($s, $note = '') { $this->status = $s; return true; }
	public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
	public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
	public function delete_meta_data($k) { unset($this->meta[$k]); }
	public function save() {}
	public function add_order_note($n) { $this->notes[] = $n; }
	public function get_notes() { return $this->notes; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_order_key() { return $this->key; }
	public function get_payment_method() { return $this->method; }
	public function get_billing_email() { return $this->email; }
	public function get_order_number() { return (string) $this->id; }
	public function get_customer_id() { return 0; }
	public function is_paid() { return $this->paid; }
	public function needs_payment() { return !$this->paid && in_array($this->status, ['pending', 'failed'], true); }
	public function payment_complete($txn = '') { $this->paid = true; $this->txn = $txn; $this->status = 'processing'; return true; }
	public function get_transaction_id() { return $this->txn; }
	public function get_checkout_order_received_url() { return 'https://shop.test/checkout/order-received/' . $this->id . '/?key=' . $this->key; }
	public function get_edit_order_url() { return 'https://shop.test/wp-admin/post.php?post=' . $this->id; }
}

class WPDB_Stub {
	public $prefix = 'wp_'; public $options = 'wp_options'; public $postmeta = 'wp_postmeta'; public $last_error = '';
	public $rows = [];
	public function prepare($q, ...$a) { $i = 0; return preg_replace_callback('/%[idsf]/', function ($m) use (&$i, $a) { $v = $a[$i++] ?? ''; return $m[0] === '%i' ? $v : (is_numeric($v) ? $v : "'" . $v . "'"); }, $q); }
	public function get_var($q) { if (stripos($q, 'SHOW TABLES') !== false) return 'wp_rt_nft_checkout_payment_link'; if (stripos($q, 'GET_LOCK') !== false) return '1'; return null; }
	public function get_row($q, $o = null) { if (preg_match('/order_id = (\d+)/', $q, $m)) { $rows = array_filter($this->rows, fn($r) => $r['order_id'] == $m[1]); if (preg_match("/uuid = '([^']*)'/", $q, $u)) $rows = array_filter($rows, fn($r) => $r['uuid'] === $u[1]); $rows = array_values($rows); return $rows ? end($rows) : null; } return null; }
	public function insert($t, $row, $f = null) { $row['id'] = count($this->rows) + 1; $this->rows[] = $row; return 1; }
	public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
	public function query($q) { return true; }
	public function esc_like($s) { return $s; }
	public function get_charset_collate() { return ''; }
	public function get_results($q, $o = null) { return []; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();
function dbDelta($sql) {}

require_once $GLOBALS['plugin_dir'] . '/includes/config.php';
require_once $GLOBALS['plugin_dir'] . '/includes/nft-checkout-payment-gateway-utils.php';
require_once $GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-logger.php';
require_once $GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-gateway-admin-notices.php';
require_once $GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-state-engine.php';
require_once $GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-gateway-rest-api.php';
require_once $GLOBALS['plugin_dir'] . '/includes/class-nft-checkout-payment-gateway-loader.php';
require_once $GLOBALS['plugin_dir'] . '/migration.php';
