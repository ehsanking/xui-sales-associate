<?php
/**
 * Plugin Name: X-UI Sales Associate
 * Description: WooCommerce ↔ 3x-ui: Create/Renew/Manage VPN accounts + product fields + subscription link in My Account
 * Version: 1.0.0
 * Author: EHSANKiNG
 * Author URI: https://t.me/VPN_SalesAssociate/
 * Requires PHP: 7.4
 * Requires at least: 6.6
 * Requires Plugins: woocommerce
 * Text Domain: xui-sa
 */

if (!defined('ABSPATH')) exit;



define('XUI_SA_MENU_SLUG',     'xui_sa');
define('XUI_SA_DONATE_SLUG',   'xui_sa_donate');
define('XUI_SA_OPTION_KEY',    'xui_sa_options');
define('XUI_SA_VER',           '1.0.0');

/**
 * Check WooCommerce dependency
 */
add_action('admin_init', function(){
    if (is_admin() && current_user_can('activate_plugins') && !class_exists('WooCommerce')){
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><strong>X-UI Sales Associate</strong> needs WooCommerce to be installed & activated.</p></div>';
        });
    }
});

/**
 * Activation: flush rewrite rules (for My Account endpoint)
 */
register_activation_hook(__FILE__, function(){
    add_rewrite_endpoint('xui-subscriptions', EP_ROOT|EP_PAGES);
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(); });

/**
 * Utilities
 */
function xui_sa_uuidv4(){
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
function xui_sa_now_ms(){ return round(microtime(true) * 1000); }
function xui_sa_gb_to_bytes($gb){ return max(0, (int)$gb) * 1024 * 1024 * 1024; }

/**
 * Register setting (single array)
 */
add_action('admin_init', function(){
    register_setting('xui_sa_settings_group', XUI_SA_OPTION_KEY, [
        'type' => 'array',
        'sanitize_callback' => function($opts){
            $defaults = [
                'proxy_url'         => '',
                'shared_secret'     => '',
                'allowed_origins'   => '',
                'panel_sub_pattern' => '',
                'default_inbound'   => '1',
                'limit_ip_default'  => '0',
                'server_host'       => '',
                'server_port'       => '',
                'server_transport'  => 'ws',
                'server_tls'        => '1',
                'server_ws_path'    => '/',
                'server_sni'        => '',
                'support_email'     => '',
                'reveal_when'      => 'completed',
            ];
            $clean = array_merge($defaults, is_array($opts) ? $opts : []);
            $clean['proxy_url']         = esc_url_raw($clean['proxy_url']);
            $clean['shared_secret']     = sanitize_text_field($clean['shared_secret']);
            $clean['allowed_origins']   = sanitize_text_field($clean['allowed_origins']);
            $clean['panel_sub_pattern'] = sanitize_text_field($clean['panel_sub_pattern']);
            $clean['default_inbound']   = preg_replace('/\D+/', '', $clean['default_inbound']);
            $clean['limit_ip_default']  = preg_replace('/\D+/', '', $clean['limit_ip_default']);
            $clean['server_host']       = sanitize_text_field($clean['server_host']);
            $clean['server_port']       = preg_replace('/\D+/', '', $clean['server_port']);
            $clean['server_transport']  = in_array($clean['server_transport'], ['ws','grpc']) ? $clean['server_transport'] : 'ws';
            $clean['server_tls']        = $clean['server_tls'] === '0' ? '0' : '1';
            $clean['server_ws_path']    = sanitize_text_field($clean['server_ws_path']);
            $clean['server_sni']        = sanitize_text_field($clean['server_sni']);
            $clean['support_email']     = sanitize_email($clean['support_email']);
            return $clean;
        }
    ]);
});

/**
 * Admin menu
 */
add_action('admin_menu', function(){
    $icon_url = plugin_dir_url(__FILE__).'assets/img/xui-shield.png';
    add_menu_page('X-UI Sales Associate','XUI-SA','manage_options',XUI_SA_MENU_SLUG,'xui_sa_render_settings_page',$icon_url,56);
    add_submenu_page(XUI_SA_MENU_SLUG, __('Settings','xui-sa'), __('Settings','xui-sa'), 'manage_options', XUI_SA_MENU_SLUG, 'xui_sa_render_settings_page');
    add_submenu_page(XUI_SA_MENU_SLUG, __('Donate','xui-sa'),   __('Donate','xui-sa'),   'manage_options', XUI_SA_DONATE_SLUG, 'xui_sa_render_donate_page');
});

/**
 * Enqueue assets
 */
add_action('admin_head', function(){ echo '<style>#toplevel_page_xui_sa .wp-menu-image img{width:20px;height:20px;object-fit:contain}</style>'; });
add_action('admin_enqueue_scripts', function($hook){
    $is_our_page = (strpos($hook, 'xui_sa') !== false);
    $is_product_editor = in_array($hook, ['post.php','post-new.php']) && isset($_GET['post_type']) && $_GET['post_type'] === 'product';
    if (!$is_our_page && !$is_product_editor) return;

    wp_enqueue_style('xui-sa-bootstrap','https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',[], '5.3.3');
    wp_enqueue_style('xui-sa-bs-icons','https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',[],'1.11.3');
    wp_enqueue_script('xui-sa-bootstrap','https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',[],'5.3.3',true);
    wp_enqueue_script('xui-sa-swal','https://cdn.jsdelivr.net/npm/sweetalert2@11',[],'11',true);

    wp_enqueue_script('xui-sa-admin', plugin_dir_url(__FILE__).'assets/js/admin-settings.js',['jquery','xui-sa-swal','xui-sa-bootstrap'], XUI_SA_VER, true);
    wp_localize_script('xui-sa-admin','XUI_SA',['ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('xui_sa_nonce')]);

    $css = '
    body.wp-admin .xui-sa-wrap{direction:ltr;text-align:left;}.xui-sa-wrap{max-width:100% !important;}
    .xui-sa-wrap .card{border-radius:1rem;box-shadow:0 6px 16px rgba(0,0,0,.06);}
    /* Full-width admin layout */
    body.wp-admin .xui-sa-wrap{max-width:none!important;width:100%!important;padding-left:0;padding-right:24px;}
    body.wp-admin .xui-sa-wrap .container-fluid{max-width:none!important;padding-left:0;padding-right:0;}
    body.wp-admin .xui-sa-wrap .card{width:100%;margin-left:0;margin-right:0;}

    .xui-sa-wrap .form-text{color:#6c757d;}

    /* XUI-SA Brand Color */
    :root{--xui-sa-primary:#4DAA57;}
    .xui-sa-wrap .btn-primary,
    .xui-sa-wrap .button.button-primary{background-color:var(--xui-sa-primary)!important;border-color:var(--xui-sa-primary)!important;}
    .xui-sa-wrap .btn-primary:hover,
    .xui-sa-wrap .button.button-primary:hover{filter:brightness(0.95);}
    .xui-sa-wrap .nav-pills .nav-link.active,
    .xui-sa-wrap .nav-pills .show>.nav-link{background-color:var(--xui-sa-primary)!important;}
    .xui-sa-wrap .badge.xui-sa-badge{background-color:var(--xui-sa-primary)!important;}
    .xui-sa-wrap a{color:var(--xui-sa-primary);}
    .xui-sa-wrap a:hover{text-decoration:underline;}

    
    /* --- XUI-SA: admin layout (v2) --- */
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap{max-width:none!important;width:100%!important;margin:0!important;padding-left:0!important;padding-right:0!important;}
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap .container-fluid{max-width:none!important;padding-left:0!important;padding-right:0!important;}
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap .card{width:100%!important;margin-left:0!important;margin-right:0!important;}
    /* Let inputs be truly stretched */
    .xui-sa-wrap .card .card-body .col-12 .form-control{width:100%!important;}

    /* --- XUI-SA: WP Admin (v3) --- */
    body.wp-admin #wpcontent .wrap{max-width:none!important;width:100%!important;}
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap{max-width:none!important;width:100%!important;padding-left:0!important;padding-right:0!important;}
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap .container-fluid{max-width:none!important;padding-left:0!important;padding-right:0!important;}
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap .card{width:100%!important;margin-left:0!important;margin-right:0!important;}
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap .card .card-header,
    body.wp-admin #wpbody-content .wrap.xui-sa-wrap .card .card-body{padding-left:1.25rem;padding-right:1.25rem;}
    /* Inputs stretch */
    .xui-sa-wrap .form-control, .xui-sa-wrap .input-group{width:100%!important;}

    .xui-sa-wrap .nav-pills .nav-link.active{background:#0d6efd;}
    .xui-sa-badge{font-weight:600;}
    .xui-sa-kbd{background:#f8f9fa;border:1px solid #e9ecef;border-radius:.375rem;padding:.15rem .4rem;font-size:.85em;}
    .xui-sa-wallet{font-family:monospace;word-break:break-all;}
    .xui-sa-product-panel .form-field{padding:8px 12px;}
    .xui-sa-product-panel .wide{max-width:1000px;} .xui-sa-wrap .form-control{max-width:100%;}
    .xui-sa-header-logo{height:28px;width:28px;vertical-align:middle;margin-left:.35rem;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.1);}
    ';
    wp_add_inline_style('xui-sa-bootstrap',$css);
});

/**
 * AJAX: Ping worker
 */
add_action('wp_ajax_xui_sa_ping', function(){
    check_ajax_referer('xui_sa_nonce','nonce');
    $opts = get_option(XUI_SA_OPTION_KEY, []);
    $proxy = isset($opts['proxy_url']) ? esc_url_raw($opts['proxy_url']) : '';
    $secret= isset($opts['shared_secret']) ? $opts['shared_secret'] : '';
    if (empty($proxy) || empty($secret)) wp_send_json_error(['message'=>'Proxy URL or Shared Secret is not set.'],400);
    $res = wp_remote_post($proxy,[
        'timeout'=>15,
        'headers'=>['Content-Type'=>'application/json','X-Alsxui-Action'=>'ping','X-Alsxui-Secret'=>$secret],
        'body'=>wp_json_encode(new stdClass()),
        'sslverify'=>true,
    ]);
    if (is_wp_error($res)) wp_send_json_error(['message'=>$res->get_error_message()],502);
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $ok = ($code>=200 && $code<500);
    wp_send_json(['ok'=>$ok,'status'=>$code,'body'=>$body]);
});

/**
 * Settings
 */
function xui_sa_render_settings_page(){
    if (!current_user_can('manage_options')) return;
    $opts = get_option(XUI_SA_OPTION_KEY,[
        'proxy_url'=>'','shared_secret'=>'','allowed_origins'=>'','panel_sub_pattern'=>'',
        'default_inbound'=>'1','limit_ip_default'=>'0',
        'server_host'=>'','server_port'=>'','server_transport'=>'ws','server_tls'=>'1','server_ws_path'=>'/','server_sni'=>'','support_email'=>'','reveal_when'=>'completed'
    ]);
    $icon_url = plugin_dir_url(__FILE__).'assets/img/xui-shield.png';
    ?>
    <div class="wrap xui-sa-wrap container-fluid" dir="ltr">
      <h1 class="mb-3"><img class="xui-sa-header-logo" src="<?php echo esc_url($icon_url); ?>" alt=""> X-UI Sales Associate — Settings</h1>
      <ul class="nav nav-pills gap-2 mb-4" id="xui-sa-tabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-connection"><i class="bi bi-plug"></i> Worker & API</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-server"><i class="bi bi-hdd-network"></i> Server Settings</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-products"><i class="bi bi-box-seam"></i> Plans / Defaults</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-diagnostics"><i class="bi bi-activity"></i> Diagnostics</button></li>
      </ul>
      <form method="post" action="options.php" class="needs-validation" novalidate><?php settings_fields('xui_sa_settings_group'); ?>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="pane-connection">
            <div class="card mb-4"><div class="card-header d-flex justify-content-between">
              <strong><i class="bi bi-cloud"></i> Cloudflare Worker & API</strong><span class="badge text-bg-secondary xui-sa-badge">Mandatory</span></div>
              <div class="card-body row g-3">
                <div class="col-12">
                  <label class="form-label">Worker Proxy URL' . ('Cloudflare Worker endpoint URL used as the proxy.') '</label>
                  <input type="url" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[proxy_url]" value="<?php echo esc_attr($opts['proxy_url']); ?>" placeholder="https://x-ui-xxx.workers.dev" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Shared Secret' . ('Shared token between plugin and worker for authentication.') '</label>
                  <input type="password" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[shared_secret]" value="<?php echo esc_attr($opts['shared_secret']); ?>" placeholder="********" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Allowed Origins (Optional)</label>
                  <input type="text" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[allowed_origins]" value="<?php echo esc_attr($opts['allowed_origins']); ?>" placeholder="https://yoursite.com, https://admin.yoursite.com"
                   aria-describedby="xui-help-orig"
                >
                <div id="xui-help-orig" class="form-text">Domains allowed to call the worker. Leave empty for any.</div>
                </div>
                <div class="col-12">
                  <button type="button" id="xui-sa-ping" class="btn btn-primary"><i class="bi bi-broadcast-pin"></i> Test Connection (Ping)</button>
                  <span id="xui-sa-ping-result" class="ms-2 align-middle"></span>
                </div>
              </div>
            </div>
          </div>
          <div class="tab-pane fade" id="pane-server">
            <div class="card mb-4"><div class="card-header"><strong><i class="bi bi-gear"></i> Server/Transport(soon)</strong></div>
              <div class="card-body row g-3">
                <div class="col-12 col-12"><label class="form-label">Host</label><input type="text" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[server_host]" value="<?php echo esc_attr($opts['server_host']); ?>" placeholder="vpn.example.com"></div>
                <div class="col-12 col-12"><label class="form-label">Port</label><input type="number" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[server_port]" value="<?php echo esc_attr($opts['server_port']); ?>" placeholder="443"></div>
                <div class="col-12 col-12"><label class="form-label">Transport</label><select class="form-select" name="<?php echo XUI_SA_OPTION_KEY; ?>[server_transport]"><option value="ws" <?php selected($opts['server_transport'],'ws'); ?>>WebSocket</option><option value="grpc" <?php selected($opts['server_transport'],'grpc'); ?>>gRPC</option></select></div>
                <div class="col-12 col-12"><label class="form-label">TLS</label><select class="form-select" name="<?php echo XUI_SA_OPTION_KEY; ?>[server_tls]"><option value="1" <?php selected($opts['server_tls'],'1'); ?>>Enabled</option><option value="0" <?php selected($opts['server_tls'],'0'); ?>>Disabled</option></select></div>
                <div class="col-12"><label class="form-label">WS Path</label><input type="text" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[server_ws_path]" value="<?php echo esc_attr($opts['server_ws_path']); ?>" placeholder="/"></div>
                <div class="col-12"><label class="form-label">SNI</label><input type="text" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[server_sni]" value="<?php echo esc_attr($opts['server_sni']); ?>" placeholder=""></div>
              </div>
            </div>
          </div>
          <div class="tab-pane fade" id="pane-products">
            <div class="card mb-4"><div class="card-header"><strong><i class="bi bi-box-seam"></i> Defaults</strong></div>
              <div class="card-body row g-3">
                <div class="col-12 col-12"><label class="form-label">Default Inbound ID</label><input type="number" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[default_inbound]" value="<?php echo esc_attr($opts['default_inbound']); ?>" placeholder="1"></div>
                <div class="col-12 col-12"><label class="form-label">Default IP Limit</label><input type="number" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[limit_ip_default]" value="<?php echo esc_attr($opts['limit_ip_default']); ?>" placeholder="0"></div>
                <div class="col-12 col-12"><label class="form-label">Panel Subscription Pattern</label><input type="text" class="form-control" name="<?php echo XUI_SA_OPTION_KEY; ?>[panel_sub_pattern]" value="<?php echo esc_attr($opts['panel_sub_pattern']); ?>" placeholder="https://panel.example.com/sub/{subId}"></div>
                
<div class="col-12 col-12">
  <label class="form-label">Reveal Configs When</label>
  <select class="form-select" name="<?php echo XUI_SA_OPTION_KEY; ?>[reveal_when]">
    <option value="processing" <?php selected(isset($opts['reveal_when'])?$opts['reveal_when']:'completed','processing'); ?>>When order is Processing</option>
    <option value="completed"  <?php selected(isset($opts['reveal_when'])?$opts['reveal_when']:'completed','completed');  ?>>When order is Completed</option>
  </select>
  <div class="form-text">Choose when customer can see subscription/config links in My Account.</div>
</div>
<div class="col-12 col-12"><label class="form-label">Support Channel</label>
<input type="text" readonly class="form-control-plaintext" value="https://t.me/VPN_SalesAssociate"></div>
              </div>
            </div>
          </div>
          <div class="tab-pane fade" id="pane-diagnostics">
            <!-- 3x-ui info card inserted -->
            <div class="card mb-4">
              <div class="card-header"><strong><i class="bi bi-diagram-3"></i> 3x-ui</strong></div>
              <div class="card-body">
                <p class="mb-2">Xray panel supporting multi-protocol multi-user expire day & traffic & IP limit (Vmess, Vless, Trojan, ShadowSocks, Wireguard, Tunnel, Mixed, HTTP).</p>
                <p class="mb-2">This plugin has been tested with the 3x-ui project.</p>
                <p class="mb-0"><a href="https://github.com/MHSanaei/3x-ui" target="_blank" rel="noopener">GitHub: MHSanaei/3x-ui</a></p>
              </div>
            </div>

            <div class="card mb-4"><div class="card-header"><strong><i class="bi bi-activity"></i> Diagnostics</strong></div>
              <div class="card-body"><p class="mb-3">To verify connectivity, click the button below.</p>
                <button type="button" id="xui-sa-ping-2" class="btn btn-outline-primary"><i class="bi bi-broadcast-pin"></i> Test Connection (Ping)</button>
                <span id="xui-sa-ping-result-2" class="ms-2 align-middle"></span>
              <div class="form-text"><a href="https://t.me/VPN_SalesAssociate" target="_blank" rel="noopener nofollow">Join our Telegram support channel</a></div></div>
            </div>
          </div>
        </div>
        <?php submit_button('Save Settings'); ?>
      </form>
    </div>
    <?php
}

/**
 * Donate
 */

function xui_sa_render_donate_page(){
    if (!current_user_can('manage_options')) return;
    $wallet = 'TKPswLQqd2e73UTGJ5prxVXBVo7MTsWedU';
    $icon_url = plugin_dir_url(__FILE__).'assets/img/xui-shield.png';

    // OKing
    $t1 = base64_decode('WC1VSSBTYWxlcyBBc3NvY2lhdGUg4oCUIERvbmF0ZSDinaTvuI8=');
    $t2 = base64_decode('U3VwcG9ydCB0aGlzIHByb2plY3Q=');
    $t3 = base64_decode('VGhpcyBwbHVnaW4gaXMgY3JhZnRlZCB3aXRoIGxvdmUgYW5kIGNvdW50bGVzcyBob3VycyBvZiB0ZXN0aW5nIHNvIHlvdSBjYW4gc2hpcCBWUE4gc2VydmljZXMgZnJvbSBXb29Db21tZXJjZSB3aXRoIGNvbmZpZGVuY2UuIFlvdXIgY29udHJpYnV0aW9uIGZ1ZWxzIG1vcmUgZmVhdHVyZXMsIGJldHRlciBVWCwgYW5kIGZhc3RlciBmaXhlcy4=');
    $t4 = base64_decode('VVNEVCAoVFJDMjAp');
    $t5 = base64_decode('Q29weQ==');
    $t6 = base64_decode('VGhhbmsgeW91IOKAlCB5b3VyIHN1cHBvcnQga2VlcHMgdGhpcyBwcm9qZWN0IGFsaXZlIGFuZCBncm93aW5nLiDwn5KZ');
    $t7 = base64_decode('T2ZmaWNpYWwgVGVsZWdyYW0gQ2hhbm5lbA==');
    $t8 = base64_decode('YnkgRWhzYW4gS2luZw==');
    $t9 = base64_decode('T3BlbiBDaGFubmVs');

    ?>
    <div class="wrap xui-sa-wrap container-fluid" dir="ltr">
      <h1 class="mb-3"><img class="xui-sa-header-logo" src="<?php echo esc_url($icon_url); ?>" alt=""> <?php echo $t1; ?></h1>

      <div class="card mb-4">
        <div class="card-header"><strong><i class="bi bi-heart"></i> <?php echo $t2; ?></strong></div>
        <div class="card-body">
          <p class="fs-5"><?php echo $t3; ?></p>
          <hr>
          <div class="row g-3 align-items-center">
            <div class="col-12"><span class="badge text-bg-dark"><?php echo $t4; ?></span></div>
            <div class="col-12"><div id="xui-sa-wallet" class="form-control bg-light"><?php echo esc_html($wallet); ?></div></div>
            <div class="col-12"><button type="button" id="xui-sa-copy-wallet" class="button button-primary w-100"><i class="bi bi-clipboard-check"></i> <?php echo $t5; ?></button></div>
          </div>

          <div class="mt-4 p-3 border rounded-3 bg-light d-flex flex-wrap align-items-center justify-content-between">
            <div class="mb-2 mb-sm-0">
              <strong><?php echo $t7; ?></strong>
              <span class="text-muted ms-2">— <?php echo $t8; ?></span>
            </div>
            <a class="button button-secondary" target="_blank" rel="noopener nofollow" href="https://t.me/VPN_SalesAssociate">
              <i class="bi bi-telegram"></i> <?php echo $t9; ?>
            </a>
          </div>

          <div class="mt-3 text-muted"><?php echo $t6; ?></div>
        </div>
      </div>
    </div>
    <?php
}

/**
 * PRODUCT META
 */
add_filter('woocommerce_product_data_tabs', function($tabs){
    $tabs['xui_sa'] = ['label'=>__('XUI-SA','xui-sa'),'target'=>'xui_sa_product_data','class'=>['show_if_simple','show_if_subscription']];
    return $tabs;
}, 99);
add_action('woocommerce_product_data_panels', function(){
    global $post;
    if (!$post) return;
    $gb = get_post_meta($post->ID,'_xui_sa_gb',true);
    $days = get_post_meta($post->ID,'_xui_sa_days',true);
    $inbound = get_post_meta($post->ID,'_xui_sa_inbound',true);
    $ovr = get_post_meta($post->ID,'_xui_sa_worker_override',true);
    $ovr_url = get_post_meta($post->ID,'_xui_sa_worker_url',true);
    $ovr_secret = get_post_meta($post->ID,'_xui_sa_worker_secret',true);
    ?>
    <div id="xui_sa_product_data" class="panel woocommerce_options_panel xui-sa-product-panel">
      <div class="options_group">
        <p class="form-field">
          <label><?php _e('Traffic (GB)','xui-sa'); ?></label>
          <input type="number" class="short" name="_xui_sa_gb" value="<?php echo esc_attr($gb); ?>" placeholder="50"> 
          <span class="description">0 = Unlimited</span>
        </p>
        <p class="form-field">
          <label><?php _e('Validity (days)','xui-sa'); ?></label>
          <input type="number" class="short" name="_xui_sa_days" value="<?php echo esc_attr($days); ?>" placeholder="30">
          <span class="description">0 = No expiry</span>
        </p>
        <p class="form-field">
          <label><?php _e('Inbound ID','xui-sa'); ?></label>
          <input type="number" class="short" name="_xui_sa_inbound" value="<?php echo esc_attr($inbound); ?>" placeholder="(default)">
        </p>
        <hr>
        <p class="form-field">
          <label><?php _e('Use custom Worker for this product','xui-sa'); ?></label>
          <input type="checkbox" name="_xui_sa_worker_override" value="1" <?php checked($ovr,'1'); ?>>
        </p>
        <p class="form-field">
          <label><?php _e('Worker Proxy URL','xui-sa'); ?></label>
          <input type="text" class="wide" name="_xui_sa_worker_url" value="<?php echo esc_attr($ovr_url); ?>" placeholder="https://...workers.dev">
        </p>
        <p class="form-field">
          <label><?php _e('Worker Shared Secret','xui-sa'); ?></label>
          <input type="text" class="wide" name="_xui_sa_worker_secret" value="<?php echo esc_attr($ovr_secret); ?>" placeholder="********">
        </p>
      </div>
    </div>
    <?php
});
add_action('woocommerce_admin_process_product_object', function($product){
    $fields = ['_xui_sa_gb','_xui_sa_days','_xui_sa_inbound','_xui_sa_worker_url','_xui_sa_worker_secret'];
    foreach($fields as $f){
        $v = isset($_POST[$f]) ? sanitize_text_field(wp_unslash($_POST[$f])) : '';
        $product->update_meta_data($f, $v);
    }
    $product->update_meta_data('_xui_sa_worker_override', isset($_POST['_xui_sa_worker_override']) ? '1' : '0');
});

 /**
 * Build subscription link
 */
function xui_sa_build_subscription_link($uuid, $pattern, $subId = ''){
    if (!$pattern) return '';
    // If pattern
    if (stripos($pattern, '{subId}') !== false && $subId === '') return '';
    $link = str_replace(['{uuid','{UUID}'], $uuid, $pattern);
    if ($subId){
        $link = str_replace(['{subId}','{SUBID}','{subid}'], $subId, $link);
    }
    return $link;
}

/**
 * ORDER HOOK — create account(s) after payment complete
 */
add_action('woocommerce_payment_complete', 'xui_sa_on_payment_complete', 20);
add_action('woocommerce_order_status_completed', 'xui_sa_on_payment_complete', 20);
function xui_sa_on_payment_complete($order_id){
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    $opts = get_option(XUI_SA_OPTION_KEY, []);
    $default_inbound = isset($opts['default_inbound']) ? intval($opts['default_inbound']) : 1;
    $limit_ip = isset($opts['limit_ip_default']) ? intval($opts['limit_ip_default']) : 0;
    $panel_sub_pattern = isset($opts['panel_sub_pattern']) ? $opts['panel_sub_pattern'] : '';

    foreach($order->get_items() as $item_id=>$item){
        $product = $item->get_product();
        if (!$product) continue;

        $gb   = intval($product->get_meta('_xui_sa_gb'));
        $days = intval($product->get_meta('_xui_sa_days'));
        $has_override = $product->get_meta('_xui_sa_worker_override')==='1';
        $is_xui = ($gb>0 || $days>0 || $has_override || $product->get_meta('_xui_sa_inbound'));
        if (!$is_xui) continue;

        $uuid = xui_sa_uuidv4();
        $email = $order->get_billing_email();
        $inbound = intval($product->get_meta('_xui_sa_inbound'));
        if ($inbound<=0) $inbound = $default_inbound;

        $expiry_ms = 0;
        if ($days>0){
            // Always set expiry to avoid "unlimited" accounts on panels that don't support delayed start via API
            $expiry_ms = xui_sa_now_ms() + ($days*24*60*60*1000);
        }

        $total_gb = max(0, $gb);

        // Resolve worker
        if ($has_override){
            $proxy = esc_url_raw($product->get_meta('_xui_sa_worker_url'));
            $secret= $product->get_meta('_xui_sa_worker_secret');
        }else{
            $proxy = isset($opts['proxy_url']) ? esc_url_raw($opts['proxy_url']) : '';
            $secret= isset($opts['shared_secret']) ? $opts['shared_secret'] : '';
        }
        if (empty($proxy) || empty($secret)){
            $order->add_order_note('XUI-SA: Worker not configured (proxy/secret missing).');
            continue;
        }

        $payload = [
            'uuid' => $uuid,
            'email' => $email,
            'limit_ip' => $limit_ip,
            'total_gb' => $total_gb,
            'expiry_ms' => $expiry_ms,
            'inbound_id' => $inbound,
        ];

        $res = wp_remote_post($proxy, [
            'timeout'=>30,
            'headers'=>[
                'Content-Type'=>'application/json',
                'X-Alsxui-Action'=>'add',
                'X-Alsxui-Secret'=>$secret,
            ],
            'body'=>wp_json_encode($payload),
            'sslverify'=>true,
        ]);
        if (is_wp_error($res)){
            $order->add_order_note('XUI-SA: Worker error — '.$res->get_error_message());
            continue;
        }
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);

        if ($code>=200 && $code<300){
            wc_add_order_item_meta($item_id, '_xui_uuid', $uuid);
            wc_add_order_item_meta($item_id, '_xui_inbound', $inbound);
            wc_add_order_item_meta($item_id, '_xui_total_gb', $total_gb);
            wc_add_order_item_meta($item_id, '_xui_days', $days);

// Fetch client details to get subId
$subId = '';
try {
    $res2 = wp_remote_post($proxy, [
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Alsxui-Action' => 'details',
            'X-Alsxui-Secret' => $secret,
        ],
        'body' => wp_json_encode([
            'uuid' => $uuid,
            'inbound_id' => $inbound,
        ]),
    ]);
    if (!is_wp_error($res2)){
        $code2 = wp_remote_retrieve_response_code($res2);
        $body2 = wp_remote_retrieve_body($res2);
        if ($code2>=200 && $code2<300){
            $obj2 = json_decode($body2, true);
            if (is_array($obj2) && !empty($obj2['subId'])){
                $subId = sanitize_text_field($obj2['subId']);
                wc_add_order_item_meta($item_id, '_xui_sub_id', $subId);
            }
        }
    }
} catch (\Throwable $e) {}
// Build Subscription link using {subId}
$sub_link = xui_sa_build_subscription_link($uuid, $panel_sub_pattern, $subId);
if ($sub_link){
    wc_add_order_item_meta($item_id, '_xui_sub_link', esc_url_raw($sub_link));
    $order->add_order_note('XUI-SA: Account created. Subscription: '.$sub_link, true);
}else{
    $order->add_order_note('XUI-SA: Account created (UUID: '.$uuid.').', true);
}

            }else{
            $order->add_order_note('XUI-SA: Worker response '.$code.' — '.$body);
        }
    }
}

/**
 * THANKYOU + VIEW ORDER
 */
add_action('woocommerce_thankyou', function($order_id){
    if (!$order_id) return;
    $order = wc_get_order($order_id); if(!$order) return;
    $opts = get_option(XUI_SA_OPTION_KEY, []);
    $reveal = isset($opts['reveal_when']) ? $opts['reveal_when'] : 'completed';
    $status = $order->get_status();
    $ok = ($reveal==='processing' && in_array($status, ['processing','completed'])) || ($reveal==='completed' && $status==='completed');
    if (!$ok){ echo '<div class="xui-sa-wrap"><p>'.__('Subscription links will be available soon.','xui-sa').'</p></div>'; return; }
    echo '<div class="xui-sa-wrap"><h3>X-UI Subscription Info</h3><ul>';
    foreach($order->get_items() as $item){
        $sub = wc_get_order_item_meta($item->get_id(), '_xui_sub_link', true);
        $uuid = wc_get_order_item_meta($item->get_id(), '_xui_uuid', true);
        if ($sub){
            echo '<li><a href="'.esc_url($sub).'" target="_blank" rel="noreferrer noopener">Subscription Link</a> — <code>'.$uuid.'</code></li>';
        }elseif($uuid){
            echo '<li><code>'.$uuid.'</code></li>';
        }
    }
    echo '</ul></div>';
}, 20);

/**
 * MY ACCOUNT
 */
add_action('init', function(){ add_rewrite_endpoint('xui-subscriptions', EP_ROOT|EP_PAGES); });
add_filter('query_vars', function($vars){ $vars[]='xui-subscriptions'; return $vars; });
add_filter('woocommerce_account_menu_items', function($items){
    $new = [];
    foreach($items as $k=>$v){
        $new[$k]=$v;
        if ($k==='downloads'){ $new['xui-subscriptions'] = __('XUI Subscriptions','xui-sa'); }
    }
    if (!isset($new['xui-subscriptions'])) $new['xui-subscriptions'] = __('XUI Subscriptions','xui-sa');
    return $new;
}, 99);
add_action('woocommerce_account_xui-subscriptions_endpoint', function(){
    $customer_id = get_current_user_id();
    $opts = get_option(XUI_SA_OPTION_KEY, []);
    $reveal = isset($opts['reveal_when']) ? $opts['reveal_when'] : 'completed';
    if (!$customer_id){ echo '<p>'.__('Please login.','xui-sa').'</p>'; return; }
    $orders = wc_get_orders([ 'customer_id'=>$customer_id, 'limit'=>20, 'orderby'=>'date', 'order'=>'DESC' ]);
    echo '<div class="xui-sa-wrap"><h3>'.__('Your X-UI Subscriptions','xui-sa').'</h3>';
    if (!$orders){ echo '<p>'.__('No orders found.','xui-sa').'</p></div>'; return; }
    echo '<ul>';
    foreach($orders as $order){ $status = $order->get_status(); $ok = ($reveal==='processing' && in_array($status, ['processing','completed'])) || ($reveal==='completed' && $status==='completed'); if(!$ok) continue;
        foreach($order->get_items() as $item){
            $sub = wc_get_order_item_meta($item->get_id(), '_xui_sub_link', true);
            $uuid= wc_get_order_item_meta($item->get_id(), '_xui_uuid', true);
            if ($sub || $uuid){
                echo '<li>Order #'.$order->get_id().' — '.$item->get_name().' — ';
                if ($sub) echo '<a href="'.esc_url($sub).'" target="_blank" rel="noreferrer noopener">Subscription</a> ';
                if ($uuid) echo '<code>'.$uuid.'</code>';
                echo '</li>';
            }
        }
    }
    echo '</ul></div>';
});

