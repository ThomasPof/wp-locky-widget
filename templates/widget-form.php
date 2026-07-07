
<?php if (!defined('ABSPATH')) exit; ?>

<div class="locky-widget-container">
    <div id="lk-initial-loading">Chargement du calendrier...</div>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

    <div class="lk-calendar-container" id="lk-global-calendar-wrapper" style="margin-top: 25px; display: none;">
        <h4 style="margin-top:0; margin-bottom:10px;">Calendrier des biplaces</h4>
        <div id="lk-calendar-legend" style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 15px; font-size: 0.85em;"></div>
        <div id="lk-calendar-component"></div>
    </div>

    <div id="lk-booking-modal" class="lk-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
        <div class="lk-modal-content" style="background: #fff; padding: 25px; border-radius: 8px; width: 100%; max-width: 450px; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">

            <span id="lk-close-modal" style="position: absolute; top: 12px; right: 15px; font-size: 24px; cursor: pointer; color: #64748b; font-weight: bold;">&times;</span>

            <h3 style="margin-top: 0; margin-bottom: 20px;">Réserver un biplace</h3>

            <form id="lk-lock-form">
                <div class="lk-form-group" style="margin-bottom: 12px;">
                    <label for="lk-clientName">Nom / Prénom :</label>
                    <input type="text" id="lk-clientName" name="clientName" style="width:100%;" required>
                </div>

                <div class="lk-form-group" style="margin-bottom: 12px;">
                    <label for="lk-clientPhone">Numéro de téléphone :</label>
                    <input type="tel" id="lk-clientPhone" name="clientPhone" style="width:100%;" placeholder="+33612345678" required>
                </div>

                <div class="lk-form-group" style="margin-bottom: 12px;">
                    <label for="lk-startDate">Date de début :</label>
                    <input type="date" id="lk-startDate" name="startDate" style="width:100%;" required>
                </div>

                <div class="lk-form-group" style="margin-bottom: 12px;">
                    <label for="lk-durationDays">Durée :</label>
                    <select id="lk-durationDays" name="durationDays" style="width:100%;" required>
                        <option value="1">1 jour</option>
                        <option value="2">2 jours</option>
                        <option value="3">3 jours</option>
                    </select>
                </div>

                <div class="lk-form-group" style="margin-bottom: 20px;">
                    <label for="lk-lockSelect">Biplace :</label>
                    <select id="lk-lockSelect" name="lockId" style="width:100%;" required></select>
                </div>

                <button type="submit" id="lk-submitBtn" style="width: 100%;">Obtenir mon code d'accès</button>
            </form>

            <div id="lk-resultBox" class="lk-result" style="display: none; margin-top: 15px;"></div>
        </div>
    </div>
</div>


<style>
    /* Intégration esthétique rapide dans ton widget */
    #lk-calendar-component {
        max-width: 100%;
        background: #ffffff;
        font-family: inherit;
    }
    /* Personnalisation des badges d'événements (réservations) */
    .fc-event {
        cursor: pointer;
        padding: 2px 4px;
        font-size: 0.8em !important;
        border-radius: 3px !important;
        border: none !important;
    }

    /* Change le curseur et ajoute un fond gris clair sur les cases des jours au survol */
    .fc .fc-daygrid-day:hover {
        cursor: pointer;
        background-color: #f1f5f9 !important; /* Couleur gris bleuté très léger au hover */
    }

    /* Optionnel : Si tu veux aussi changer la couleur au survol des jours qui sont déjà réservés (les événements) */
    .fc-event:hover {
        filter: brightness(0.9); /* Assombrit légèrement la couleur du badge au survol */
        cursor: pointer;
    }
    </style>
