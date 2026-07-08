<div id="lk-cancel-modal" class="lk-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div class="lk-modal-content" style="background: #fff; padding: 25px; border-radius: 8px; width: 100%; max-width: 400px; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">

        <span id="lk-close-cancel-modal" style="position: absolute; top: 12px; right: 15px; font-size: 24px; cursor: pointer; color: #64748b; font-weight: bold;">&times;</span>

        <h3 style="margin-top: 0; margin-bottom: 10px; color: #dc2626;">Annuler ma réservation</h3>
        <p style="font-size: 0.9em; color: #475569; margin-bottom: 20px;">Pour confirmer l'annulation, veuillez saisir le code d'accès qui vous a été envoyé par SMS.</p>

        <form id="lk-cancel-form">
            <input type="hidden" id="lk-cancel-res-id">
            <div class="lk-form-group" style="margin-bottom: 15px;">
                <label tribes="lk-cancelCode" style="font-weight: bold; display:block; margin-bottom: 5px;">Code reçu par SMS :</label>
                <input type="text" id="lk-cancelCode" required placeholder="Ex: 5113289" style="width:100%; text-align: center; font-size: 1.2em; letter-spacing: 2px;" autocomplete="off">
            </div>

            <button type="submit" id="lk-confirmCancelBtn" style="width: 100%; background-color: #dc2626; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-weight: bold;">Confirmer l'annulation</button>
        </form>

        <div id="lk-cancelResultBox" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px; font-size: 0.9em; text-align: center;"></div>
    </div>
</div>
