/* DNSManage - account.form.js
 * Chargé via PLUGIN_HOOKS['add_javascript'] (uniquement sur account.form.php)
 * Les données (dont les textes traduits) sont dans window.dnsmanagerData,
 * injecté par le template Twig.
 */

// ---------------------------------------------------------------------------
// Aide contextuelle ("?") : fermeture au clic ailleurs et sur Échap.
// Branché une seule fois par page.
// ---------------------------------------------------------------------------
(function () {
    if (window.__dnsmanagerHelpFix) return;
    window.__dnsmanagerHelpFix = true;

    function hideAll(clicked) {
        if (!window.bootstrap) return;
        document.querySelectorAll('[data-bs-toggle="popover"], [data-bs-toggle="tooltip"]').forEach(function (el) {
            if (clicked && el.contains(clicked)) return;
            var kind = el.getAttribute('data-bs-toggle') === 'popover' ? bootstrap.Popover : bootstrap.Tooltip;
            var inst = kind && kind.getInstance(el);
            if (!inst) return;
            if (clicked && inst.tip && inst.tip.contains(clicked)) return;
            inst.hide();
        });
    }
    document.addEventListener('click', function (e) { hideAll(e.target); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideAll(null); });
})();

window.initDnsManagerForm = function initDnsManagerForm() {
    var data           = window.dnsmanagerData;
    var providerSelect = document.getElementById('dns-provider-select');

    // Attendre que les données ET les éléments DOM soient disponibles
    window.__dnsmanagerRetries = (window.__dnsmanagerRetries || 0) + 1;
    if ((!data || !providerSelect) && window.__dnsmanagerRetries < 100) {
        setTimeout(initDnsManagerForm, 150);
        return;
    }
    if (!data || !providerSelect) return;

    // Garde anti double initialisation : sans elle, les écouteurs seraient branchés
    // deux fois et chaque clic lancerait deux requêtes en parallèle.
    if (providerSelect.dataset.dnsmanagerInit === '1') return;
    providerSelect.dataset.dnsmanagerInit = '1';

    var providerData     = data.allProviderData     || {};
    var savedCredentials = data.existingCredentials || {};
    var savedEndpoint    = data.currentEndpoint     || '';
    var syncUrl          = CFG_GLPI.root_doc + '/plugins/dnsmanager/ajax/sync.php';
    var accountId        = data.accountId           || 0;
    var T                = data.i18n                || {};

    function t(key) { return T[key] !== undefined ? T[key] : key; }
    function fmt(str, vars) {
        return String(str).replace(/\{(\w+)\}/g, function (m, k) { return vars[k] !== undefined ? vars[k] : m; });
    }
    function esc(s) {
        return String(s === undefined || s === null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    var SPINNER = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>';

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
        empty.textContent = t('chooseEndpoint');
        empty.disabled    = true;
        empty.selected    = !savedEndpoint;
        sel.appendChild(empty);
        sel.required = true;

        keys.forEach(function (key) {
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
            section.innerHTML = '<p class="text-muted">' + esc(t('noCredentials')) + '</p>';
            return;
        }

        fields.forEach(function (field) {
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
                ? (hasExisting ? t('unchanged') : '••••••••') : '';
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
            note.innerHTML = '<i class="ti ti-info-circle me-2"></i>' + esc(t('keepPassword'));
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
    if (providerSelect.value) loadProvider(providerSelect.value);
    providerSelect.addEventListener('change', function () { loadProvider(this.value); });

    var result = document.getElementById('dns-action-result');

    // -----------------------------------------------------------------------
    // Test de connexion
    // -----------------------------------------------------------------------
    var btnTest = document.getElementById('dns-btn-test');
    if (btnTest) {
        btnTest.addEventListener('click', function () {
            var params = { action: 'test_form' };
            if (accountId) params.account_id = accountId;
            var pSel = document.getElementById('dns-provider-select');
            if (pSel) params.provider_type = pSel.value;
            var eSel = document.getElementById('dns-endpoint-select');
            if (eSel) params.endpoint = eSel.value;
            document.querySelectorAll('[name^="cred_"]').forEach(function (el) {
                if (el.value.trim()) params[el.name] = el.value;
            });
            btnTest.disabled = true;
            result.innerHTML = '<div class="alert alert-info d-flex align-items-center">' + SPINNER
                + '<span>' + esc(t('testRunning')) + '</span></div>';
            glpiFetch(syncUrl, params)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btnTest.disabled = false;
                    if (d.success) {
                        var diagHtml = d.message ? '<pre class="mt-2 mb-0 small">' + esc(d.message) + '</pre>' : '';
                        result.innerHTML = '<div class="alert alert-success"><i class="ti ti-circle-check me-2"></i>'
                            + esc(t('testOk')) + diagHtml + '</div>';
                    } else {
                        result.innerHTML = '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i><strong>'
                            + esc(t('failure')) + '</strong> ' + esc(d.message) + '</div>';
                    }
                })
                .catch(function () {
                    btnTest.disabled = false;
                    result.innerHTML = '<div class="alert alert-danger">' + esc(t('commError')) + '</div>';
                });
        });
    }

    // -----------------------------------------------------------------------
    // Sync rapide : domaine par domaine (une requête courte par domaine) avec
    // progression, au lieu d'une seule requête de plusieurs minutes qui finissait
    // par expirer et bloquait les autres onglets de la session.
    // -----------------------------------------------------------------------
    var btnSync     = document.getElementById('dns-btn-sync');
    var syncRunning = false;
    if (btnSync) {
        btnSync.addEventListener('click', function () {
            if (syncRunning) return;
            syncRunning = true;
            btnSync.disabled = true;

            var domains = [], index = 0, okCount = 0, recs = 0, errors = [], fails = 0;

            function showProgress(done, total, domain) {
                var pct = total ? Math.round(done / total * 100) : 0;
                result.innerHTML = '<div class="alert alert-info">'
                    + '<div class="d-flex align-items-center mb-2">' + SPINNER + '<span>'
                    + esc(fmt(t('syncProgress'), { done: done, total: total, domain: domain })) + '</span></div>'
                    + '<div class="progress"><div class="progress-bar" role="progressbar" style="width:' + pct + '%"'
                    + ' aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"></div></div></div>';
            }

            function finish(aborted) {
                btnSync.disabled = false;
                syncRunning = false;
                var cls = (aborted || errors.length) ? 'alert-warning' : 'alert-success';
                var html = '<div class="alert ' + cls + '"><i class="ti ti-circle-check me-2"></i><strong>'
                    + esc(t('syncDone')) + '</strong> '
                    + esc(fmt(t('syncSummary'), { domains: okCount, records: recs }));
                if (aborted) html += '<br>' + esc(t('syncAborted'));
                if (errors.length) html += '<br>' + esc(t('syncErrors')) + '<br>' + errors.map(esc).join('<br>');
                result.innerHTML = html + '</div>';
            }

            function next() {
                if (index >= domains.length) { finish(false); return; }
                var ref = domains[index];
                showProgress(index, domains.length, ref);
                glpiFetch(syncUrl, { action: 'sync_domain', account_id: accountId, domain_ref: ref })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        fails = 0;
                        if (d.success) {
                            okCount++;
                            recs += (d.records_added || 0) + (d.records_updated || 0);
                            (d.errors || []).forEach(function (e) { errors.push(ref + ' : ' + e); });
                        } else {
                            errors.push(ref + ' : ' + d.message);
                        }
                        index++; next();
                    })
                    .catch(function () {
                        errors.push(ref + ' : ' + t('commError'));
                        fails++; index++;
                        if (fails >= 3) { finish(true); return; }
                        next();
                    });
            }

            result.innerHTML = '<div class="alert alert-info d-flex align-items-center">' + SPINNER
                + '<span>' + esc(t('syncStarting')) + '</span></div>';

            glpiFetch(syncUrl, { action: 'get_sync_domains', account_id: accountId, restrict_entities: 1 })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) {
                        btnSync.disabled = false; syncRunning = false;
                        result.innerHTML = '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i>' + esc(d.message) + '</div>';
                        return;
                    }
                    domains = d.domains || [];
                    if (domains.length === 0) {
                        btnSync.disabled = false; syncRunning = false;
                        result.innerHTML = '<div class="alert alert-info">' + esc(t('syncNothing')) + '</div>';
                        return;
                    }
                    next();
                })
                .catch(function () {
                    btnSync.disabled = false; syncRunning = false;
                    result.innerHTML = '<div class="alert alert-danger">' + esc(t('commError')) + '</div>';
                });
        });
    }

    // -----------------------------------------------------------------------
    // Statistiques : comptage chez l'hébergeur (à la demande)
    // -----------------------------------------------------------------------
    var btnStats = document.getElementById('dns-btn-stats');
    if (btnStats) {
        btnStats.addEventListener('click', function () {
            var slot = document.getElementById('dns-stat-provider');
            var nl   = document.getElementById('dns-stat-notlinked');
            btnStats.disabled = true;
            slot.innerHTML = SPINNER + esc(t('statsRunning'));
            glpiFetch(syncUrl, { action: 'account_stats', account_id: accountId })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btnStats.disabled = false;
                    if (d.success) {
                        slot.textContent = d.provider_count;
                        if (nl) nl.textContent = d.not_linked;
                    } else {
                        slot.innerHTML = '<span class="text-danger">' + esc(d.message) + '</span>';
                    }
                })
                .catch(function () {
                    btnStats.disabled = false;
                    slot.innerHTML = '<span class="text-danger">' + esc(t('commError')) + '</span>';
                });
        });
    }
};

// Démarrer — boucle jusqu'à ce que DOM + données soient prêts
// (un seul appel : la garde dataset.dnsmanagerInit empêche de toute façon un double branchement)
window.initDnsManagerForm();
