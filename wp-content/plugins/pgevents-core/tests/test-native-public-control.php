<?php
/** Run: php tests/test-native-public-control.php
 * Isolated child processes, persistent temporary option store, real WP header
 * sender. No application bootstrap, application DB, HTTP, mail or remote I/O.
 * The option adapter is not a substitute for a WordPress/MySQL integration test.
 */
if (($argv[1] ?? '') === 'worker') {
    $store = $argv[2];
    $case = json_decode($argv[3], true);
    define('ABSPATH', dirname(__DIR__, 4) . '/');
    if (array_key_exists('constant', $case)) define('PGE_NATIVE_HOME_PUBLIC', $case['constant']);
    if (!empty($case['rest'])) define('REST_REQUEST', true);
    function get_option($key, $default = false) {
        global $store;
        $data = json_decode(file_get_contents($store), true);
        return $data[$key]['value'] ?? $default;
    }
    function update_option($key, $value, $autoload) {
        global $store, $case;
        if (!empty($case['write_failure'])) return false;
        $data = json_decode(file_get_contents($store), true);
        $data[$key] = ['value' => $value, 'autoload' => $autoload];
        file_put_contents($store, json_encode($data));
        return true;
    }
    function add_action(...$args) {}
    function add_filter(...$args) {}
    function apply_filters($name, $value, ...$args) { return $name === 'wp_headers' ? pge_homepage_transition_headers($value) : $value; }
    function do_action_ref_array(...$args) {}
    function is_admin() { global $case; return !empty($case['admin']); }
    function is_front_page() { global $case; return !isset($case['front']) || $case['front']; }
    function is_feed() { global $case; return !empty($case['feed']); }
    function is_404() { return false; }
    function wp_doing_ajax() { global $case; return !empty($case['ajax']); }
    function wp_get_environment_type() { global $case; return $case['environment'] ?? 'production'; }
    function current_user_can($cap) { global $case; return ($case['authorized'] ?? true) && $cap === 'manage_options'; }
    function is_user_logged_in() { global $case; return !empty($case['logged']); }
    function sanitize_text_field($s) { return trim($s); }
    function wp_unslash($s) { return $s; }
    function wp_die($m, $t = '', $args = []) { throw new RuntimeException('denied:' . $args['response']); }
    function check_admin_referer($action) {
        global $case;
        if ($action !== 'pge_save_native_public' || !($case['nonce'] ?? true)) throw new RuntimeException('nonce');
    }
    function admin_url($p) { return $p; }
    function wp_safe_redirect($url) { throw new RuntimeException('saved'); }
    function is_singular() { return false; }
    function status_header($code) { $GLOBALS['status'] = $code; }
    $theme = dirname(__DIR__, 3) . '/themes/pgevents-pro/';
    require $theme . 'inc/native-home-preview-admin.php';
    $source = file_get_contents($theme . 'functions.php');
    $start = strpos($source, "if (!function_exists('pge_is_native_home_preview'))");
    $end = strpos($source, '// The query is resolved', $start);
    eval(substr($source, $start, $end - $start));
    $_SERVER['REQUEST_METHOD'] = $case['method'] ?? 'GET';
    $_GET = !empty($case['preview']) ? ['pge_native_home_preview' => '1'] : [];
    $_POST = $case['post'] ?? [];
    if (!empty($case['conditional'])) {
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"old-home"';
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = 'Wed, 11 Nov 2037 05:00:00 GMT';
    }
    $outcome = 'read';
    if (!empty($case['save'])) {
        try { pge_save_native_public(); } catch (RuntimeException $e) { $outcome = $e->getMessage(); }
    }
    $initial = ['ETag' => '"old-home"', 'Last-Modified' => 'old'];
    $headers = pge_homepage_transition_headers($initial);
    // Exercise the actual WP HTML send_headers branch with conditional inputs.
    // Logged-in and feeds use separate core dependencies; policy is tested above.
    $status = 200;
    if (!is_user_logged_in() && !is_feed()) {
        require ABSPATH . 'wp-includes/class-wp.php';
        $wp = new WP();
        $wp->query_vars = [];
        $wp->send_headers();
    }
    echo json_encode(['outcome' => $outcome, 'native' => pge_should_render_native_home(),
        'public' => pge_native_home_public_enabled(), 'headers' => $headers,
        'status' => $status, 'store' => json_decode(file_get_contents($store), true)]);
    exit;
}
$store = tempnam(sys_get_temp_dir(), 'pge-public-');
file_put_contents($store, '{}');
$count = 0;
function request_case($case) {
    global $store;
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $store, json_encode($case)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit || $err || !is_array($result = json_decode($out, true))) throw new RuntimeException($err . $out);
    return $result;
}
function check($condition, $label) {
    global $count;
    if (!$condition) throw new RuntimeException($label);
    $count++;
}
try {
    $r = request_case([]);
    check(!$r['native'] && !$r['store'], 'default OFF without option creation');
    foreach (['1', '0', '1', '0'] as $value) {
        $r = request_case(['save' => true, 'method' => 'POST', 'post' => ['enabled' => $value]]);
        check($r['outcome'] === 'saved' && $r['store']['pge_native_home_public'] === ['value' => $value, 'autoload' => false], 'persistent save/autoload');
        $r = request_case(['conditional' => true]);
        check($r['native'] === ($value === '1') && $r['status'] === 200, 'independent anonymous request / conditional 200');
        check($r['headers']['Cache-Control'] === 'private, no-store, no-cache, must-revalidate'
            && $r['headers']['Expires'] === 'Wed, 11 Jan 1984 05:00:00 GMT'
            && $r['headers']['Pragma'] === 'no-cache' && !isset($r['headers']['ETag'])
            && !isset($r['headers']['X-Robots-Tag']), 'headers both branches');
    }
    foreach ([true, false, '1', 1, 'true', null] as $constant) {
        foreach (['0', '1'] as $option) {
            file_put_contents($store, json_encode(['pge_native_home_public' => ['value' => $option]]));
            $r = request_case(['constant' => $constant, 'save' => true, 'method' => 'POST', 'post' => ['enabled' => '1']]);
            check($r['public'] === ($constant === true) && $r['outcome'] === 'denied:403'
                && $r['store']['pge_native_home_public']['value'] === $option, 'constant precedence and lock');
        }
    }
    file_put_contents($store, '{}');
    foreach ([true, 'yes', [], 2] as $invalid) {
        file_put_contents($store, json_encode(['pge_native_home_public' => ['value' => $invalid]]));
        check(!request_case([])['public'], 'invalid stored option fails closed');
    }
    file_put_contents($store, '{}');
    foreach ([['method' => 'GET'], ['authorized' => false], ['nonce' => false],
        ['post' => ['enabled' => []]], ['post' => ['enabled' => 'yes']], ['post' => ['enabled' => null]]] as $bad) {
        $r = request_case(array_merge(['save' => true, 'method' => 'POST'], $bad));
        check($r['outcome'] !== 'saved' && !$r['store'], 'rejected save has no writes');
    }
    $r = request_case(['save' => true, 'method' => 'POST', 'post' => ['enabled' => '1'], 'write_failure' => true]);
    check($r['outcome'] === 'denied:500', 'failed persistence detected');
    $r = request_case(['save' => true, 'method' => 'POST']);
    check($r['outcome'] === 'saved' && $r['store']['pge_native_home_public']['value'] === '0', 'unchecked checkbox saves OFF');
    $r = request_case(['save' => true, 'method' => 'POST', 'write_failure' => true]);
    check($r['outcome'] === 'saved', 'unchanged OFF verifies successfully');
    foreach ([['front' => false], ['admin' => true], ['ajax' => true], ['rest' => true], ['feed' => true], ['method' => 'POST']] as $excluded) {
        $r = request_case($excluded);
        check($r['headers'] === ['ETag' => '"old-home"', 'Last-Modified' => 'old'], 'cache policy exclusions');
    }
    $r = request_case(['method' => 'HEAD', 'conditional' => true]);
    check(isset($r['headers']['Pragma']) && $r['status'] === 200, 'HEAD conditional');
    foreach (['local', 'development'] as $env) {
        $r = request_case(['environment' => $env, 'preview' => true, 'logged' => true]);
        check($r['native'] && !$r['public'], 'local preview independent');
    }
    file_put_contents($store, json_encode(['pge_native_home_production_preview' => ['value' => '1']]));
    $r = request_case(['preview' => true, 'logged' => true]);
    check($r['native'] && !$r['public'], 'production preview independent');
    $r = request_case(['preview' => true, 'authorized' => false]);
    check(!$r['native'], 'anonymous preview denied');
    echo "PASS $count checks; independent processes and persistent test store; no application DB\n";
} finally { unlink($store); }
