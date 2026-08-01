<?php
/**
 * Plugin Name: Spam Cleanup Dashboard
 * Description: Ontvangt rapportages van het externe Python spam-cleanup script (via GitHub Actions) en toont ze in een WordPress dashboard.
 * Version: 1.0.0
 * Author: Jouw naam
 * Text Domain: spamcleanup-dashboard
 */

if (!defined('ABSPATH')) {
    exit; // Direct toegang niet toegestaan.
}

define('SPAMCLEANUP_DB_VERSION', '1.0');
define('SPAMCLEANUP_TABLE', 'spamcleanup_history');

/* -------------------------------------------------------------------------
 * ACTIVATIE: custom database tabel aanmaken + API key genereren
 * ---------------------------------------------------------------------- */

register_activation_hook(__FILE__, 'spamcleanup_activate');

function spamcleanup_activate() {
    global $wpdb;

    $table_name      = $wpdb->prefix . SPAMCLEANUP_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        run_time DATETIME NOT NULL,
        item_time VARCHAR(10) NOT NULL,
        folder VARCHAR(191) NOT NULL,
        sender VARCHAR(255) NOT NULL,
        subject TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY run_time (run_time),
        KEY folder (folder)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('spamcleanup_db_version', SPAMCLEANUP_DB_VERSION);

    // Genereer eenmalig een API key als die nog niet bestaat.
    if (!get_option('spamcleanup_api_key')) {
        update_option('spamcleanup_api_key', wp_generate_password(40, false, false));
    }
}

/* -------------------------------------------------------------------------
 * REST API ENDPOINT
 * POST /wp-json/spamcleanup/v1/report
 * Body: { "generated_at": "...", "history": [ {timestamp, folder, sender, subject}, ... ] }
 * Header: X-API-Key: <key>
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('spamcleanup/v1', '/report', [
        'methods'             => 'POST',
        'callback'            => 'spamcleanup_receive_report',
        'permission_callback' => 'spamcleanup_check_api_key',
    ]);

    register_rest_route('spamcleanup/v1', '/status', [
        'methods'             => 'GET',
        'callback'            => 'spamcleanup_status',
        'permission_callback' => 'spamcleanup_check_api_key',
    ]);
});

function spamcleanup_check_api_key(WP_REST_Request $request) {
    $provided = $request->get_header('x-api-key');
    $stored   = get_option('spamcleanup_api_key');

    if (empty($stored) || empty($provided) || !hash_equals($stored, $provided)) {
        return new WP_Error(
            'spamcleanup_unauthorized',
            'Ongeldige of ontbrekende API key.',
            ['status' => 401]
        );
    }
    return true;
}

function spamcleanup_status() {
    return new WP_REST_Response(['status' => 'ok', 'time' => current_time('mysql')], 200);
}

function spamcleanup_receive_report(WP_REST_Request $request) {
    global $wpdb;

    $params = $request->get_json_params();

    if (empty($params) || !isset($params['history']) || !is_array($params['history'])) {
        return new WP_Error(
            'spamcleanup_bad_request',
            'Verwacht JSON-body met een "history" array.',
            ['status' => 400]
        );
    }

    $table       = $wpdb->prefix . SPAMCLEANUP_TABLE;
    $run_time    = isset($params['generated_at'])
        ? sanitize_text_field($params['generated_at'])
        : current_time('mysql');
    $now         = current_time('mysql');
    $inserted    = 0;

    foreach ($params['history'] as $item) {
        if (!isset($item['sender'], $item['subject'], $item['folder'])) {
            continue;
        }

        $wpdb->insert(
            $table,
            [
                'run_time'   => $run_time,
                'item_time'  => isset($item['timestamp']) ? sanitize_text_field($item['timestamp']) : '',
                'folder'     => sanitize_text_field($item['folder']),
                'sender'     => sanitize_text_field($item['sender']),
                'subject'    => sanitize_textarea_field($item['subject']),
                'created_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        $inserted++;
    }

    // Bewaar tijdstip van laatste ontvangen run apart, handig voor het dashboard.
    update_option('spamcleanup_last_run', $run_time);

    return new WP_REST_Response(
        ['status' => 'ok', 'inserted' => $inserted],
        200
    );
}

/* -------------------------------------------------------------------------
 * ADMIN: instellingenpagina (API key tonen/regenereren)
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_menu_page(
        'Spam Cleanup',
        'Spam Cleanup',
        'manage_options',
        'spamcleanup-dashboard',
        'spamcleanup_render_admin_page',
        'dashicons-shield',
        80
    );

    add_submenu_page(
        'spamcleanup-dashboard',
        'Instellingen',
        'Instellingen',
        'manage_options',
        'spamcleanup-settings',
        'spamcleanup_render_settings_page'
    );
});

function spamcleanup_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['spamcleanup_regenerate']) && check_admin_referer('spamcleanup_settings_action')) {
        update_option('spamcleanup_api_key', wp_generate_password(40, false, false));
        echo '<div class="notice notice-success"><p>Nieuwe API key gegenereerd. Werk het GitHub Actions secret bij!</p></div>';
    }

    $api_key   = get_option('spamcleanup_api_key');
    $endpoint  = esc_url(rest_url('spamcleanup/v1/report'));
    ?>
    <div class="wrap">
        <h1>Spam Cleanup – Instellingen</h1>

        <table class="form-table">
            <tr>
                <th scope="row">REST endpoint</th>
                <td><code><?php echo $endpoint; ?></code></td>
            </tr>
            <tr>
                <th scope="row">API key</th>
                <td>
                    <input type="text" readonly style="width:420px;" value="<?php echo esc_attr($api_key); ?>" onclick="this.select();" />
                    <p class="description">Zet deze waarde als <code>WP_API_KEY</code> secret in je GitHub repository (Settings → Secrets and variables → Actions).</p>
                </td>
            </tr>
        </table>

        <form method="post">
            <?php wp_nonce_field('spamcleanup_settings_action'); ?>
            <p>
                <button type="submit" name="spamcleanup_regenerate" class="button button-secondary"
                    onclick="return confirm('Weet je zeker dat je een nieuwe API key wilt genereren? De oude stopt direct met werken.');">
                    Regenereer API key
                </button>
            </p>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * ADMIN: hoofd dashboard pagina met volledige historie
 * ---------------------------------------------------------------------- */

function spamcleanup_render_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . SPAMCLEANUP_TABLE;

    // Simpele filter op datum (run_time dag).
    $selected_date = isset($_GET['spamcleanup_date']) ? sanitize_text_field($_GET['spamcleanup_date']) : '';

    $where = '';
    if ($selected_date) {
        $where = $wpdb->prepare(" WHERE DATE(run_time) = %s", $selected_date);
    }

    $total_removed = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table" . $where);
    $last_run      = get_option('spamcleanup_last_run', '—');

    $rows = $wpdb->get_results(
        "SELECT * FROM $table" . $where . " ORDER BY run_time DESC, id DESC LIMIT 500"
    );

    $dates = $wpdb->get_col("SELECT DISTINCT DATE(run_time) FROM $table ORDER BY run_time DESC LIMIT 30");
    ?>
    <div class="wrap">
        <h1>Spam Cleanup Dashboard</h1>

        <div style="display:flex; gap:20px; margin:20px 0;">
            <div class="card" style="padding:16px 20px;">
                <h2 style="margin-top:0;">Laatste run</h2>
                <p style="font-size:18px;"><?php echo esc_html($last_run); ?></p>
            </div>
            <div class="card" style="padding:16px 20px;">
                <h2 style="margin-top:0;">Totaal verwijderd<?php echo $selected_date ? ' (geselecteerde dag)' : ''; ?></h2>
                <p style="font-size:18px;"><?php echo esc_html($total_removed); ?></p>
            </div>
        </div>

        <form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="spamcleanup-dashboard" />
            <label for="spamcleanup_date">Filter op datum:</label>
            <select name="spamcleanup_date" id="spamcleanup_date" onchange="this.form.submit()">
                <option value="">Alle dagen</option>
                <?php foreach ($dates as $d): ?>
                    <option value="<?php echo esc_attr($d); ?>" <?php selected($selected_date, $d); ?>>
                        <?php echo esc_html($d); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:140px;">Run</th>
                    <th style="width:70px;">Tijd</th>
                    <th style="width:100px;">Map</th>
                    <th>Afzender</th>
                    <th>Onderwerp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="5">Nog geen data ontvangen.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->run_time); ?></td>
                        <td><?php echo esc_html($row->item_time); ?></td>
                        <td><?php echo esc_html($row->folder); ?></td>
                        <td><?php echo esc_html($row->sender); ?></td>
                        <td><?php echo esc_html($row->subject); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p class="description">Toont maximaal de laatste 500 rijen<?php echo $selected_date ? ' voor de geselecteerde dag' : ''; ?>.</p>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * DASHBOARD WIDGET (op het standaard WP dashboard)
 * ---------------------------------------------------------------------- */

add_action('wp_dashboard_setup', function () {
    wp_add_dashboard_widget(
        'spamcleanup_widget',
        'Spam Cleanup – Laatste run',
        'spamcleanup_render_widget'
    );
});

function spamcleanup_render_widget() {
    global $wpdb;
    $table    = $wpdb->prefix . SPAMCLEANUP_TABLE;
    $last_run = get_option('spamcleanup_last_run');

    if (!$last_run) {
        echo '<p>Nog geen rapportage ontvangen.</p>';
        return;
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table WHERE run_time = %s ORDER BY id ASC LIMIT 10", $last_run)
    );
    $count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE run_time = %s", $last_run)
    );

    echo '<p><strong>Laatste run:</strong> ' . esc_html($last_run) . '</p>';
    echo '<p><strong>Verwijderd deze run:</strong> ' . esc_html($count) . '</p>';

    if ($rows) {
        echo '<ul style="max-height:180px; overflow-y:auto;">';
        foreach ($rows as $r) {
            echo '<li>' . esc_html($r->item_time) . ' — ' . esc_html($r->sender) . ': ' . esc_html($r->subject) . '</li>';
        }
        echo '</ul>';
    }

    echo '<p><a href="' . esc_url(admin_url('admin.php?page=spamcleanup-dashboard')) . '">Bekijk volledig dashboard →</a></p>';
}
