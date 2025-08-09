<?php
/**
 * Plugin Name: W3TC Varnish CLI Helper
 * Description: Bridges W3 Total Cache’s Varnish purge to Varnish CLI (6082 + Control Key). Adds settings inside W3TC → General Settings → Reverse Proxy. Hooks W3TC purge events, intercepts HTTP PURGE, and handles dashboard flush buttons. Only active when W3TC’s Varnish is enabled.
 * Version: 1.4.0
 * Author: Byron Iniotakis
 */

if (!defined('ABSPATH')) exit;

class W3TC_Varnish_CLI_Helper {
    // Options (autoload = no)
    const OPT_ENABLED     = 'w3x_cli_enabled';
    const OPT_SERVERS     = 'w3x_cli_servers';      // "127.0.0.1:6082 10.0.0.5:6082"
    const OPT_KEY         = 'w3x_cli_key';          // control key string
    const OPT_TIMEOUT_S   = 'w3x_cli_timeout_s';    // int seconds
    const OPT_METHOD      = 'w3x_cli_method';       // 'PURGE' or 'BAN'
    const OPT_DEBUG       = 'w3x_cli_debug';        // bool

    public function __construct() {
        add_action('admin_init', [$this, 'maybe_set_defaults']);
        add_action('admin_init', [$this, 'save_from_w3tc_submit']);

        // Safe UI injection (after page DOM exists)
        add_action('admin_footer', [$this, 'inject_ui']);

        // AJAX Test
        add_action('wp_ajax_w3x_cli_test', [$this, 'handle_ajax_test']);

        // Wire runtime (events + interceptor + force HTTP + handle dashboard buttons)
        add_action('init', [$this, 'maybe_wire_runtime'], 9);
    }

    /** ---------- Helpers ---------- */

    private function on_w3tc_general_page(): bool {
        return is_admin() && isset($_GET['page']) && $_GET['page'] === 'w3tc_general';
    }

    private function is_w3tc_varnish_enabled(): bool {
        if (!class_exists('\W3TC\Dispatcher')) return false;
        try {
            $c = \W3TC\Dispatcher::config();
            return (bool)$c->get_boolean('varnish.enabled'); // W3TC checkbox
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function maybe_set_defaults() {
        $defaults = [
            self::OPT_ENABLED   => 1,
            self::OPT_SERVERS   => '127.0.0.1:6082',
            self::OPT_KEY       => '',
            self::OPT_TIMEOUT_S => 2,
            self::OPT_METHOD    => 'BAN', // default BAN: many varnish builds don't support CLI PURGE
            self::OPT_DEBUG     => 0,
        ];
        foreach ($defaults as $k => $v) {
            if (get_option($k, null) === null) {
                add_option($k, $v, '', 'no');
            }
        }
    }

    /** ---------- Save inside W3TC General form ---------- */
    public function save_from_w3tc_submit() {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (empty($_POST)) return;

        $has = isset($_POST[self::OPT_ENABLED]) || isset($_POST[self::OPT_SERVERS]) ||
               isset($_POST[self::OPT_KEY]) || isset($_POST[self::OPT_TIMEOUT_S]) ||
               isset($_POST[self::OPT_METHOD]) || isset($_POST[self::OPT_DEBUG]);
        if (!$has) return;

        $enabled = isset($_POST[self::OPT_ENABLED]) ? 1 : 0;
        $servers_raw = (string)($_POST[self::OPT_SERVERS] ?? '');
        $key     = (string)($_POST[self::OPT_KEY] ?? '');
        $timeout = (int)($_POST[self::OPT_TIMEOUT_S] ?? 2);
        $method  = strtoupper((string)($_POST[self::OPT_METHOD] ?? 'BAN'));
        $debug   = isset($_POST[self::OPT_DEBUG]) ? 1 : 0;

        // normalize servers: "host:port" tokens
        $parts = preg_split('/\s+/', trim(preg_replace('/[ \t]+/', ' ', $servers_raw)));
        $ok = [];
        foreach ($parts as $p) {
            if ($p !== '' && preg_match('~^([a-z0-9\.\-]+|\[[0-9a-f:\.]+\]):\d+$~i', $p)) $ok[] = $p;
        }
        $servers = implode(' ', array_unique($ok));

        update_option(self::OPT_ENABLED,   (int)$enabled, false);
        update_option(self::OPT_SERVERS,   $servers, false);
        update_option(self::OPT_KEY,       $key, false);
        update_option(self::OPT_TIMEOUT_S, max(1, $timeout), false);
        update_option(self::OPT_METHOD,    ($method === 'PURGE' ? 'PURGE' : 'BAN'), false);
        update_option(self::OPT_DEBUG,     (int)$debug, false);
    }

    /** ---------- Wire runtime (events + interceptor + force HTTP + dashboard buttons) ---------- */
    public function maybe_wire_runtime() {
        $helper_enabled = (bool)get_option(self::OPT_ENABLED, 1);
        $varnish_on = $this->is_w3tc_varnish_enabled();
        if (!$helper_enabled || !$varnish_on) return;

        // Force W3TC to use WP HTTP API (so pre_http_request can intercept)
        if (class_exists('\W3TC\Dispatcher')) {
            try {
                $c = \W3TC\Dispatcher::config();
                $home = home_url('/');
                $pu = wp_parse_url($home);
                if ($pu && !empty($pu['host'])) {
                    $scheme = $pu['scheme'] ?? 'http';
                    $port   = isset($pu['port']) ? (int)$pu['port'] : (($scheme === 'https') ? 443 : 80);
                    $hostport = $pu['host'] . ':' . $port;
                    // Runtime-only; does not change saved W3TC settings
                    $c->set('varnish.servers', [$hostport]);
                    $c->set('timelimit.varnish_purge', max(1, (int)get_option(self::OPT_TIMEOUT_S, 2)));
                }
            } catch (\Throwable $e) {}
        }

        // Hook W3TC purge events
        add_action('w3tc_flush_all', function() {
            $this->log_if_debug('EVENT: w3tc_flush_all received');
            $this->cli_flush_all_current_host();
        }, 1);

        add_action('w3tc_flush_url', function($url) {
            $this->log_if_debug('EVENT: w3tc_flush_url received: '.$url);
            if (!empty($url)) $this->cli_flush_url($url);
        }, 1, 1);

        // Intercept any HTTP PURGE (dashboard buttons, internal Varnish_Flush using HTTP API)
        add_filter('pre_http_request', function($pre, $r, $url) {
            if (!isset($r['method']) || strtoupper($r['method']) !== 'PURGE') return $pre;

            $servers = array_filter(array_map('trim', preg_split('/\s+/', (string)get_option(W3TC_Varnish_CLI_Helper::OPT_SERVERS, ''))));
            if (!$servers) return $pre;

            $key    = (string)get_option(W3TC_Varnish_CLI_Helper::OPT_KEY, '');
            $tmo_s  = (int)get_option(W3TC_Varnish_CLI_Helper::OPT_TIMEOUT_S, 2);
            $method = strtoupper((string)get_option(W3TC_Varnish_CLI_Helper::OPT_METHOD, 'BAN')) === 'PURGE' ? 'PURGE' : 'BAN';
            $debug  = (bool)get_option(W3TC_Varnish_CLI_Helper::OPT_DEBUG, 0);

            // Build expression for this URL
            $expr = (function($u){
                $p = @parse_url($u);
                $host = $p['host'] ?? '';
                $path = ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
                return 'req.http.host == "'.$host.'" && req.url ~ "^'.str_replace('"','\"',$path).'$"';
            })($url);

            $ok_all = true; $last_detail = '';
            foreach ($servers as $srv) {
                $res = $this->cli_command_for_expr($srv, $key, $tmo_s, $expr, $method, $debug);
                $ok_all = $ok_all && $res['ok'];
                $last_detail = $res['detail'] ?? '';
            }

            // Fake a normal HTTP response so W3TC is satisfied
            if ($ok_all) {
                return [
                    'response' => ['code'=>200, 'message'=>'OK'],
                    'body'     => 'CLI '.$method.' OK',
                    'headers'  => []
                ];
            } else {
                return [
                    'response' => ['code'=>503, 'message'=>'Service Unavailable'],
                    'body'     => 'CLI '.$method.' failed: '.$last_detail,
                    'headers'  => []
                ];
            }
        }, 10, 3);

        // Handle dashboard Flush Varnish / Flush Post buttons directly by query args
        $self = $this; // capture instance for closures
        add_action('admin_init', function () use ($self, $helper_enabled, $varnish_on) {
            if (!is_admin() || !current_user_can('manage_options')) return;
            if (!$helper_enabled || !$varnish_on) return;
            if (!isset($_GET['page']) || $_GET['page'] !== 'w3tc_dashboard') return;

            // Flush ALL via dashboard button (?w3tc_flush_varnish)
            if (isset($_GET['w3tc_flush_varnish'])) {
                $self->log_if_debug('DASHBOARD: w3tc_flush_varnish detected');
                $self->cli_flush_all_current_host();
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success"><p>Varnish CLI: Flush all sent.</p></div>';
                });
            }

            // Flush single post via dashboard button (?w3tc_flush_post=y&post_id=ID)
            if (isset($_GET['w3tc_flush_post']) && isset($_GET['post_id'])) {
                $post_id = intval($_GET['post_id']);
                if ($post_id > 0) {
                    $url = get_permalink($post_id);
                    if ($url) {
                        $self->log_if_debug('DASHBOARD: w3tc_flush_post detected for '.$url);
                        $self->cli_flush_url($url);
                        add_action('admin_notices', function () use ($url) {
                            echo '<div class="notice notice-success"><p>Varnish CLI: Flushed '.esc_html($url).'</p></div>';
                        });
                    }
                }
            }
        }, 1);
    }

    /** ---------- UI injection (safe, after DOM) ---------- */
    public function inject_ui() {
        if (!$this->on_w3tc_general_page()) return;

        try {
            $enabled = (bool)get_option(self::OPT_ENABLED, 1);
            $servers = (string)get_option(self::OPT_SERVERS, '127.0.0.1:6082');
            $key     = (string)get_option(self::OPT_KEY, '');
            $timeout = (int)get_option(self::OPT_TIMEOUT_S, 2);
            $method  = strtoupper((string)get_option(self::OPT_METHOD, 'BAN')) === 'PURGE' ? 'PURGE' : 'BAN';
            $debug   = (bool)get_option(self::OPT_DEBUG, 0);
            $nonceT  = wp_create_nonce('w3x_cli_test');

            $html  = '<div class="box" id="w3x-cli-panel" style="margin-top:16px">';
            $html .= '<h3>Varnish CLI</h3>';
            $html .= '<p>When W3 Total Cache purges, run a <strong>CLI '.$method.'</strong> via port <strong>6082</strong> using the Control Key.</p>';
            $html .= '<table class="form-table"><tbody>';

            $html .= '<tr><th><label for="w3x_cli_enabled">Enable</label></th><td>';
            $html .= '<label><input type="checkbox" id="w3x_cli_enabled" name="'.esc_attr(self::OPT_ENABLED).'" value="1" '.checked($enabled, true, false).'> Use CLI instead of HTTP when W3TC purges</label>';
            $html .= '<p class="description">Only applies if W3TC’s “Enable reverse proxy caching via varnish” is ON.</p>';
            $html .= '</td></tr>';

            $html .= '<tr><th><label for="w3x_cli_servers">CLI Servers</label></th><td>';
            $html .= '<input type="text" id="w3x_cli_servers" class="regular-text code" name="'.esc_attr(self::OPT_SERVERS).'" value="'.esc_attr($servers).'" placeholder="127.0.0.1:6082 10.0.0.5:6082" />';
            $html .= '<p class="description">Space-separated list of Varnish <strong>management</strong> endpoints (host:port).</p>';
            $html .= '</td></tr>';

            $html .= '<tr><th><label for="w3x_cli_key">Control Key</label></th><td>';
            $html .= '<input type="text" id="w3x_cli_key" class="regular-text" name="'.esc_attr(self::OPT_KEY).'" value="'.esc_attr($key).'" />';
            $html .= '</td></tr>';

            $html .= '<tr><th><label for="w3x_cli_method">Command Type</label></th><td>';
            $html .= '<select id="w3x_cli_method" name="'.esc_attr(self::OPT_METHOD).'">';
            $html .= '<option value="BAN" '.selected($method,'BAN',false).'>BAN</option>';
            $html .= '<option value="PURGE" '.selected($method,'PURGE',false).'>PURGE</option>';
            $html .= '</select>';
            $html .= '</td></tr>';

            $html .= '<tr><th><label for="w3x_cli_timeout_s">Timeout</label></th><td>';
            $html .= '<input type="number" id="w3x_cli_timeout_s" min="1" step="1" name="'.esc_attr(self::OPT_TIMEOUT_S).'" value="'.(int)$timeout.'" /> seconds';
            $html .= '</td></tr>';

            $html .= '<tr><th><label for="w3x_cli_debug">Debug purging</label></th><td>';
            $html .= '<label><input type="checkbox" id="w3x_cli_debug" name="'.esc_attr(self::OPT_DEBUG).'" value="1" '.checked($debug, true, false).'> Log CLI calls to uploads</label>';
            $html .= '<p class="description">Writes to <code>wp-content/uploads/w3tc-varnish-cli.log</code> when enabled.</p>';
            $html .= '</td></tr>';

            $html .= '</tbody></table>';
            $html .= '<hr /><h4>Test Connection</h4>';
            $html .= '<p>Runs a small <code>'.$method.'</code> for the home URL via CLI on the first configured server.</p>';
            $html .= '<p><button class="button" id="w3x-cli-test" data-nonce="'.esc_attr($nonceT).'">Run Test</button></p>';
            $html .= '<div id="w3x-cli-test-result"></div>';
            $html .= '</div>';

            ?>
<script>
(function(){
  try {
    var container = document.querySelector('#reverse_proxy .inside') || document.querySelector('#reverse_proxy') || document.querySelector('.wrap');
    if (!container) return;
    container.insertAdjacentHTML('beforeend', <?php echo json_encode($html); ?>);

    // Mirror CLI servers into W3TC textarea (strip ports; visual only)
    var cliInput = document.getElementById('w3x_cli_servers');
    var w3ta     = document.querySelector('textarea[name="varnish__servers"]');
    function mirrorServers(){
      if (!cliInput || !w3ta) return;
      var ips = cliInput.value.trim().split(/\s+/).map(function(s){
        return s.replace(/^\[?([0-9a-f\.:]+)\]?:\d+$/i, '$1');
      }).join("\n");
      w3ta.value = ips;
    }
    mirrorServers();
    if (cliInput) cliInput.addEventListener('input', mirrorServers);

    // AJAX Test
    var btn = document.getElementById('w3x-cli-test');
    var out = document.getElementById('w3x-cli-test-result');
    if (btn && out) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        out.innerHTML = '<div class="notice notice-info"><p>Testing...</p></div>';
        var data = new FormData();
        data.append('action','w3x_cli_test');
        data.append('_wpnonce', btn.getAttribute('data-nonce') || '');
        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
          .then(r=>r.json())
          .then(function(res){
            var cls = res.success ? 'notice-success' : 'notice-error';
            out.innerHTML = '<div class="notice '+cls+'"><p>'+res.message+'</p></div>';
            if (res.detail) {
              var pre = document.createElement('pre');
              pre.textContent = res.detail;
              out.appendChild(pre);
            }
          })
          .catch(function(err){
            out.innerHTML = '<div class="notice notice-error"><p>AJAX failed: '+(err && err.message ? err.message : 'Unknown error')+'</p></div>';
          });
      });
    }
  } catch(e) {}
})();
</script>
            <?php
        } catch (\Throwable $e) {}
    }

    /** ---------- Event helpers (public so closures can call safely) ---------- */

    public function cli_flush_all_current_host(): void {
        $home = home_url('/');
        $p = wp_parse_url($home);
        $host = $p['host'] ?? '';
        if (!$host) return;
        $expr = 'req.http.host == "'.$host.'" && req.url ~ ".*"';
        $this->cli_send_expr_to_all($expr, 'ALL');
    }

    public function cli_flush_url(string $url): void {
        $pu = @parse_url($url);
        if (!$pu || empty($pu['host'])) return;
        $host = $pu['host'];
        $path = ($pu['path'] ?? '/').(isset($pu['query']) ? '?'.$pu['query'] : '');
        $expr = 'req.http.host == "'.$host.'" && req.url ~ "^'.str_replace('"','\"',$path).'$"';
        $this->cli_send_expr_to_all($expr, $path);
    }

    public function cli_send_expr_to_all(string $expr, string $label=''): void {
        $servers = array_filter(array_map('trim', preg_split('/\s+/', (string)get_option(self::OPT_SERVERS, ''))));
        if (!$servers) return;

        $method = strtoupper((string)get_option(self::OPT_METHOD, 'BAN')) === 'PURGE' ? 'PURGE' : 'BAN';
        $key    = (string)get_option(self::OPT_KEY, '');
        $tmo_s  = (int)get_option(self::OPT_TIMEOUT_S, 2);
        $debug  = (bool)get_option(self::OPT_DEBUG, 0);

        foreach ($servers as $srv) {
            $res = $this->cli_command_for_expr($srv, $key, $tmo_s, $expr, $method, $debug);
            $this->log_if_debug("EVENT CLI {$method} @ {$srv} {$label} :: ".($res['ok']?'OK':'FAIL').($res['detail']?(' :: '.$res['detail']):''));
        }
    }

    /** ---------- AJAX Test ---------- */
    public function handle_ajax_test() {
        if (!current_user_can('manage_options')) wp_send_json(['success'=>false,'message'=>'Forbidden'], 403);
        check_ajax_referer('w3x_cli_test');

        $servers = array_filter(array_map('trim', preg_split('/\s+/', (string)get_option(self::OPT_SERVERS, ''))));
        if (!$servers) wp_send_json(['success'=>false,'message'=>'No CLI servers configured.']);

        $server = $servers[0];
        $method = strtoupper((string)get_option(self::OPT_METHOD, 'BAN')) === 'PURGE' ? 'PURGE' : 'BAN';
        $key    = (string)get_option(self::OPT_KEY, '');
        $tmo_s  = (int)get_option(self::OPT_TIMEOUT_S, 2);
        $debug  = (bool)get_option(self::OPT_DEBUG, 0);

        $home = home_url('/');
        $p = @parse_url($home);
        $host = $p['host'] ?? '';
        $expr = 'req.http.host == "'.$host.'" && req.url ~ "^/$"';

        $res = $this->cli_command_for_expr($server, $key, $tmo_s, $expr, $method, $debug);
        if ($res['ok']) {
            wp_send_json(['success'=>true,'message'=>"CLI {$method} OK on {$server}",'detail'=>$res['detail'] ?? '']);
        } else {
            wp_send_json(['success'=>false,'message'=>"CLI {$method} FAILED on {$server}",'detail'=>$res['detail'] ?? '']);
        }
    }

    /** ---------- CLI core (sockets + auth) ---------- */
    private function cli_command_for_expr(string $terminal, string $secret, int $timeout_s, string $expr, string $method, bool $debug): array {
        if (!extension_loaded('sockets')) {
            return ['ok'=>false, 'detail'=>'PHP sockets extension not loaded'];
        }
        $cmd  = ($method === 'PURGE' ? 'purge ' : 'ban ') . $expr;

        if (strpos($terminal, ':') === false) return ['ok'=>false, 'detail'=>'Invalid terminal (host:port expected)'];
        list($server, $port) = explode(':', $terminal, 2);
        $port = (int)$port;

        $sec = max(1, (int)$timeout_s);
        $client = @socket_create(AF_INET, SOCK_STREAM, getprotobyname('tcp'));
        if (!$client) return ['ok'=>false, 'detail'=>'socket_create failed'];

        @socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec'=>$sec, 'usec'=>0]);
        @socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec'=>$sec, 'usec'=>0]);

        if (@!socket_connect($client, $server, $port)) {
            $err = socket_strerror(socket_last_error($client));
            @socket_close($client);
            $this->maybe_log("[CONNECT FAIL] {$terminal} :: {$err}");
            return ['ok'=>false, 'detail'=>"connect {$server}:{$port} failed: {$err}"];
        }

        // Banner + optional auth
        $st = $this->cli_read($client);
        if (!$st['ok']) { @socket_close($client); $this->maybe_log("[BANNER ERROR] {$terminal} :: {$st['detail']}"); return ['ok'=>false, 'detail'=>$st['detail']]; }
        if ($st['code'] == 107) { // Auth required
            $challenge = substr($st['msg'], 0, 32);
            $pack = $challenge . "\x0A" . $secret . "\x0A" . $challenge . "\x0A";
            $key  = hash('sha256', $pack);
            @socket_write($client, "auth $key\n");
            $st2 = $this->cli_read($client);
            if (!$st2['ok'] || $st2['code'] != 200) { @socket_close($client); $this->maybe_log("[AUTH FAIL] {$terminal}"); return ['ok'=>false, 'detail'=>'Authentication failed']; }
        }

        // Send command
        @socket_write($client, $cmd . "\n");
        $st3 = $this->cli_read($client);
        @socket_close($client);

        $this->maybe_log("CMD: {$cmd} @ {$terminal} :: STATUS ".$st3['code']." :: ".$st3['msg']);

        // Fallback: if PURGE unsupported (status 101), retry once with BAN
        if ($method === 'PURGE' && $st3['ok'] && (int)$st3['code'] === 101) {
            return $this->cli_command_for_expr($terminal, $secret, $timeout_s, $expr, 'BAN', $debug);
        }

        if (!$st3['ok']) return ['ok'=>false, 'detail'=>$st3['detail']];
        if ((int)$st3['code'] !== 200) return ['ok'=>false, 'detail'=>"Status ".$st3['code'].": ".$st3['msg']];
        return ['ok'=>true, 'detail'=>trim($st3['msg']) ?: strtoupper($method).' OK'];
    }

    private function cli_read($client, int $retry=1): array {
        $hdr = @socket_read($client, 13, PHP_BINARY_READ);
        if ($hdr === false) {
            $err = socket_last_error($client);
            if ($err == 35 && $retry > 0) return $this->cli_read($client, $retry-1);
            return ['ok'=>false, 'detail'=>'Socket read error: '.socket_strerror($err), 'code'=>$err, 'msg'=>''];
        }
        $len = (int)substr($hdr, 4, 6) + 1;
        $msg = @socket_read($client, $len, PHP_BINARY_READ);
        $code = (int)substr($hdr, 0, 3);
        return ['ok'=>true, 'detail'=>'', 'code'=>$code, 'msg'=>$msg ?? ''];
    }

    private function maybe_log(string $line): void {
        if (!get_option(self::OPT_DEBUG, 0)) return;
        $up = wp_upload_dir();
        if (empty($up['basedir'])) return;
        $file = trailingslashit($up['basedir']) . 'w3tc-varnish-cli.log';
        $entry = '['.gmdate('Y-m-d H:i:s')."] ".$line."\n";
        if (!file_exists($file)) {
            @file_put_contents($file, $entry, FILE_APPEND);
            @chmod($file, 0644);
        } else {
            @file_put_contents($file, $entry, FILE_APPEND);
        }
    }
    public function log_if_debug(string $line): void { $this->maybe_log($line); }
}

new W3TC_Varnish_CLI_Helper();
