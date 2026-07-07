document.addEventListener("DOMContentLoaded", () => {
    // Sécurité si les données globales injectées manquent
    if (typeof lockyAdminData === 'undefined' || !lockyAdminData.root_url) return;

    const lockCells = document.querySelectorAll('.lk-lock-id');
    if (lockCells.length === 0) return; // Aucun tableau ou aucune ligne sur la page

    // Appel de l'API pour récupérer la liste des cadenas et leurs noms
    fetch(`${lockyAdminData.root_url}list-locks`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.locks.length > 0) {

                // 1. On crée un dictionnaire { lockId: "Nom du cadenas" }
                const lockNames = {};
                data.locks.forEach(lock => {
                    lockNames[lock.lockId] = lock.lockAlias || lock.lockName || `Cadenas #${lock.lockId}`;
                });

                // 2. On parcourt toutes les cellules du tableau pour remplacer l'ID par le nom
                lockCells.forEach(cell => {
                    const lockId = cell.getAttribute('data-lock-id');

                    if (lockNames[lockId]) {
                        // On remplace le code brut par le joli nom textuel
                        cell.innerHTML = `<strong>${lockNames[lockId]}</strong><br><small style="color: #94a3b8; font-family: monospace;">${lockId}</small>`;
                    }
                });
            }
        })
        .catch(err => console.error("Locky Admin Error:", err));
});
