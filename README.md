# wp-locky-widget

Plugin WordPress pour générer et envoyer des codes d'accès TTLock (SMSFactor), avec calendrier de réservation.

## Structure du plugin

- `wp-locky-widget.php`
  - Point d'entrée du plugin
  - Déclare les constantes globales
  - Enregistre les routes REST
  - Déclare le shortcode `[generateur_locky]`
  - Crée la table `wp_locky_reservations` à l'activation
- `includes/class-locky-api.php`
  - Logique métier et endpoints REST (liste cadenas, génération code, annulation, réservations)
  - Communication TTLock et SMSFactor
- `includes/class-locky-admin.php`
  - Interface d'administration (historique + réglages)
  - Gestion de la suppression d'une réservation
- `templates/widget-form.php`
  - HTML du widget public (calendrier, modale de réservation, modale d'annulation)
- `assets/js/locky-widget.js`
  - Logique front du widget public
  - Chargement API, rendu FullCalendar, soumission réservation et annulation
- `assets/js/locky-admin.js`
  - Améliore la page admin en remplaçant les IDs cadenas par des libellés lisibles

## Flux principal

1. Le shortcode affiche le template et charge `locky-widget.js`.
2. Le script récupère les cadenas + réservations via l'API REST du plugin.
3. L'utilisateur réserve depuis la modale, le backend génère un code TTLock.
4. Le plugin enregistre la réservation en base et envoie le code par SMS.
5. Une réservation peut ensuite être annulée depuis le calendrier (vérification par code SMS).
