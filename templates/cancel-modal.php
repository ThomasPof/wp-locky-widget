<div id="lk-cancel-modal" class="lk-modal">
    <div class="lk-modal-content lk-modal-content--cancel">

        <button type="button" id="lk-close-cancel-modal" class="lk-modal-close" aria-label="Fermer">&times;</button>

        <h3 class="lk-modal-title lk-modal-title--danger">Annuler ma réservation</h3>
        <p class="lk-modal-text">Pour confirmer l'annulation, veuillez saisir le code d'accès qui vous a été envoyé par SMS.</p>

        <form id="lk-cancel-form">
            <input type="hidden" id="lk-cancel-res-id">
            <div class="lk-form-group lk-form-group--spacious">
                <label for="lk-cancelCode" class="lk-form-label">Code reçu par SMS :</label>
                <input type="text" id="lk-cancelCode" class="lk-code-input" required placeholder="Ex: 5113289" autocomplete="off">
            </div>

            <button type="submit" id="lk-confirmCancelBtn" class="lk-modal-submit lk-modal-submit--danger">Confirmer l'annulation</button>
        </form>

        <div id="lk-cancelResultBox" class="lk-modal-result lk-modal-result--status"></div>
    </div>
</div>
