/* DNSManage - account.form.js
 * Chargé via PLUGIN_HOOKS['add_javascript']
 * Les données sont dans window.dnsmanagerData (injecté par le template Twig)
 */

window.initDnsManagerForm = function initDnsManagerForm() {
    var data = window.dnsmanagerData;

    // Vérifier que les données ET les éléments DOM sont disponibles
    if (!data || !document.getElementById('dns-provider-select')) {
        setTimeout(initDnsManagerForm, 150);
        return;
    }

    var providerData     = data.allProviderData     || {};
    var savedCredentials = data.existingCredentials || {};
    var savedEndpoint    = data.currentEndpoint     || '';
    var syncUrl          = CFG_GLPI.root_doc + '/plugins/dnsmanager/ajax/sync.php';
    var accountId        = data.accountId           || 0;

    function getCsrf() {
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.content : '';
    }

    function glpiFetch(url, params) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type':      'application/x-www-form-urlencoded',
                'X-Glpi-Csrf-Token': getCsrf(),
                'X-Requested-With':  'XMLHttpRequest',
            },
            body: new URLSearchParams(params).toString(),
        });
    }

    function renderEndpoints(endpoints) {
        var sel = document.getElementById('dns-endpoint-select');
        var sec = document.getElementById('dns-endpoint-section');
        if (!sel) return;
        sel.innerHTML = '';
        var keys = Object.keys(endpoints || {});
        if (keys.length === 0) { if (sec) sec.style.display = 'none'; return; }
        if (sec) sec.style.display = '';

        var empty = document.createElement('option');
        empty.value       = '';
        empty.textContent = '-- Choisir un endpoint --';
        empty.disabled    = true;
        empty.selected    = !savedEndpoint;
        sel.appendChild(empty);
        sel.required = true;

        keys.forEach(function(key) {
            var opt = document.createElement('option');
            opt.value       = key;
            opt.textContent = endpoints[key];
            if (key === savedEndpoint) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    function renderCredentials(fields) {
        var section = document.getElementById('dns-credentials-section');
        if (!section) return;
        section.innerHTML = '';
        var hasExisting = Object.keys(savedCredentials).length > 0;

        if (!fields || fields.length === 0) {
            section.innerHTML = '<p class="text-muted">Aucun credential requis.</p>';
            return;
        }

        fields.forEach(function(field) {
            var row   = document.createElement('div');
            row.className = 'mb-3 row';
            var label = document.createElement('label');
            label.className   = 'col-xxl-4 col-form-label';
            label.textContent = field.label + (field.required ? ' *' : '');
            var col   = document.createElement('div');
            col.className = 'col-xxl-8';
            var input = document.createElement('input');
            input.type        = field.type === 'password' ? 'password' : 'text';
            input.name        = 'cred_' + field.key;
            input.className   = 'form-control font-monospace';
            input.placeholder = field.type === 'password'
                ? (hasExisting ? '(inchangé)' : '••••••••') : '';
            if (field.type !== 'password' && savedCredentials[field.key]) {
                input.value = savedCredentials[field.key];
            }
            col.appendChild(input);
            if (field.help) {
                var help = document.createElement('div');
                help.className   = 'form-text text-muted';
                help.textContent = field.help;
                col.appendChild(help);
            }
            row.appendChild(label);
            row.appendChild(col);
            section.appendChild(row);
        });

        if (hasExisting) {
            var note = document.createElement('div');
            note.className = 'alert alert-info mt-2';
            note.innerHTML = '<i class="ti ti-info-circle me-2"></i>Laissez les champs mot de passe vides pour conserver les credentials existants.';
            section.appendChild(note);
        }
    }

    function loadProvider(type) {
        var d = providerData[type];
        if (!d) return;
        renderEndpoints(d.endpoints || {});
        renderCredentials(d.fields   || []);
    }

    // Initialisation
    var sel = document.getElementById('dns-provider-select');
    if (sel) {
        if (sel.value) loadProvider(sel.value);
        sel.addEventListener('change', function() { loadProvider(this.value); });
    }

    // Bouton test connexion
    var btnTest = document.getElementById('dns-btn-test');
    if (btnTest) {
        btnTest.addEventListener('click', function() {
            var result = document.getElementById('dns-action-result');
            var params = { action: 'test_form' };
            if (accountId) params.account_id = accountId;
            var pSel = document.getElementById('dns-provider-select');
            if (pSel) params.provider_type = pSel.value;
            var eSel = document.getElementById('dns-endpoint-select');
            if (eSel) params.endpoint = eSel.value;
            document.querySelectorAll('[name^="cred_"]').forEach(function(el) {
                if (el.value.trim()) params[el.name] = el.value;
            });
            btnTest.disabled = true;
            result.innerHTML = '<div class="alert alert-info"><i class="ti ti-loader me-2"></i>Test en cours...</div>';
            glpiFetch(syncUrl, params)
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    btnTest.disabled = false;
                    result.innerHTML = d.success
                        ? '<div class="alert alert-success"><i class="ti ti-circle-check me-2"></i>Connexion réussie !</div>'
                        : '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i><strong>Échec :</strong> ' + d.message + '</div>';
                })
                .catch(function() {
                    btnTest.disabled = false;
                    result.innerHTML = '<div class="alert alert-danger">Erreur de communication.</div>';
                });
        });
    }

    // Bouton synchroniser
    var btnSync = document.getElementById('dns-btn-sync');
    if (btnSync) {
        btnSync.addEventListener('click', function() {
            var result = document.getElementById('dns-action-result');
            btnSync.disabled = true;
            result.innerHTML = '<div class="alert alert-info"><i class="ti ti-loader me-2"></i>Synchronisation en cours...</div>';
            glpiFetch(syncUrl, { action: 'sync', account_id: accountId })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    btnSync.disabled = false;
                    if (d.success) {
                        result.innerHTML = '<div class="alert alert-success">'
                            + '<i class="ti ti-circle-check me-2"></i><strong>Terminé.</strong> '
                            + (d.updated || 0) + ' domaine(s), '
                            + ((d.records_added || 0) + (d.records_updated || 0)) + ' enregistrement(s).'
                            + (d.errors && d.errors.length ? '<br>Erreurs : ' + d.errors.join('<br>') : '')
                            + '</div>';
                    } else {
                        result.innerHTML = '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i>' + d.message + '</div>';
                    }
                })
                .catch(function() {
                    btnSync.disabled = false;
                    result.innerHTML = '<div class="alert alert-danger">Erreur de communication.</div>';
                });
        });
    }
}

// Démarrer — boucle jusqu'à ce que DOM + données soient prêts
initDnsManagerForm();

// Démarrer
window.initDnsManagerForm();
