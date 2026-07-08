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
                <input type="tel"
                    id="lk-clientPhone"
                    name="clientPhone"
                    style="width:100%;"
                    placeholder="0612345678"
                    pattern="[0-9]{10}"
                    required>
                <p style="font-size: 0.85em; color: #64748b;">Le code du cadenas vous sera envoyé par SMS.</p>
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
