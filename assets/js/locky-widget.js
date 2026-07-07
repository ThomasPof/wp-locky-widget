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
            startDateInput: document.getElementById('lk-startDate')
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
        });
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
                    Votre code : <strong>${data.code}</strong><br>
                    <small>Valide du ${data.startDate} au ${data.endDate}</small>
                `;
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
                if (this.elements.startDateInput) this.elements.startDateInput.value = info.dateStr;
                if (this.elements.bookingModal) this.elements.bookingModal.style.display = 'flex';
            }
        });

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
    }
};
