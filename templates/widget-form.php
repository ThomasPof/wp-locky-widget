
<?php if (!defined('ABSPATH')) exit; ?>

<div class="locky-widget-container">
    <div id="lk-initial-loading">
        <h3>Chargement du calendrier...</h3>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

    <div class="lk-calendar-container" id="lk-global-calendar-wrapper">
        <div id="lk-calendar-legend"></div>
        <div id="lk-calendar-component"></div>
        <div id="lk-new-booking-btn-wrapper">
            <button id="lk-new-booking-btn">
                Réserver un biplace
            </button>
        </div>
    </div>

    <?php include LK_PLUGIN_DIR . 'templates/booking-modal.php'; ?>
    <?php include LK_PLUGIN_DIR . 'templates/cancel-modal.php'; ?>
</div>
