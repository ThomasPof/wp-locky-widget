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
        $lock_id       = $request->get_param('lockId');
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

        list($final_start_date, $final_end_date, $display_start, $display_end) = self::handle_star_end_dates($start_raw, $duration_days);

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
            // Reconversion des millisecondes en secondes pour le formatage PHP
            $display_start = date('Y-m-d H:i:s', $final_start_date / 1000);
            $display_end   = date('Y-m-d H:i:s', $final_end_date / 1000);

            // envoi du SMS via SMSFactor
            $sms_sent = self::lk_send_sms_notification($client_phone, $client_name, $json['keyboardPwd'], $display_start, $display_end);
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
                    'lock_id'       => $lock_id,
                    'client_name'   => $client_name,
                    'client_phone'  => $client_phone,
                    'start_date'    => $start_raw,     // Enregistre "YYYY-MM-DD"
                    'duration_days' => $duration_days, // Enregistre l'entier (1 à 3)
                    'generated_code'=> $json['keyboardPwd'],
                    'generated_code_id' => $json['keyboardPwdId']
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

    public static function handle_star_end_dates($start_raw, $duration_days) {
        // --- STRATÉGIE DE TEMPS ET COMPENSATIONS ---
        $original_timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        $today = date('Y-m-d');
        $twelve_hours_ms = 12 * 3600 * 1000;

        // Calcul de la date de fin (toujours +12h après la fin du séjour)
        $end_timestamp_ms = strtotime($start_raw . " +{$duration_days} days 00:00:00") * 1000;
        $final_end_date   = $end_timestamp_ms + $twelve_hours_ms;

        if ($start_raw === $today) {
            // SCÉNARIO AUJOURD'HUI : Pas de marge de -12h, juste "maintenant" arrondi
            $local_time = current_time('timestamp');
            $minutes    = intval(date('i', $local_time));

            // Arrondi à la demi-heure inférieure pour Sciener
            $round_time = ($minutes >= 30) ? date('H:30:00', $local_time) : date('H:00:00', $local_time);

            // final_start_date prend directement la valeur arrondie actuelle, sans soustraire 12h
            $final_start_date = strtotime($start_raw . ' ' . $round_time) * 1000;
        } else {
            // SCÉNARIO FUTUR : On commence à minuit et on applique la marge de -12h
            $start_timestamp_ms = strtotime($start_raw . ' 00:00:00') * 1000;
            $final_start_date   = $start_timestamp_ms - $twelve_hours_ms;
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
     * @param string $name      Nom/Prénom du client
     * @param string $code      Code généré par TTLock
     * @param string $startDate Date de début formatée (ex: jeudi 6 juillet 2026)
     * @param string $endDate   Date de fin formatée
     * @return bool             True si envoyé avec succès, false sinon
     */
    public static function lk_send_sms_notification($phone, $name, $code, $startDate, $endDate) {
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

        // 2. Formatage du texte du message (Attention aux 160 caractères par SMS standard)
        $message_text = sprintf(
            "Bonjour %s, votre code d'acces pour le cadenas est : %s. Valide du %s au %s.",
            $name,
            $code,
            $startDate,
            $endDate
        );

        // 4. Construction de l'URL finale avec les paramètres GET requis

        $sms_url = add_query_arg([
            'text'  => $message_text,
            'to'    => $phone,
            'token' => $api_token
        ], 'https://api.smsfactor.com/send');

        // Optionnel : Si tu veux ajouter l'émetteur personnalisé, l'API GET de SMSFactor utilise généralement le paramètre 'sender'
        // $sms_url = add_query_arg('sender', 'LOCKY', $sms_url);

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

        if ($response_code === 200 && isset($response_body['status']) && $response_body['status'] === 'OK') {
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
     * Tente d'annuler une réservation via la vérification du code généré
     */
    public static function handle_cancel_reservation(WP_REST_Request $request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'locky_reservations';

        $reservation_id = intval($request->get_param('id'));
        $user_code      = sanitize_text_field($request->get_param('code'));

        if (!$reservation_id || empty($user_code)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Données incomplètes.'], 400);
        }

        // 1. On cherche la réservation pour vérifier le code
        $reservation = $wpdb->get_row($wpdb->prepare(
            "SELECT generated_code, generated_code_id, lock_id FROM $table_name WHERE id = %d",
            $reservation_id
        ));

        if (!$reservation) {
            return new WP_REST_Response(['success' => false, 'error' => 'Réservation introuvable.'], 404);
        }

        // 2. Vérification stricte du code d'accès
        if (trim($reservation->generated_code) !== trim($user_code)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Le code saisi est incorrect.'], 403);
        }

        // revocation du code sur TTLock
        $revoked = self::lk_revoke_ttlock_code($reservation->lock_id, $reservation->generated_code_id);
        if (!$revoked) {
            return new WP_REST_Response(['success' => false, 'error' => 'Échec de la révocation du code sur TTLock.'], 500);
        }

        // 3. Suppression
        $deleted = $wpdb->delete($table_name, ['id' => $reservation_id], ['%d']);

        if ($deleted) {
            // Révocation du code sur TTLock
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
            'date'          => current_time('timestamp') * 1000 // Timestamp en millisecondes requis par TTLock
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
}
