<?php
if (!defined('ABSPATH')) exit;

class Locky_API {

    private static $base_url = 'https://api.sciener.com';

    private static function get_access_token() {
        $body = [
            'client_id'     => LK_CLIENT_ID,
            'client_secret' => LK_CLIENT_SECRET,
            'username'      => LK_LOCKY_USERNAME,
            'password'      => md5(LK_LOCKY_PASSWORD),
            'grant_type'    => 'password'
        ];

        $response = wp_remote_post(self::$base_url . '/oauth2/token', ['body' => $body]);
        if (is_wp_error($response)) return false;

        $json = json_decode(wp_remote_retrieve_body($response), true);
        return $json['access_token'] ?? false;
    }

    public static function handle_list_locks() {
        $token = self::get_access_token();
        if (!$token) {
            return new WP_REST_Response(['success' => false, 'error' => 'Échec d\'authentification.'], 401);
        }

        $url = add_query_arg([
            'clientId'    => LK_CLIENT_ID,
            'accessToken' => $token,
            'pageNo'      => 1,
            'pageSize'    => 100,
            'date'        => time() * 1000
        ], self::$base_url . '/v3/lock/list');

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Erreur serveur.'], 500);
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($json['list'])) {
            return new WP_REST_Response(['success' => true, 'locks' => $json['list']], 200);
        }

        return new WP_REST_Response(['success' => false, 'error' => $json['errmsg'] ?? 'Erreur.'], 400);
    }

    public static function handle_generate_code($request) {
        // Récupération de la valeur brute du formulaire (ex: "31322958|Casier 4 Golden")
        $lock_data_raw = $request->get_param('lockId');
        $lock_id = '';
        $lock_name = 'Cadenas'; // Valeur par défaut si pas de nom
        if (strpos($lock_data_raw, '|') !== false) {
            // On sépare l'ID et le Nom
            list($lock_id, $lock_name) = explode('|', $lock_data_raw);
        } else {
            $lock_id = $lock_data_raw;
        }
        $start_raw     = $request->get_param('startDate'); // Reçoit "YYYY-MM-DD"
        $duration_days = intval($request->get_param('durationDays'));

        $client_name   = sanitize_text_field($request->get_param('clientName'));
        $client_phone  = sanitize_text_field($request->get_param('clientPhone'));

        if (!$lock_id || !$start_raw || !$duration_days) {
            return new WP_REST_Response(['success' => false, 'error' => 'Champs obligatoires manquants.'], 400);
        }

        if ($duration_days < 1 || $duration_days > 3) {
            return new WP_REST_Response(['success' => false, 'error' => 'Durée invalide.'], 400);
        }

        // verify availability
        $is_available = self::handle_verify_availability($lock_id, $start_raw, $duration_days);
        if (!$is_available) {
            return new WP_REST_Response(['success' => false, 'error' => 'Ce cadenas est déjà réservé pour cette période.'], 409);
        }

        $token = self::get_access_token();
        if (!$token) {
            return new WP_REST_Response(['success' => false, 'error' => 'Échec d\'authentification.'], 401);
        }

        list($final_start_date, $final_end_date) = self::calculate_start_end_dates($start_raw, $duration_days, date('Y-m-d'));

        $body = [
            'clientId'        => LK_CLIENT_ID,
            'accessToken'     => $token,
            'lockId'          => $lock_id,
            'keyboardPwdType' => 3,
            // keyboardPwdName = clientName + clientPhone
            'keyboardPwdName' => $client_name . ' - ' . $client_phone,
            'startDate'       => $final_start_date,
            'endDate'         => $final_end_date,
            'date'            => time() * 1000,
            'pwdName'         => $client_name,
            'receiverTo'      => $client_phone
        ];

        $response = wp_remote_post(self::$base_url . '/v3/keyboardPwd/get', ['body' => $body]);
        if (is_wp_error($response)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Serveur injoignable.'], 500);
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($json['keyboardPwd'])) {

            // --- FIX AFFICHAGE & SMS TIMEZONE (PARIS UTC+2) ---
            // Création d'objets DateTime basés sur les timestamps en millisecondes (divisés par 1000)
            $date_start = new DateTime('@' . intval($final_start_date / 1000));
            $date_start->setTimezone(new DateTimeZone('Europe/Paris'));
            $display_start = wp_date('l j F \à H:i', $date_start->getTimestamp(), new DateTimeZone('Europe/Paris'));

            $date_end = new DateTime('@' . intval($final_end_date / 1000));
            $date_end->setTimezone(new DateTimeZone('Europe/Paris'));
            $display_end = wp_date('l j F \à H:i', $date_end->getTimestamp(), new DateTimeZone('Europe/Paris'));
            // --- FIN DU FIX ---

            $door_code = get_option('locky_door_code', '');
            $message_text = sprintf(
                "Bonjour %s, votre code d'acces pour le %s est : %s. Valide du %s au %s. Code porte du local : %s.",
                $client_name,
                $lock_name,
                $json['keyboardPwd'],
                $display_start,
                $display_end,
                $door_code
            );
            // envoi du SMS via SMSFactor
            $sms_sent = self::lk_send_sms_notification($client_phone, $message_text);
            if (!$sms_sent) {
                error_log('Locky SMS Error: Échec de l\'envoi du SMS pour le code d\'accès. Numéro: ' . $client_phone);
            }

            /**
             * ENREGISTREMENT EN BDD DE LA RÉSERVATION BRUTE
             */
            global $wpdb;
            $table_name = $wpdb->prefix . 'locky_reservations';

            $wpdb->insert(
                $table_name,
                [
                    'lock_id'           => sanitize_key($lock_id), // Force un format d'ID propre (lettres, chiffres, tirets)
                    'client_name'       => sanitize_text_field($client_name),
                    'client_phone'      => sanitize_text_field($client_phone),
                    'start_date'        => sanitize_text_field($start_raw),
                    'duration_days'     => intval($duration_days), // Force un entier strict
                    'generated_code'    => sanitize_text_field($json['keyboardPwd']),
                    'generated_code_id' => sanitize_text_field($json['keyboardPwdId'])
                ],
                [
                    '%s', // lock_id
                    '%s', // client_name
                    '%s', // client_phone
                    '%s', // start_date (format date standard)
                    '%d', // duration_days
                    '%s', // generated_code
                    '%s'  // generated_code_id
                ]
            );

            return new WP_REST_Response([
                'success'   => true,
                'code'      => $json['keyboardPwd'],
                'startDate' => $display_start,
                'endDate'   => $display_end
            ], 200);
        }

        return new WP_REST_Response(['success' => false, 'error' => $json['errmsg'] ?? 'Erreur de génération.'], 400);
    }

    public static function calculate_start_end_dates($start_raw, $duration_days, $today = null) {
        // --- STRATÉGIE DE TEMPS ET COMPENSATIONS ---
        $original_timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        // prise des bi de 16h (-8h) la veille et retour avant 9h
        $end_offset = 9 * 3600 * 1000; // 9 heures en millisecondes
        $start_offset = 8 * 3600 * 1000; // 8 heures en millisecondes

        // Calcul de la date de fin (toujours +12h après la fin du séjour)
        $end_timestamp_ms = strtotime($start_raw . " +{$duration_days} days 00:00:00") * 1000;
        $final_end_date   = $end_timestamp_ms + $end_offset;

        if ($today && $start_raw === $today) {
            // SCÉNARIO AUJOURD'HUI : On utilise le timestamp PHP actuel (qui respecte Europe/Paris)
            $local_time = time();
            $minutes    = intval(date('i', $local_time));

            // Arrondi à la demi-heure inférieure
            $round_time = ($minutes >= 30) ? date('H:30:00', $local_time) : date('H:00:00', $local_time);

            // Construction du timestamp final en millisecondes
            $final_start_date = strtotime($start_raw . ' ' . $round_time) * 1000;
        } else {
            // SCÉNARIO FUTUR : On commence à minuit et on applique la marge de -12h
            $start_timestamp_ms = strtotime($start_raw . ' 00:00:00') * 1000;
            $final_start_date   = $start_timestamp_ms - $start_offset;
        }

        // Préparation des formats lisibles pour le retour du widget et le SMS
        $display_start = date('d/m/Y à H:i', $final_start_date / 1000);
        $display_end   = date('d/m/Y à H:i', $final_end_date / 1000);

        date_default_timezone_set($original_timezone);
        // --- FIN DE LA CONFIGURATION DU TEMPS ---
        return [$final_start_date, $final_end_date, $display_start, $display_end];
    }

    /**
     * Envoie le code d'accès par SMS via l'API de SMSFactor
     *
     * @param string $phone     Numéro de téléphone du destinataire (format international ex: +33612345678)
     * @param string $message   Message à envoyer (texte brut, pas de HTML)
     * @return bool             True si envoyé avec succès, false sinon
     */
    public static function lk_send_sms_notification($phone, $message) {
        // 1. Récupération du jeton API (à ajouter dans tes réglages, ou via une constante)
        // Par sécurité, nous pouvons réutiliser un champ option ou définir une constante temporaire
        $api_token = LK_SMSFACTOR_TOKEN; // À configurer dans les options du plugin

        if (empty($api_token)) {
            error_log('Locky SMS Error: Jeton API SMSFactor manquant ou non configuré.');
            return false;
        }

        // clean phone number (remove spaces, dashes, etc.)
        $phone = preg_replace('/\D+/', '', $phone);
        // add 33 if the number starts with 06 or 07 and doesn't already have +33
        if (preg_match('/^0[67]\d{8}$/', $phone)) {
            $phone = '33' . substr($phone, 1);
        }

        // URL officielle de l'API v3 de SMSFactor pour l'envoi de SMS uniques
        $sms_url = 'https://api.smsfactor.com/send';

        $sender_name = get_option('locky_sender_name', substr(preg_replace('/[^A-Za-z0-9]/', '', get_bloginfo('name')), 0, 11));

        // 4. Construction de l'URL finale avec les paramètres GET requis
        $sms_url = add_query_arg([
            'text'  => $message,
            'to'    => $phone,
            'token' => $api_token,
            'sender' => $sender_name
        ], 'https://api.smsfactor.com/send');

        // 5. Envoi de la requête GET via WordPress
        $response = wp_remote_get($sms_url, [
            'timeout' => 15
        ]);

        // 5. Analyse de la réponse et logs en cas d'échec
        if (is_wp_error($response)) {
            error_log('Locky SMS cURL Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code === 200 && isset($response_body['status']) && (int)$response_body['status'] === 1) {
            return true; // SMS envoyé avec succès !
        } else {
            error_log('Locky SMS API Error: Code ' . $response_code . ' - ' . print_r($response_body, true));
            return false;
        }
    }

    public static function handle_verify_availability($lock_id, $start_raw, $duration_days) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'locky_reservations';

        // Nouvelle demande :
        $new_start = $start_raw;
        // Si duration = 1 jour, le jour de fin inclus est le jour même (+0 day)
        $days_to_add = intval($duration_days) - 1;
        $new_end = date('Y-m-d', strtotime("$start_raw +$days_to_add days"));

        // Requête SQL d'intersection directe :
        // Une réservation existante chevauche si :
        // (Le début existant <= Fin nouvelle) ET (Fin existante >= Début nouvelle)
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name
            WHERE lock_id = %s
            AND start_date <= %s
            AND DATE_ADD(start_date, INTERVAL (duration_days - 1) DAY) >= %s",
            $lock_id,
            $new_end,
            $new_start
        );

        $count = intval($wpdb->get_var($query));

        return ($count === 0); // Retourne true si disponible, false si déjà pris
    }

    // Callback API : Récupère TOUTES les réservations de TOUS les cadenas
    public static function handle_get_all_reservations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'locky_reservations';

        $today = date('Y-m-d');

        // Récupération des réservations futures ET de celles terminées depuis moins de 30 jours
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, lock_id, start_date, duration_days, client_name, client_phone
             FROM $table_name
             WHERE DATE_ADD(start_date, INTERVAL duration_days DAY) >= DATE_SUB(%s, INTERVAL 30 DAY)",
            $today
        ));

        return new WP_REST_Response(['success' => true, 'reservations' => $results], 200);
    }

    /**
     * Tente d'annuler une réservation via la vérification sécurisée du code généré
     */
    public static function handle_cancel_reservation(WP_REST_Request $request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'locky_reservations';

        // Sécurisation des entrées (Casting strict en entier et assainissement textuel)
        $reservation_id = intval($request->get_param('id'));
        $user_code      = sanitize_text_field($request->get_param('code'));

        if (!$reservation_id || empty($user_code)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Données incomplètes.'], 400);
        }

        // 1. On cherche la réservation pour vérifier le code
        $reservation = $wpdb->get_row($wpdb->prepare(
            "SELECT generated_code, generated_code_id, lock_id, start_date, duration_days FROM $table_name WHERE id = %d",
            $reservation_id
        ));

        if (!$reservation) {
            return new WP_REST_Response(['success' => false, 'error' => 'Réservation introuvable.'], 404);
        }

        // 2. Vérification du code d'accès
        if (trim($reservation->generated_code) !== trim($user_code)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Le code saisi est incorrect.'], 403);
        }

        // --- CALCUL DES TIMESTAMPS EXACTS DE VALIDITÉ ---
        // On réutilise ta fonction pour générer à l'identique les formats start/end en ms
        list($final_start_date_ms, $final_end_date_ms) = self::calculate_start_end_dates($reservation->start_date, $reservation->duration_days);
        // ------------------------------------------------

        // --- VÉRIFICATION PAR LES LOGS D'OUVERTURE ---
        $is_activated = self::lk_is_code_already_activated(
            $reservation->lock_id,
            $reservation->generated_code,
            $final_start_date_ms,
            $final_end_date_ms
        );

        if ($is_activated) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Impossible d\'annuler : ce code d\'accès a déjà été utilisé pour ouvrir le cadenas.'
            ], 400);
        }

        // revocation du code sur TTLock
        $revoked = self::lk_revoke_ttlock_code($reservation->lock_id, $reservation->generated_code_id);
        if (!$revoked) {
            return new WP_REST_Response(['success' => false, 'error' => 'Échec de la révocation du code sur TTLock.'], 500);
        }

        // 3. Suppression sécurisée en limitant la clause WHERE à des formats stricts
        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $reservation_id],
            ['%d'] // Sécurise le format de l'ID passé en paramètre
        );

        if ($deleted) {
            return new WP_REST_Response(['success' => true, 'message' => 'Réservation annulée avec succès.'], 200);
        }

        return new WP_REST_Response(['success' => false, 'error' => 'Erreur lors de la suppression en base de données.'], 500);
    }


    /**
     * Révoque un code d'accès directement sur l'API TTLock via son ID unique
     *
     * @param string $lock_id             L'ID du cadenas
     * @param string $generated_code_id   L'ID du code d'accès renvoyé par TTLock (keyboardPwdId)
     * @return bool                       True si la révocation a réussi, false sinon
     */
    public static function lk_revoke_ttlock_code($lock_id, $generated_code_id) {
        $token = self::get_access_token();
        if (!$token) {
            error_log('Locky TTLock Revocation Error: Impossible de récupérer le token d\'accès.');
            return false;
        }

        // Endpoint officiel de TTLock pour la suppression définitive d'un code
        $url = 'https://api.ttlock.com/v3/keyboardPwd/delete';

        // Construction du payload attendu par TTLock
        $body = [
            'clientId'      => LK_CLIENT_ID,
            'accessToken'   => $token,
            'lockId'        => $lock_id,
            'keyboardPwdId' => $generated_code_id, // Utilisation de l'ID officiel du code
            'date'          => time() * 1000 // Timestamp en millisecondes requis par TTLock
        ];

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'body'    => $body
        ]);

        if (is_wp_error($response)) {
            error_log('Locky TTLock Revocation Error: ' . $response->get_error_message());
            return false;
        }

        $res_body = json_decode(wp_remote_retrieve_body($response), true);

        // errcode 0 = Succès chez TTLock
        if (isset($res_body['errcode']) && $res_body['errcode'] === 0) {
            return true;
        }

        error_log('Locky TTLock Revocation API Refusal: ' . print_r($res_body, true));
        return false;
    }

    /**
     * Vérifie si le code a réellement été utilisé pour ouvrir le cadenas
     * en scannant l'historique sur la plage exacte de validité du code
     */
    public static function lk_is_code_already_activated($lock_id, $generated_code, $final_start_date_ms, $final_end_date_ms) {
        $token = self::get_access_token();
        if (!$token) return false;

        $url = 'https://api.ttlock.com/v3/lockRecord/list';

        $body = [
            'clientId'    => LK_CLIENT_ID,
            'accessToken' => $token,
            'lockId'      => $lock_id,
            'startDate'   => $final_start_date_ms, // Début de validité du code (avec ton offset de 8h la veille)
            'endDate'     => $final_end_date_ms,   // Fin de validité du code (avec ton offset de 9h le lendemain)
            'pageNo'      => 1,
            'pageSize'    => 100, // On élargit pour être sûr de capter le passage dans la plage
            'date'        => time() * 1000
        ];

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'body'    => $body
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $res_body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($res_body['list']) && is_array($res_body['list'])) {
            foreach ($res_body['list'] as $record) {

                // 1. On extrait et nettoie les valeurs du log
                $log_pwd     = isset($record['keyboardPwd']) ? trim($record['keyboardPwd']) : '';
                $log_success = isset($record['success']) ? intval($record['success']) : 0;

                // 2. FILTRE STRICT : Le code doit matcher ET l'ouverture doit être un succès (success === 1)
                if ($log_pwd === trim($generated_code) && $log_success === 1) {
                    error_log("Locky Security: Tentative d'annulation bloquée. Le code {$generated_code} a ouvert le cadenas avec succès.");
                    return true; // Match parfait ! Le client est entré, on bloque l'annulation.
                }
            }
        }

        if (isset($res_body['list']) && is_array($res_body['list'])) {
            foreach ($res_body['list'] as $record) {
                // Si le code brute correspond à un log d'ouverture
                if (isset($record['keyboardPwd']) && trim($record['keyboardPwd']) === trim($generated_code)) {
                    return true;
                }
            }
        }

        return false;
    }
}
