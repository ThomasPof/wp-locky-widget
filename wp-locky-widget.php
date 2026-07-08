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
 * Hook d'activation : Création de la table de réservation personnalisée
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
}

// Initialisation de la page d'administration (uniquement dans l'espace d'administration)
if (is_admin()) {
    Locky_Admin::init();
}
