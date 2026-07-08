<div id="lk-booking-modal" class="lk-modal">
    <div class="lk-modal-content lk-modal-content--booking">

        <button type="button" id="lk-close-modal" class="lk-modal-close" aria-label="Fermer">&times;</button>

        <h3 class="lk-modal-title">Réserver un biplace</h3>

        <form id="lk-lock-form">
            <div class="lk-form-group">
                <label for="lk-clientName">Nom / Prénom :</label>
                <input type="text" id="lk-clientName" name="clientName" required>
            </div>

            <div class="lk-form-group">
                <label for="lk-clientPhone">Numéro de téléphone :</label>
                <input type="tel"
                    id="lk-clientPhone"
                    name="clientPhone"
                    placeholder="0612345678"
                    pattern="[0-9]{10}"
                    required>
                <p class="lk-form-help">Le code du cadenas vous sera envoyé par SMS.</p>
            </div>

            <div class="lk-form-group">
                <label for="lk-startDate">Date de début :</label>
                <input type="date" id="lk-startDate" name="startDate" required>
            </div>

            <div class="lk-form-group">
                <label for="lk-durationDays">Durée :</label>
                <select id="lk-durationDays" name="durationDays" required>
                    <option value="1">1 jour</option>
                    <option value="2">2 jours</option>
                    <option value="3">3 jours</option>
                </select>
            </div>

            <div class="lk-form-group">
                <label for="lk-lockSelect">Biplace :</label>
                <select id="lk-lockSelect" name="lockId" required></select>
            </div>

            <button type="submit" id="lk-submitBtn" class="lk-modal-submit">Obtenir mon code d'accès</button>
        </form>

        <div id="lk-resultBox" class="lk-modal-result"></div>
    </div>
</div>
