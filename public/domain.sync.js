/* DNSManage - domain.sync.js
 * Bouton de synchronisation dans la fiche domaine
 */
(function() {
    var btn = document.getElementById('dns-domain-sync-btn');
    if (!btn) return;

    btn.addEventListener('click', function() {
        var result = document.getElementById('dns-domain-sync-result');
        var csrf   = document.querySelector('meta[property="glpi:csrf_token"]') ? 
                     document.querySelector('meta[property="glpi:csrf_token"]').content : '';
        btn.disabled = true;
        result.innerHTML = '<span class="badge bg-info me-2">En cours...</span>';

        fetch(btn.dataset.syncUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Glpi-Csrf-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'sync_domain',
                account_id: btn.dataset.accountId,
                domain_ref: btn.dataset.domainRef
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) {
                result.innerHTML = '<span class="badge bg-success me-2"><i class="ti ti-check me-1"></i>' + d.message + '</span>';
                // Recharger la page après 1.5s pour afficher les contacts mis à jour
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                result.innerHTML = '<span class="badge bg-danger me-2"><i class="ti ti-x me-1"></i>' + d.message + '</span>';
            }
        })
        .catch(function() {
            btn.disabled = false;
            result.innerHTML = '<span class="badge bg-danger me-2">Erreur de communication.</span>';
        });
    });
})();
