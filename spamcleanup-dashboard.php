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
 * STATISTIEKEN: dagelijkse aantallen + top-afzenders
 * Gedeelde helpers, gebruikt door zowel de admin-pagina als de shortcode.
 * ---------------------------------------------------------------------- */

function spamcleanup_get_daily_counts($days = 14) {
    global $wpdb;
    $table = $wpdb->prefix . SPAMCLEANUP_TABLE;

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DATE(run_time) AS day, COUNT(*) AS total
             FROM $table
             WHERE run_time >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
             GROUP BY DATE(run_time)
             ORDER BY day ASC",
            $days - 1
        ),
        ARRAY_A
    );

    // Zet om naar een dag => aantal map, en vul ontbrekende dagen (0 verwijderd) aan.
    $counts_by_day = [];
    foreach ($results as $row) {
        $counts_by_day[$row['day']] = (int) $row['total'];
    }

    $output = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $output[$day] = $counts_by_day[$day] ?? 0;
    }

    return $output;
}

function spamcleanup_get_top_senders($limit = 10, $days = 30) {
    global $wpdb;
    $table = $wpdb->prefix . SPAMCLEANUP_TABLE;

    // Ruwe sender-waarden ophalen (kunnen "Naam <adres>" of kaal adres zijn, afhankelijk
    // van wanneer de entry is opgeslagen) en in PHP normaliseren naar alleen het adres,
    // zodat oude en nieuwe entries van dezelfde afzender samengeteld worden.
    $raw_senders = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT sender FROM $table WHERE run_time >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            $days - 1
        )
    );

    $counts = [];
    foreach ($raw_senders as $raw) {
        $email = spamcleanup_display_email($raw);
        $counts[$email] = ($counts[$email] ?? 0) + 1;
    }

    arsort($counts);
    $top = array_slice($counts, 0, $limit, true);

    $result = [];
    foreach ($top as $email => $total) {
        $result[] = ['sender' => $email, 'total' => $total];
    }
    return $result;
}

/**
 * Rendert een simpele staafdiagram als inline SVG (geen JS-library nodig).
 * $daily_counts: associatieve array van 'Y-m-d' => aantal, chronologisch.
 */
function spamcleanup_render_daily_chart_svg($daily_counts) {
    $width       = 700;
    $height      = 220;
    $padding_l   = 30;
    $padding_b   = 30;
    $padding_t   = 15;
    $chart_w     = $width - $padding_l - 10;
    $chart_h     = $height - $padding_t - $padding_b;

    $max = max(array_merge(array_values($daily_counts), [1])); // voorkom delen door 0
    $bar_count = count($daily_counts);
    $bar_gap   = 6;
    $bar_w     = $bar_count > 0 ? ($chart_w - ($bar_gap * ($bar_count - 1))) / $bar_count : 0;

    ob_start();
    ?>
    <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" style="width:100%; height:auto; font-family:inherit;">
        <!-- Y-as gridlines (0, max/2, max) -->
        <?php foreach ([0, 0.5, 1] as $frac): ?>
            <?php $y = $padding_t + $chart_h - ($frac * $chart_h); ?>
            <line x1="<?php echo $padding_l; ?>" y1="<?php echo $y; ?>" x2="<?php echo $width - 10; ?>" y2="<?php echo $y; ?>" stroke="#e5e5e5" stroke-width="1" />
            <text x="2" y="<?php echo $y + 4; ?>" font-size="10" fill="#666"><?php echo (int) round($frac * $max); ?></text>
        <?php endforeach; ?>

        <?php $x = $padding_l; $i = 0; foreach ($daily_counts as $day => $count): ?>
            <?php
                $bar_h = $max > 0 ? ($count / $max) * $chart_h : 0;
                $y = $padding_t + $chart_h - $bar_h;
                $label = date('d-m', strtotime($day));
                $show_label = ($bar_count <= 14) || ($i % 2 === 0); // voorkom te drukke as bij veel dagen
            ?>
            <rect x="<?php echo $x; ?>" y="<?php echo $y; ?>" width="<?php echo $bar_w; ?>" height="<?php echo $bar_h; ?>" fill="#2271b1" rx="2">
                <title><?php echo esc_html($day . ': ' . $count); ?></title>
            </rect>
            <?php if ($count > 0): ?>
                <text x="<?php echo $x + $bar_w / 2; ?>" y="<?php echo $y - 4; ?>" font-size="10" fill="#333" text-anchor="middle"><?php echo (int) $count; ?></text>
            <?php endif; ?>
            <?php if ($show_label): ?>
                <text x="<?php echo $x + $bar_w / 2; ?>" y="<?php echo $height - 10; ?>" font-size="10" fill="#666" text-anchor="middle"><?php echo esc_html($label); ?></text>
            <?php endif; ?>
            <?php $x += $bar_w + $bar_gap; $i++; ?>
        <?php endforeach; ?>
    </svg>
    <?php
    return ob_get_clean();
}

/**
 * Rendert de top-afzenders als een horizontale bar-lijst (HTML/CSS, geen SVG nodig).
 */
function spamcleanup_render_top_senders_html($top_senders) {
    if (empty($top_senders)) {
        return '<p>Nog geen data beschikbaar.</p>';
    }

    $max = max(array_column($top_senders, 'total'));

    ob_start();
    ?>
    <div style="display:flex; flex-direction:column; gap:8px;">
        <?php foreach ($top_senders as $row): ?>
            <?php $pct = $max > 0 ? round(($row['total'] / $max) * 100) : 0; ?>
            <div>
                <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:2px;">
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:80%;"><?php echo esc_html($row['sender']); ?></span>
                    <span style="font-weight:600;"><?php echo (int) $row['total']; ?></span>
                </div>
                <div style="background:#eee; border-radius:3px; height:8px; overflow:hidden;">
                    <div style="background:#2271b1; height:100%; width:<?php echo $pct; ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Haalt alleen het e-mailadres uit een "Naam <adres>"-string voor weergave.
 * Werkt ook met al opgeslagen oudere entries die nog de volledige From-header bevatten.
 */
function spamcleanup_display_email($raw) {
    if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $raw, $matches)) {
        return $matches[1];
    }
    // Geen "Naam <adres>"-formaat gevonden: al een kaal adres, of onherkenbaar. Toon zoals het is.
    return $raw;
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

        <div style="display:flex; gap:20px; margin:20px 0; flex-wrap:wrap; align-items:flex-start;">
            <div class="card" style="flex:2; min-width:400px; padding:16px 20px;">
                <h2 style="margin-top:0;">Verwijderd per dag (laatste 14 dagen)</h2>
                <?php echo spamcleanup_render_daily_chart_svg(spamcleanup_get_daily_counts(14)); ?>
            </div>
            <div class="card" style="flex:1; min-width:280px; padding:16px 20px;">
                <h2 style="margin-top:0;">Top 10 afzenders (30 dagen)</h2>
                <?php echo spamcleanup_render_top_senders_html(spamcleanup_get_top_senders(10, 30)); ?>
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
                        <td><?php echo esc_html(spamcleanup_display_email($row->sender)); ?></td>
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
 * SHORTCODE: [spamcleanup_dashboard]
 * Toont het dashboard op een gewone WordPress-pagina.
 * Alleen zichtbaar voor ingelogde gebruikers met manage_options-rechten.
 * ---------------------------------------------------------------------- */

add_shortcode('spamcleanup_dashboard', 'spamcleanup_render_shortcode');

function spamcleanup_render_shortcode($atts) {
    // Niet ingelogd, of geen rechten: toon niets bruikbaars.
    if (!is_user_logged_in()) {
        return '<p>Je moet ingelogd zijn om dit dashboard te bekijken. <a href="' .
            esc_url(wp_login_url(get_permalink())) . '">Inloggen</a></p>';
    }

    if (!current_user_can('manage_options')) {
        return '<p>Je hebt geen rechten om dit dashboard te bekijken.</p>';
    }

    global $wpdb;
    $table = $wpdb->prefix . SPAMCLEANUP_TABLE;

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

    // Huidige paginaURL, zodat het datumfilter blijft werken op de front-end pagina.
    $current_url = esc_url(strtok($_SERVER['REQUEST_URI'], '?'));

    ob_start();
    ?>
    <div class="spamcleanup-frontend-dashboard" style="max-width:900px; margin:0 auto; font-family:inherit;">
        <div style="display:flex; gap:20px; margin:20px 0; flex-wrap:wrap;">
            <div style="flex:1; min-width:200px; padding:16px 20px; border:1px solid #ddd; border-radius:6px;">
                <h3 style="margin-top:0;">Laatste run</h3>
                <p style="font-size:18px; margin-bottom:0;"><?php echo esc_html($last_run); ?></p>
            </div>
            <div style="flex:1; min-width:200px; padding:16px 20px; border:1px solid #ddd; border-radius:6px;">
                <h3 style="margin-top:0;">Totaal verwijderd<?php echo $selected_date ? ' (geselecteerde dag)' : ''; ?></h3>
                <p style="font-size:18px; margin-bottom:0;"><?php echo esc_html($total_removed); ?></p>
            </div>
        </div>

        <div style="display:flex; gap:20px; margin:20px 0; flex-wrap:wrap; align-items:flex-start;">
            <div style="flex:2; min-width:320px; padding:16px 20px; border:1px solid #ddd; border-radius:6px;">
                <h3 style="margin-top:0;">Verwijderd per dag (laatste 14 dagen)</h3>
                <?php echo spamcleanup_render_daily_chart_svg(spamcleanup_get_daily_counts(14)); ?>
            </div>
            <div style="flex:1; min-width:260px; padding:16px 20px; border:1px solid #ddd; border-radius:6px;">
                <h3 style="margin-top:0;">Top 10 afzenders (30 dagen)</h3>
                <?php echo spamcleanup_render_top_senders_html(spamcleanup_get_top_senders(10, 30)); ?>
            </div>
        </div>

        <form method="get" action="<?php echo $current_url; ?>" style="margin-bottom:16px;">
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

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid #ccc; text-align:left;">
                        <th style="padding:8px;">Run</th>
                        <th style="padding:8px;">Tijd</th>
                        <th style="padding:8px;">Map</th>
                        <th style="padding:8px;">Afzender</th>
                        <th style="padding:8px;">Onderwerp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5" style="padding:8px;">Nog geen data ontvangen.</td></tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px;"><?php echo esc_html($row->run_time); ?></td>
                            <td style="padding:8px;"><?php echo esc_html($row->item_time); ?></td>
                            <td style="padding:8px;"><?php echo esc_html($row->folder); ?></td>
                            <td style="padding:8px;"><?php echo esc_html(spamcleanup_display_email($row->sender)); ?></td>
                            <td style="padding:8px;"><?php echo esc_html($row->subject); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <p style="font-size:13px; color:#666;">Toont maximaal de laatste 500 rijen<?php echo $selected_date ? ' voor de geselecteerde dag' : ''; ?>.</p>
    </div>
    <?php
    return ob_get_clean();
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
            echo '<li>' . esc_html($r->item_time) . ' — ' . esc_html(spamcleanup_display_email($r->sender)) . ': ' . esc_html($r->subject) . '</li>';
        }
        echo '</ul>';
    }

    echo '<p><a href="' . esc_url(admin_url('admin.php?page=spamcleanup-dashboard')) . '">Bekijk volledig dashboard →</a></p>';
}
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
