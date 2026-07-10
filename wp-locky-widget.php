<?php
/**
 * Plugin Name: Locky Widget Code Generator
 * Description: Widget de génération de codes pour cadenas Locky via l'API TTLock. Shortcode : [generateur_locky]
 * Version: 1.1.0
 * Author: Thomas Popoff
 */

if (!defined('ABSPATH')) exit;

// Définition des constantes globales du plugin
define('LK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LK_PLUGIN_URL', plugin_dir_url(__FILE__));

define('LK_CLIENT_ID',       get_option('lk_client_id', ''));
define('LK_CLIENT_SECRET',   get_option('lk_client_secret', ''));
define('LK_LOCKY_USERNAME',  get_option('lk_username', ''));
define('LK_LOCKY_PASSWORD',  get_option('lk_password', ''));
define('LK_SMSFACTOR_TOKEN', get_option('lk_smsfactor_token', ''));

// Inclusion de la classe logique
require_once LK_PLUGIN_DIR . 'includes/class-locky-api.php';
require_once LK_PLUGIN_DIR . 'includes/class-locky-admin.php';

// Enregistrement des routes REST de WordPress
add_action('rest_api_init', function () {
    register_rest_route('locky-widget/v1', '/list-locks', [
        'methods'             => 'GET',
        'callback'            => ['Locky_API', 'handle_list_locks'],
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('locky-widget/v1', '/generate-code', [
        'methods'             => 'POST',
        'callback'            => ['Locky_API', 'handle_generate_code'],
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('locky-widget/v1', '/get-all-reservations', [
        'methods'             => 'GET',
        'callback'            => ['Locky_API', 'handle_get_all_reservations'],
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('locky-widget/v1', '/cancel-reservation', [
        'methods'             => 'POST',
        'callback'            => ['Locky_API', 'handle_cancel_reservation'],
        'permission_callback' => '__return_true', // Accessible publiquement
    ]);
});

// Déclaration du Shortcode
add_shortcode('generateur_locky', 'lk_render_widget_shortcode');

function lk_render_widget_shortcode() {
    // Enregistrement et injection du script JS dédié uniquement quand le shortcode est présent
    wp_register_script('locky-widget-js', LK_PLUGIN_URL . 'assets/js/locky-widget.js', [], '1.0.0', true);
    wp_enqueue_style('locky-widget-css', LK_PLUGIN_URL . 'assets/css/locky-widget.css', [], '1.0.0');

    // Passage de variables de PHP à JS de manière sécurisée (l'URL de la REST API)
    wp_localize_script('locky-widget-js', 'lockyWidgetData', [
        'root_url' => esc_url_raw(rest_url('locky-widget/v1/'))
    ]);

    wp_enqueue_script('locky-widget-js');

    // Chargement du template HTML épuré
    ob_start();
    include LK_PLUGIN_DIR . 'templates/widget-form.php';
    return ob_get_clean();
}

/**
 * Hook d'activation : Création de la table de réservation personnalisée + Planification du Cron Job
 */
register_activation_hook(__FILE__, 'lk_activate_locky_plugin');

function lk_activate_locky_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'locky_reservations';

    $charset_collate = $wpdb->get_charset_collate();

    // Structure optimisée pour stocker la demande brute (YYYY-MM-DD + X jours)
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        lock_id varchar(50) NOT NULL,
        client_name varchar(100) NOT NULL,
        client_phone varchar(20) NOT NULL,
        start_date date NOT NULL,
        duration_days int(3) NOT NULL,
        generated_code varchar(50) NOT NULL,
        generated_code_id varchar(50) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Planification du Cron Job lors de l'activation
    lk_schedule_checkout_sms_cron();
}

/**
 * Hook de désactivation : Nettoyage du Cron Job
 */
register_deactivation_hook(__FILE__, 'lk_clear_checkout_sms_cron');

// Initialisation de la page d'administration (uniquement dans l'espace d'administration)
if (is_admin()) {
    Locky_Admin::init();
}


/* ==========================================================================
   LOGIQUE DU CRON JOB (DÉPART À 10H)
   ========================================================================== */

/**
 * Configure la tâche planifiée quotidienne à 10h00 heure de Paris
 */
function lk_schedule_checkout_sms_cron() {
    if (!wp_next_scheduled('lk_checkout_sms_daily_event')) {
        $timezone = new DateTimeZone('Europe/Paris');
        $date = new DateTime('now', $timezone);
        $date->setTime(10, 0, 0);

        // Si 10h est déjà passé aujourd'hui, on commence demain à 10h
        if ($date->getTimestamp() < time()) {
            $date->modify('+1 day');
        }

        wp_schedule_event($date->getTimestamp(), 'daily', 'lk_checkout_sms_daily_event');
    }
}

/**
 * Nettoie le cron de la base de données WordPress à la désactivation
 */
function lk_clear_checkout_sms_cron() {
    $timestamp = wp_next_scheduled('lk_checkout_sms_daily_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'lk_checkout_sms_daily_event');
    }
}

/**
 * Logique d'exécution du traitement quotidien à 10h
 */
add_action('lk_checkout_sms_daily_event', 'lk_process_daily_checkout_sms');

function lk_process_daily_checkout_sms() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'locky_reservations';

    $original_timezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Paris');
    $today = date('Y-m-d');

    // Récupération du message rédigé dans l'admin
    $sms_template = get_option('locky_sms_checkout_template', '');
    if (empty($sms_template)) {
        date_default_timezone_set($original_timezone);
        return;
    }

    // Récupération des départs du jour (start_date + duration_days = aujourd'hui)
    $departures = $wpdb->get_results($wpdb->prepare(
        "SELECT client_name, client_phone
         FROM $table_name
         WHERE DATE_ADD(start_date, INTERVAL CAST(duration_days AS SIGNED) DAY) = %s
         AND client_phone IS NOT NULL AND client_phone != ''",
        $today
    ));

    if (!empty($departures)) {
        foreach ($departures as $res) {
            // Appel de la méthode d'envoi de SMS de ta classe d'API
            if (method_exists('Locky_API', 'lk_send_sms_notification')) {
                Locky_API::lk_send_sms_notification($res->client_phone, $sms_template);
            } else {
                error_log("Locky Cron Error: La méthode Locky_API::lk_send_sms_notification n'existe pas.");
            }
        }
    }

    date_default_timezone_set($original_timezone);
}
