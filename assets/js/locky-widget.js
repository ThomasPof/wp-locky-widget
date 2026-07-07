document.addEventListener("DOMContentLoaded", () => {
    LockyWidget.init();
});

const LockyWidget = {
    // Éléments du DOM stockés pour éviter les redondances de querySelector
    elements: {},
    baseUrl: '',
    lockNames: {},
    lockColorMap: {},
    colorPalette: ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'],

    /**
     * Initialisation principale du widget
     */
    init() {
        // Sécurité si les données globales injectées par WP manquent
        if (typeof lockyWidgetData === 'undefined' || !lockyWidgetData.root_url) return;

        this.baseUrl = lockyWidgetData.root_url;
        this.cacheElements();

        if (!this.elements.lockSelect || !this.elements.lockForm) return;

        this.initEventListeners();
        this.loadWidgetData();
    },

    /**
     * Mise en cache des références du DOM
     */
    cacheElements() {
        this.elements = {
            lockSelect:     document.getElementById("lk-lockSelect"),
            lockForm:       document.getElementById("lk-lock-form"),
            initialLoading: document.getElementById("lk-initial-loading"),
            resultBox:      document.getElementById("lk-resultBox"),
            submitBtn:      document.getElementById("lk-submitBtn"),
            lockCalendar:   document.getElementById("lk-global-calendar-wrapper"),
            calendarEl:     document.getElementById('lk-calendar-component'),
            legendEl:       document.getElementById('lk-calendar-legend'),
            bookingModal:   document.getElementById('lk-booking-modal'),
            closeModal:     document.getElementById('lk-close-modal'),
            startDateInput: document.getElementById('lk-startDate'),
            cancelModal:       document.getElementById('lk-cancel-modal'),
            closeCancelModal:  document.getElementById('lk-close-cancel-modal'),
            cancelForm:        document.getElementById('lk-cancel-form'),
            cancelResIdInput:  document.getElementById('lk-cancel-res-id'),
            cancelCodeInput:   document.getElementById('lk-cancel-code'),
            cancelResultBox:   document.getElementById('lk-cancelResultBox'),
        };
    },

    /**
     * Groupement de tous les écouteurs d'événements
     */
    initEventListeners() {
        // Soumission du formulaire
        this.elements.lockForm.addEventListener("submit", (e) => this.handleFormSubmit(e));

        // Fermeture de la modale (Clic croix)
        if (this.elements.closeModal) {
            this.elements.closeModal.addEventListener('click', () => this.closeBookingModal());
        }

        // Fermeture de la modale (Clic à l'extérieur)
        window.addEventListener('click', (e) => {
            if (e.target === this.elements.bookingModal) {
                this.closeBookingModal();
            }
            if (e.target === this.elements.cancelModal) {
                this.closeCancelModal();
            }
        });

        if (this.elements.closeCancelModal) {
            this.elements.closeCancelModal.addEventListener('click', () => this.closeCancelModal());
        }
        this.elements.cancelForm.addEventListener('submit', (e) => this.handleCancelSubmit(e));
    },

    /**
     * Chargement séquentiel des données (Cadenas puis Réservations)
     */
    loadWidgetData() {
        // On lance les deux fetch en même temps
        Promise.all([
            fetch(`${this.baseUrl}list-locks`).then(res => res.json()),
            fetch(`${this.baseUrl}get-all-reservations`).then(res => res.json())
        ])
        .then(([locksData, reservationsData]) => {
            // 1. On traite d'abord la liste des cadenas pour hydrater "this.lockNames"
            this.populateLockSelect(locksData);

            // 2. On initialise le calendrier (qui a maintenant accès aux noms via "this.lockNames")
            if (reservationsData.success) {
                this.initFullCalendar(reservationsData.reservations);
            }
            this.elements.initialLoading.style.display = "none";
        })
        .catch(err => {
            console.error("Locky Error:", err);
            this.elements.initialLoading.textContent = "Erreur de chargement du widget.";
        });
    },

    /**
     * Remplissage du select des cadenas
     */
    populateLockSelect(data) {
        if (!data.success || !data.locks.length) {
            throw new Error(data.error || "Aucun cadenas disponible.");
        }

        data.locks.forEach(lock => {
            const option = document.createElement("option");
            option.value = lock.lockId;
            option.textContent = lock.lockAlias || lock.lockName || `Cadenas #${lock.lockId}`;

            this.lockNames[lock.lockId] = option.textContent;
            this.elements.lockSelect.appendChild(option);
        });
    },

    /**
     * Traitement de l'envoi du formulaire de réservation
     */
    handleFormSubmit(e) {
        e.preventDefault();

        this.setSubmitState(true);
        this.elements.resultBox.style.display = "none";

        const formData = new FormData(this.elements.lockForm);

        fetch(`${this.baseUrl}generate-code`, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            this.elements.resultBox.style.display = "block";
            if (data.success) {
                this.elements.resultBox.innerHTML = `
                    Votre code vous a été envoyé par SMS : <strong>${data.code}</strong><br>
                    <small>Valide du ${data.startDate} au ${data.endDate}</small>
                `;
                // get all events (with new reservation) and re-render the calendar
                fetch(`${this.baseUrl}get-all-reservations`).then(res => res.json())
                .then(reservationsData => {
                    if (reservationsData.success) {
                        this.initFullCalendar(reservationsData.reservations);
                    }
                });

            } else {
                this.elements.resultBox.textContent = data.error || "Erreur de génération.";
            }
        })
        .catch(() => {
            this.elements.resultBox.style.display = "block";
            this.elements.resultBox.textContent = "Une erreur technique est survenue.";
        })
        .finally(() => {
            this.setSubmitState(false);
        });
    },

    /**
     * UI : Gère l'état de chargement du bouton submit
     */
    setSubmitState(isLoading) {
        this.elements.lockForm.style.pointerEvents = isLoading ? "none" : "auto";
        this.elements.lockForm.style.opacity = isLoading ? "0.6" : "1";
        this.elements.lockForm.style.cursor = isLoading ? "not-allowed" : "default";
        this.elements.submitBtn.disabled = isLoading;
        this.elements.submitBtn.textContent = isLoading ? "Génération..." : "Obtenir mon code d'accès";
    },

    /**
     * UI : Fermeture propre de la modale de réservation
     */
    closeBookingModal() {
        if (this.elements.bookingModal) this.elements.bookingModal.style.display = 'none';
        if (this.elements.resultBox) this.elements.resultBox.style.display = 'none';
        this.elements.lockForm.reset();
        this.setSubmitState(false);
    },

    /**
     * Attribution d'une couleur cohérente par ID de cadenas
     */
    getLockColor(lockId) {
        if (!this.lockColorMap[lockId]) {
            const index = Object.keys(this.lockColorMap).length;
            this.lockColorMap[lockId] = this.colorPalette[index % this.colorPalette.length];
        }
        return this.lockColorMap[lockId];
    },

    /**
     * Initialisation et configuration de FullCalendar
     */
    initFullCalendar(reservations) {
        if (!this.elements.calendarEl || typeof FullCalendar === 'undefined') return;

        this.elements.lockCalendar.style.display = 'block'; // Affiche le calendrier une fois les données prêtes

        const events = reservations.map(res => {
            const startDate = new Date(res.start_date);
            const endDate = new Date(res.start_date);
            endDate.setDate(startDate.getDate() + (parseInt(res.duration_days) || 1));

            return {
                id: res.id, // CRUCIAL : On passe l'ID SQL de la réservation
                title: res.client_name + (res.client_phone ? ` (${res.client_phone})` : ''),
                start: res.start_date,
                end: endDate.toISOString().split('T')[0],
                allDay: true,
                backgroundColor: this.getLockColor(res.lock_id),
                color: '#ffffff'
            };
        });

        this.renderLegend();

        const calendar = new FullCalendar.Calendar(this.elements.calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'fr',
            firstDay: 1,
            headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
            buttonText: { today: "Aujourd'hui" },
            events: events,
            height: 'auto',
            dateClick: (info) => {
                const dayElement = info.dayEl;
                if(dayElement.classList.contains('fc-day-past')) return; // Ignore les jours passés
                if (this.elements.startDateInput) this.elements.startDateInput.value = info.dateStr;
                if (this.elements.bookingModal) this.elements.bookingModal.style.display = 'flex';
            },

            // AJOUT : Injection HTML personnalisée du bouton de suppression dans le badge de l'événement
            eventContent: function(arg) {
                let titleEl = document.createElement('span');
                titleEl.textContent = arg.event.title;

                let deleteBtn = document.createElement('span');
                deleteBtn.innerHTML = '×'; // Ou '×' si tu préfères une croix
                deleteBtn.className = 'lk-delete-btn';
                deleteBtn.title = 'Annuler cette réservation';

                // On intercepte le clic sur la poubelle pour éviter qu'il déclenche d'autres actions
                deleteBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    LockyWidget.openCancelModal(arg.event.id);
                });

                let arrayOfNodes = [titleEl, deleteBtn];
                return { domNodes: arrayOfNodes };
            },
            eventClick: function(info) {
                LockyWidget.openCancelModal(info.event.id);
                // On peut éventuellement afficher une modale d'info sur la réservation ici
                // Mais on ne fait rien pour l'instant
            }
        });

        this.calendarInstance = calendar; // On garde une copie de l'instance pour refresh plus tard
        calendar.render();
    },
    /**
     * Génération de la légende des puces de couleur
     */
    renderLegend() {
        if (!this.elements.legendEl) return;
        this.elements.legendEl.innerHTML = '';

        Object.keys(this.lockColorMap).forEach(lockId => {
            const labelWrapper = document.createElement('div');
            labelWrapper.style.cssText = "display: flex; align-items: center; gap: 6px;";

            const pastille = document.createElement('span');
            pastille.style.cssText = `display: inline-block; width: 12px; height: 12px; border-radius: 3px; background-color: ${this.lockColorMap[lockId]};`;

            const label = document.createElement('span');
            label.style.cssText = "color: #475569; font-weight: 500;";
            label.textContent = this.lockNames[lockId] || `Cadenas #${lockId.substr(-4)}`;

            labelWrapper.appendChild(pastille);
            labelWrapper.appendChild(label);
            this.elements.legendEl.appendChild(labelWrapper);
        });
    },

    // 4. Ajoute les méthodes d'UI et de soumission Ajax d'annulation à la suite de ton objet
    openCancelModal(reservationId) {
        if (!reservationId) return;
        this.elements.cancelResIdInput.value = reservationId;
        this.elements.cancelModal.style.display = 'flex';
    },

    closeCancelModal() {
        this.elements.cancelModal.style.display = 'none';
        this.elements.cancelResultBox.style.display = 'none';
        this.elements.cancelForm.reset();
    },

    handleCancelSubmit(e) {
        e.preventDefault();

        const resId = this.elements.cancelResIdInput.value;
        const code  = document.getElementById('lk-cancelCode').value;

        this.elements.cancelResultBox.style.display = "block";
        this.elements.cancelResultBox.style.backgroundColor = "#f1f5f9";
        this.elements.cancelResultBox.style.color = "#334155";
        this.elements.cancelResultBox.textContent = "Vérification...";

        fetch(`${this.baseUrl}cancel-reservation`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=${resId}&code=${encodeURIComponent(code)}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.elements.cancelResultBox.style.backgroundColor = "#dcfce7";
                this.elements.cancelResultBox.style.color = "#166534";
                this.elements.cancelResultBox.textContent = "Réservation annulée ! Le calendrier se met à jour...";

                // Retrait dynamique de l'événement sur le calendrier sans recharger la page
                if (this.calendarInstance) {
                    const eventToTarget = this.calendarInstance.getEventById(resId);
                    if (eventToTarget) eventToTarget.remove();
                }

                setTimeout(() => this.closeCancelModal(), 2000);
            } else {
                this.elements.cancelResultBox.style.backgroundColor = "#fee2e2";
                this.elements.cancelResultBox.style.color = "#991b1b";
                this.elements.cancelResultBox.textContent = data.error || "Une erreur est survenue.";
            }
        })
        .catch(() => {
            this.elements.cancelResultBox.style.backgroundColor = "#fee2e2";
            this.elements.cancelResultBox.style.color = "#991b1b";
            this.elements.cancelResultBox.textContent = "Erreur de communication avec le serveur.";
        });
    }
};
