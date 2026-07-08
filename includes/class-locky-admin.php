<?php
/**
 * Gestion de la page d'administration et des réglages Locky
 */

if (!defined('ABSPATH')) {
    exit;
}

class Locky_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_locky_settings']);
        add_action('admin_init', [__CLASS__, 'handle_delete_reservation']);

        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    /**
     * Ajoute les menus Locky dans l'administration
     */
    public static function add_admin_menu() {
        // Menu Principal : Liste des réservations
        add_menu_page(
            'Réservations Locky',
            'Locky',
            'manage_options',
            'locky-reservations',
            [__CLASS__, 'render_admin_page'],
            'dashicons-unlock',
            26
        );

        // Sous-menu : Configuration
        add_submenu_page(
            'locky-reservations',
            'Configuration API Locky',
            'Réglages',
            'manage_options',
            'locky-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    /**
     * Enregistre les 4 paramètres dans la table wp_options via l'API Settings de WP
     */
    public static function register_locky_settings() {
        register_setting('locky_settings_group', 'lk_client_id');
        register_setting('locky_settings_group', 'lk_client_secret');
        register_setting('locky_settings_group', 'lk_username');
        register_setting('locky_settings_group', 'lk_password');
        register_setting('locky_settings_group', 'lk_smsfactor_token');
    }

    /**
     * Rendu HTML de la page des réglages
     */
    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>⚙️ Configuration de l'API Locky / TTLock</h1>
            <p class="description">Renseignez ici vos identifiants de l'API TTLock pour permettre au widget de communiquer avec vos cadenas.</p>

            <hr class="wp-header-end">

            <form method="post" action="options.php" style="margin-top: 20px; max-width: 700px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                <?php
                // Injecte les champs cachés requis par WordPress (Nonces, etc.)
                settings_fields('locky_settings_group');
                do_settings_sections('locky_settings_group');
                ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="lk_client_id">Client ID</label></th>
                        <td>
                            <input type="text" id="lk_client_id" name="lk_client_id" value="<?php echo esc_attr(get_option('lk_client_id')); ?>" class="regular-text" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lk_client_secret">Client Secret</label></th>
                        <td>
                            <input type="text" id="lk_client_secret" name="lk_client_secret" value="<?php echo esc_attr(get_option('lk_client_secret')); ?>" class="regular-text" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lk_username">Identifiant / Email TTLock</label></th>
                        <td>
                            <input type="email" id="lk_username" name="lk_username" value="<?php echo esc_attr(get_option('lk_username')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lk_password">Mot de passe TTLock</label></th>
                        <td>
                            <input type="password" id="lk_password" name="lk_password" value="<?php echo esc_attr(get_option('lk_password')); ?>" class="regular-text">
                            <p class="description">Le mot de passe sera masqué pour des raisons de sécurité.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lk_smsfactor_token">Token SMSFactor</label></th>
                        <td>
                            <input type="text" id="lk_smsfactor_token" name="lk_smsfactor_token" value="<?php echo esc_attr(get_option('lk_smsfactor_token')); ?>" class="regular-text">
                            <p class="description">Le token SMSFactor est utilisé pour l'envoi des codes par SMS. Assurez-vous de l'avoir configuré correctement.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Sauvegarder les identifiants'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Traitement de la suppression d'une réservation (Inchangé)
     */
    public static function handle_delete_reservation() {
        if (!current_user_can('manage_options') || !isset($_GET['action']) || $_GET['action'] !== 'delete') {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_reservation_' . $_GET['id'])) {
            wp_die('Action non autorisée.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'locky_reservations';
        $reservation_id = intval($_GET['id']);

        $reservation = $wpdb->get_row($wpdb->prepare(
            "SELECT generated_code_id, lock_id FROM $table_name WHERE id = %d",
            $reservation_id
        ));

        if ($reservation) {
            Locky_API::lk_revoke_ttlock_code($reservation->lock_id, $reservation->generated_code_id);
        }

        $wpdb->delete($table_name, ['id' => $reservation_id], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=locky-reservations&deleted=true'));
        exit;
    }

    /**
     * Rendu HTML de la page principale de l'historique (Inchangé)
     */
    public static function render_admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'locky_reservations';

        if (isset($_GET['deleted']) && $_GET['deleted'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>La réservation a été supprimée avec succès.</p></div>';
        }

        $reservations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Historique des Réservations Locky</h1>
            <a href="<?php echo admin_url('admin.php?page=locky-settings'); ?>" class="page-title-action">⚙️ Configurer l'API</a>

            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped table-view-list" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th scope="col" style="width: 50px; font-weight: bold;">ID</th>
                        <th scope="col" style="font-weight: bold;">Nom / Prénom</th>
                        <th scope="col" style="font-weight: bold;">Téléphone</th>
                        <th scope="col" style="font-weight: bold;">Cadenas (ID)</th>
                        <th scope="col" style="font-weight: bold;">Date Début brute</th>
                        <th scope="col" style="font-weight: bold;">Durée</th>
                        <th scope="col" style="font-weight: bold;">Code Généré</th>
                        <th scope="col" style="font-weight: bold;">Date de demande</th>
                        <th scope="col" style="width: 100px; font-weight: bold; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($reservations)) : ?>
                        <?php foreach ($reservations as $res) : ?>
                            <?php
                            $delete_url = wp_nonce_url(
                                admin_url('admin.php?page=locky-reservations&action=delete&id=' . $res->id),
                                'delete_reservation_' . $res->id
                            );
                            ?>
                            <tr>
                                <td><code>#<?php echo esc_html($res->id); ?></code></td>
                                <td><strong><?php echo esc_html($res->client_name); ?></strong></td>
                                <td><?php echo esc_html($res->client_phone ? $res->client_phone : '—'); ?></td>
                                <td class="lk-lock-id" data-lock-id="<?php echo esc_attr($res->lock_id); ?>">
                                    <code><?php echo esc_html($res->lock_id); ?></code>
                                </td>
                                <td><code><?php echo esc_html($res->start_date); ?></code></td>
                                <td><?php echo esc_html($res->duration_days); ?> jour<?php echo $res->duration_days > 1 ? 's' : ''; ?></td>
                                <td><span class="notice notice-success" style="padding: 3px 8px; font-weight: bold; font-family: monospace; font-size: 1.1em; display: inline-block; margin: 0;"><?php echo esc_html($res->generated_code); ?></span></td>
                                <td><?php echo esc_html(date_i18n('d/m/Y à H:i', strtotime($res->created_at))); ?></td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <a href="<?php echo esc_url($delete_url); ?>"
                                       class="button button-link-delete"
                                       style="color: #dc2626;"
                                       onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette réservation ?');">
                                        Supprimer
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 20px; font-style: italic; color: #64748b;">
                                Aucune réservation enregistrée pour le moment.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Enregistre et charge le script JS pour l'administration
     */
    public static function enqueue_admin_assets($hook) {
        // On charge le script uniquement si on est sur la page de l'historique ou des réglages Locky
        if (strpos($hook, 'locky-reservations') === false && strpos($hook, 'locky-settings') === false) {
            return;
        }

        // Enregistre ton nouveau fichier JS admin
        wp_register_script('locky-admin-js', LK_PLUGIN_URL . 'assets/js/locky-admin.js', [], '1.0.0', true);

        // Passe l'URL de l'API REST de manière sécurisée au JS
        wp_localize_script('locky-admin-js', 'lockyAdminData', [
            'root_url' => esc_url_raw(rest_url('locky-widget/v1/'))
        ]);

        wp_enqueue_script('locky-admin-js');
    }
}
