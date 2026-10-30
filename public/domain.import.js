/* DNSManage - domain.import.js - v3.1.7
 * Traitement AJAX par lot avec barre de progression
 */
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('dns-import-form');
    if (!form) return;

    var btnSubmit   = document.getElementById('dns-import-submit');
    var progressDiv = document.getElementById('dns-import-progress');
    var progressBar = document.getElementById('dns-import-bar');
    var progressMsg = document.getElementById('dns-import-msg');
    var resultsList = document.getElementById('dns-import-results');
    var syncUrl     = form.dataset.syncUrl;
    var accountId   = form.dataset.accountId;
    var csrf        = document.querySelector('meta[property="glpi:csrf_token"]') ?
                      document.querySelector('meta[property="glpi:csrf_token"]').content : '';

    function post(action, params) {
        params.action     = action;
        params.account_id = accountId;
        return fetch(syncUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Glpi-Csrf-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(params)
        }).then(function(r) { return r.json(); });
    }

    function addResult(label, success, message) {
        var li = document.createElement('li');
        li.className = 'list-group-item py-1 ' + (success ? 'list-group-item-success' : 'list-group-item-danger');
        li.innerHTML = (success ? '<i class="ti ti-check me-1"></i>' : '<i class="ti ti-x me-1"></i>') +
                       '<strong>' + label + '</strong>' + (message ? ' — ' + message : '');
        resultsList.appendChild(li);
    }

    async function processAll(tasks, actionName, getParams, getLabel, onSuccess) {
        var total = tasks.length;
        var done  = 0;
        var errors = 0;

        for (var i = 0; i < tasks.length; i++) {
            var task = tasks[i];
            progressMsg.textContent = getLabel(task) + ' (' + (done + 1) + '/' + total + ')';

            try {
                var result = await post(actionName, getParams(task));
                done++;
                var pct = Math.round((done / total) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
                addResult(getLabel(task), result.success, result.message);
                if (result.success && onSuccess) {
                    onSuccess(task);
                }
                if (!result.success) errors++;
            } catch(e) {
                done++;
                errors++;
                addResult(getLabel(task), false, 'Erreur réseau');
            }
        }
        return errors;
    }

    btnSubmit && btnSubmit.addEventListener('click', async function(e) {
        e.preventDefault();

        // Importer uniquement les domaines COCHES avec entité définie
        var imports = [];
        document.querySelectorAll('.dns-import-checkbox:checked').forEach(function(cb) {
            var ref = cb.dataset.ref;
            var hidden = document.querySelector('.dns-entity-value[data-ref="' + ref + '"]');
            if (hidden && parseInt(hidden.value) > 0) {
                imports.push({ encoded_ref: ref, entities_id: hidden.value });
            }
        });

        // Collecter les restaurations
        var restores = [];
        var restoreIdx = 0;
        document.querySelectorAll('.dns-restore-checkbox:checked').forEach(function(cb) {
            restoreIdx++;
            restores.push({ domain_id: cb.dataset.domainId, provider_ref: cb.dataset.ref, index: restoreIdx });
        });

        // Collecter les suppressions
        var deletes = [];
        var deleteIdx = 0;
        document.querySelectorAll('.dns-delete-checkbox:checked').forEach(function(cb) {
            deleteIdx++;
            deletes.push({ domain_id: cb.dataset.domainId, index: deleteIdx });
        });

        var total = imports.length + restores.length + deletes.length;
        if (total === 0) {
            alert('Aucune action sélectionnée. Cochez des domaines avec une entité définie.');
            return;
        }

        // Afficher la progression
        if (btnSubmit) btnSubmit.style.pointerEvents = 'none';
        progressDiv.style.display = '';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
        progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated';
        resultsList.innerHTML = '';

        var errors = 0;

        // Imports
        if (imports.length > 0) {
            errors += await processAll(imports, 'import_domain',
                function(t) { return { encoded_ref: t.encoded_ref, entities_id: t.entities_id }; },
                function(t) { return atob(t.encoded_ref); },
                function(t) {
                    // Supprimer la ligne du tableau après import réussi
                    var row = document.getElementById('row_' + t.encoded_ref);
                    if (row) row.remove();
                }
            );
        }

        // Restaurations
        if (restores.length > 0) {
            errors += await processAll(restores, 'restore_domain',
                function(t) { return { domain_id: t.domain_id, provider_ref: t.provider_ref }; },
                function(t) { return t.provider_ref + ' (restauration)'; },
                function(t) {
                    var cb = document.getElementById('rst_' + t.index);
                    if (cb) { var row = cb.closest('tr'); if (row) row.remove(); }
                }
            );
        }

        // Suppressions
        if (deletes.length > 0) {
            errors += await processAll(deletes, 'delete_domain',
                function(t) { return { domain_id: t.domain_id }; },
                function(t) { return '#' + t.domain_id + ' (corbeille)'; },
                function(t) {
                    var cb = document.getElementById('del_' + t.index);
                    if (cb) { var row = cb.closest('tr'); if (row) row.remove(); }
                }
            );
        }

        var cls = errors > 0 ? 'bg-warning' : 'bg-success';
        progressBar.className = 'progress-bar ' + cls;
        progressMsg.textContent = 'Terminé ! ' + (errors > 0 ? errors + ' erreur(s).' : 'Aucune erreur.');
        if (btnSubmit) btnSubmit.style.pointerEvents = '';
    });
});
