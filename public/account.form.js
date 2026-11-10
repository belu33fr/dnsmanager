/* DNSManage - account.form.js
 * Chargé via PLUGIN_HOOKS['add_javascript'] (uniquement sur account.form.php)
 * Les données (dont les textes traduits) sont dans window.dnsmanagerData,
 * injecté par le template Twig.
 */

// ---------------------------------------------------------------------------
// Aide contextuelle ("?") : GLPI crée ces infobulles sans préciser de déclencheur,
// donc Bootstrap utilise "survol + focus" et l'infobulle peut rester figée. Sur cette
// page, on les recrée avec le seul déclencheur "survol" et on les ferme au clic.
// Idempotent. Le marquage data-bs-original-title empêche GLPI de les réinitialiser
// (son initialisation ignore les éléments qui portent déjà cet attribut).
// ---------------------------------------------------------------------------
window.dnsmanagerFixHelp = function () {
    if (!window.bootstrap || !bootstrap.Tooltip) return;
    document.querySelectorAll('.form-help[data-bs-toggle="tooltip"]').forEach(function (el) {
        if (el.dataset.dnsmanagerHelp === '1') return;
        el.dataset.dnsmanagerHelp = '1';

        var old = bootstrap.Tooltip.getInstance(el);
        if (old) old.dispose();

        var tip = new bootstrap.Tooltip(el, {
            trigger:   'hover',
            placement: el.getAttribute('data-bs-placement') || 'top',
            html:      el.getAttribute('data-bs-html') === 'true',
            title:     el.getAttribute('data-bs-title') || '',
        });
        el.setAttribute('data-bs-original-title', el.getAttribute('data-bs-title') || '');

        el.addEventListener('click',      function () { tip.hide(); });
        el.addEventListener('mouseleave', function () { tip.hide(); });
    });
};
(function () {
    function run() { window.dnsmanagerFixHelp(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
    // GLPI peut initialiser ses infobulles après nous : repasser quelques fois (idempotent)
    [300, 1200, 3000].forEach(function (ms) { setTimeout(run, ms); });
})();

// ---------------------------------------------------------------------------
// Indicateur dans l'onglet du navigateur : titre animé (spinner + pourcentage) et icône
// en anneau de progression, pour suivre la synchro depuis un autre onglet.
// ---------------------------------------------------------------------------
window.dnsmanagerTab = (function () {
    var FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    var labels = { sync: '', done: '', doneErr: '' };
    var origTitle = null, origIcons = null, createdLink = null, timer = null, frame = 0, pct = 0, indet = false;

    function draw(value, mode) {
        var c = document.createElement('canvas');
        c.width = c.height = 64;
        var x = c.getContext('2d');
        x.lineWidth = 10; x.lineCap = 'round';
        x.strokeStyle = '#d0d7de';
        x.beginPath(); x.arc(32, 32, 24, 0, Math.PI * 2); x.stroke();
        if (mode === 'done' || mode === 'warn') {
            var col = mode === 'done' ? '#2fb344' : '#f59f00';
            x.strokeStyle = col;
            x.beginPath(); x.arc(32, 32, 24, 0, Math.PI * 2); x.stroke();
            x.lineWidth = 8;
            x.beginPath();
            if (mode === 'done') { x.moveTo(20, 33); x.lineTo(29, 42); x.lineTo(45, 23); }
            else                 { x.moveTo(32, 20); x.lineTo(32, 36); x.moveTo(32, 44); x.lineTo(32, 45); }
            x.stroke();
        } else if (mode === 'spin') {
            x.strokeStyle = '#206bc4';
            var a0 = value * Math.PI / 5;
            x.beginPath(); x.arc(32, 32, 24, a0, a0 + Math.PI / 2); x.stroke();
        } else if (value > 0) {
            x.strokeStyle = '#206bc4';
            x.beginPath(); x.arc(32, 32, 24, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * value / 100); x.stroke();
        }
        return c.toDataURL('image/png');
    }
    function setIcon(url) {
        var list = Array.prototype.slice.call(document.querySelectorAll('link[rel~="icon"]'));
        if (origIcons === null) {
            origIcons = list.map(function (l) { return { el: l, href: l.href, type: l.getAttribute('type') }; });
        }
        if (list.length === 0) {
            createdLink = document.createElement('link');
            createdLink.rel = 'icon';
            document.head.appendChild(createdLink);
            list = [createdLink];
        }
        list.forEach(function (l) { l.type = 'image/png'; l.href = url; });
    }
    function restore() {
        if (timer) { clearInterval(timer); timer = null; }
        if (origTitle !== null) document.title = origTitle;
        if (origIcons) {
            origIcons.forEach(function (o) {
                o.el.href = o.href;
                if (o.type) o.el.setAttribute('type', o.type); else o.el.removeAttribute('type');
            });
        }
        if (createdLink) { createdLink.remove(); createdLink = null; }
        origTitle = null; origIcons = null;
    }
    function render() {
        document.title = FRAMES[frame % FRAMES.length] + ' ' + (indet ? '' : pct + ' % — ') + labels.sync;
    }

    return {
        labels: labels,
        start: function (indeterminate) {
            restore();
            indet = !!indeterminate;
            origTitle = document.title; pct = 0; frame = 0;
            render(); setIcon(draw(indet ? 0 : 0, indet ? 'spin' : 'run'));
            timer = setInterval(function () {
                frame++; render();
                if (indet) setIcon(draw(frame, 'spin'));
            }, 250);
        },
        update: function (value) { pct = value; render(); setIcon(draw(value, 'run')); },
        finish: function (mode) {
            if (timer) { clearInterval(timer); timer = null; }
            document.title = (mode === 'done' ? '✔ ' + labels.done : '⚠ ' + labels.doneErr);
            setIcon(draw(100, mode));
            // Restaurer le titre/l'icône d'origine quand l'utilisateur revient sur l'onglet
            if (!document.hidden) { setTimeout(restore, 6000); return; }
            var onVisible = function () {
                if (document.hidden) return;
                document.removeEventListener('visibilitychange', onVisible);
                setTimeout(restore, 4000);
            };
            document.addEventListener('visibilitychange', onVisible);
        },
    };
})();

/**
 * Initialisation par ZONE. La fiche d'un hébergeur est faite d'onglets natifs GLPI, chargés après la
 * page, chacun avec ses propres éléments :
 *   - #dns-main-root : fiche de base (statistiques, boutons Tester / Rechercher / Sync rapide) ;
 *   - #dns-auth-root : onglet Authentification (endpoint, identifiants, test avec les valeurs saisies).
 * Chaque zone n'est branchée qu'une fois, dès qu'elle apparaît dans la page (voir le démarrage en bas).
 */
window.initDnsManagerForm = function initDnsManagerForm() {
    var data = window.dnsmanagerData;
    if (!data) return;

    window.dnsmanagerFixHelp();

    var mainRoot = document.getElementById('dns-main-root');
    var authRoot = document.getElementById('dns-auth-root');
    var doMain   = !!(mainRoot && mainRoot.dataset.dnsmanagerInit !== '1');
    var doAuth   = !!(authRoot && authRoot.dataset.dnsmanagerInit !== '1');
    if (!doMain && !doAuth) return;
    // Garde anti double initialisation : sans elle, les écouteurs seraient branchés deux fois
    // et chaque clic lancerait deux requêtes en parallèle.
    if (doMain) mainRoot.dataset.dnsmanagerInit = '1';
    if (doAuth) authRoot.dataset.dnsmanagerInit = '1';

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

    function fetchJson(params) {
        return glpiFetch(syncUrl, params).then(function (r) {
            return r.text().then(function (txt) {
                try { return JSON.parse(txt); }
                catch (e) {
                    var err = new Error('HTTP ' + r.status);
                    err.detail = txt.substring(0, 300);
                    throw err;
                }
            });
        });
    }

    // Message d'erreur de communication avec le code HTTP (diagnostic) ; détail en console
    function commErr(e) {
        if (e && e.detail && window.console) console.error('DNSManage', e.message, e.detail);
        return t('commError') + (e && e.message ? ' (' + e.message + ')' : '');
    }

    function renderEndpoints(endpoints) {
        var sel = document.getElementById('dns-endpoint-select');
        var sec = document.getElementById('dns-endpoint-section');
        if (!sel) return;
        sel.innerHTML = '';
        var keys = Object.keys(endpoints || {});
        if (keys.length === 0) {
            // Fournisseur sans endpoint (ex. serveur DNS Windows) : le champ ne doit plus être
            // obligatoire, sinon le navigateur bloque l'envoi du formulaire sans rien afficher
            // (champ obligatoire invisible et sans valeur possible).
            sel.required = false;
            var none = document.createElement('option');
            none.value = ''; none.selected = true;
            sel.appendChild(none);
            if (sec) sec.style.display = 'none';
            return;
        }
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


    // -----------------------------------------------------------------------
    // Test de connexion : fiche de base (identifiants enregistrés) ou onglet
    // Authentification (valeurs saisies, avant enregistrement)
    // -----------------------------------------------------------------------
    function bindTest(btn, resultEl) {
        if (!btn || !resultEl) return;
        btn.addEventListener('click', function () {
            var params = { action: 'test_form', provider_type: data.providerType || '' };
            if (accountId) params.account_id = accountId;
            var eSel = document.getElementById('dns-endpoint-select');
            params.endpoint = (eSel && eSel.value) ? eSel.value : savedEndpoint;
            document.querySelectorAll('[name^="cred_"]').forEach(function (el) {
                if (el.value.trim()) params[el.name] = el.value;
            });
            btn.disabled = true;
            resultEl.innerHTML = '<div class="alert alert-info d-flex align-items-center">' + SPINNER
                + '<span>' + esc(t('testRunning')) + '</span></div>';
            fetchJson(params)
                .then(function (d) {
                    btn.disabled = false;
                    if (d.success) {
                        var diagHtml = d.message ? '<pre class="mt-2 mb-0 small">' + esc(d.message) + '</pre>' : '';
                        resultEl.innerHTML = '<div class="alert alert-success"><i class="ti ti-circle-check me-2"></i>'
                            + esc(t('testOk')) + diagHtml + '</div>';
                    } else {
                        resultEl.innerHTML = '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i><strong>'
                            + esc(t('failure')) + '</strong> ' + esc(d.message) + '</div>';
                    }
                })
                .catch(function (e) {
                    btn.disabled = false;
                    resultEl.innerHTML = '<div class="alert alert-danger">' + esc(commErr(e)) + '</div>';
                });
        });
    }

    // -----------------------------------------------------------------------
    // Zone « Authentification »
    // -----------------------------------------------------------------------
    function initAuth() {
        loadProvider(data.providerType || '');
        bindTest(authRoot.querySelector('.dns-test-btn'), authRoot.querySelector('.dns-result'));
    }

    // -----------------------------------------------------------------------
    // Zone « fiche de base » : statistiques et boutons
    // -----------------------------------------------------------------------
    function initMain() {
        var result = document.getElementById('dns-action-result');
        bindTest(document.getElementById('dns-btn-test'), result);

    // -----------------------------------------------------------------------
    // Sync rapide : domaine par domaine (une requête courte par domaine) avec
    // progression, au lieu d'une seule requête de plusieurs minutes qui finissait
    // par expirer et bloquait les autres onglets de la session.
    // -----------------------------------------------------------------------
    var btnSync     = document.getElementById('dns-btn-sync');
    var syncRunning = false;
    var cancelRequested = false;
    var tab         = window.dnsmanagerTab;

    // Bouton "Annuler" (délégation : la zone de résultat est reconstruite à chaque domaine).
    // L'annulation prend effet à la fin du domaine en cours (une requête serveur déjà
    // partie ne peut pas être interrompue proprement).
    result.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('#dns-btn-cancel') : null;
        if (!b || !syncRunning || cancelRequested) return;
        cancelRequested = true;
        b.disabled = true;
        var msg = document.getElementById('dns-sync-msg');
        if (msg) msg.textContent = t('syncCancelling');
    });
    // Éviter de quitter la page par mégarde pendant une synchronisation
    window.addEventListener('beforeunload', function (e) {
        if (syncRunning) { e.preventDefault(); e.returnValue = ''; }
    });
    tab.labels.sync    = t('tabSync');
    tab.labels.done    = t('tabDone');
    tab.labels.doneErr = t('tabDoneErr');

    function fmtDuration(ms) {
        var sec = Math.round(ms / 1000);
        if (sec < 60) return sec + ' s';
        return Math.floor(sec / 60) + ' min ' + ('0' + (sec % 60)).slice(-2) + ' s';
    }

    // Met à jour le bloc "Statistiques" (domaines GLPI, dates de synchronisation)
    function refreshStats() {
        fetchJson({ action: 'account_stats_local', account_id: accountId })
            .then(function (d) {
                if (!d || !d.success) return;
                var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
                set('dns-stat-glpi',    d.glpi_domains);
                set('dns-stat-lastrec', d.last_records_sync || '—');
                set('dns-stat-lastlog', d.last_log          || '—');
            })
            .catch(function () {});
    }

    if (btnSync) {
        btnSync.addEventListener('click', function () {
            if (syncRunning) return;
            syncRunning = true;
            btnSync.disabled = true;

            var domains = [], index = 0, okCount = 0, added = 0, updated = 0, errors = [], fails = 0;
            var t0 = Date.now(), apiMs = 0, apiCalls = 0, timings = [], changes = [];
            cancelRequested = false;
            tab.start();

            function showProgress(done, total, domain) {
                var pct = total ? Math.round(done / total * 100) : 0;
                // Fond neutre (lisible en thème clair comme sombre), barre cerclée d'un filet
                result.innerHTML =
                    '<div class="border rounded p-3">'
                    + '<div class="d-flex align-items-center mb-2">' + SPINNER
                    + '<span id="dns-sync-msg" class="flex-grow-1">' + esc(fmt(t('syncProgress'), { done: done, total: total, domain: domain })) + '</span>'
                    + '<strong class="ms-3">' + pct + ' %</strong>'
                    + '<button type="button" id="dns-btn-cancel" class="btn btn-primary ms-3"><i class="ti ti-x me-1"></i>' + esc(t('cancel')) + '</button>'
                    + '</div>'
                    + '<div class="progress" style="height:1rem;border:1px solid var(--tblr-border-color, #adb5bd);background:var(--tblr-bg-surface-secondary, #f1f3f5)">'
                    + '<div class="progress-bar bg-primary" role="progressbar" style="width:' + pct + '%;transition:width .3s"'
                    + ' aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"></div></div>'
                    + '</div>';
                tab.update(pct);
            }

            function finish(aborted, cancelled) {
                var elapsed = Date.now() - t0;
                btnSync.disabled = false;
                syncRunning = false;
                var bad  = aborted || cancelled || errors.length > 0;
                var head = cancelled
                    ? fmt(t('syncCancelled'), { done: index, total: domains.length })
                    : t('syncDone') + ' ' + fmt(t('syncSummary'), { domains: okCount, added: added, updated: updated });
                var html = '<div class="alert ' + (bad ? 'alert-warning' : 'alert-success') + '"><i class="ti ti-circle-check me-2"></i><strong>'
                    + esc(head) + '</strong>';
                if (cancelled) html += ' ' + esc(fmt(t('syncSummary'), { domains: okCount, added: added, updated: updated }));
                if (aborted) html += '<br>' + esc(t('syncAborted'));
                if (errors.length) html += '<br>' + esc(t('syncErrors')) + '<br>' + errors.map(esc).join('<br>');

                // Détail des enregistrements créés / modifiés (permet de repérer ceux qui changent à chaque passage)
                if (changes.length) {
                    html += '<div class="small mt-2"><strong>' + esc(t('syncChanges')) + '</strong><br>'
                        + changes.slice(0, 15).map(esc).join('<br>') + (changes.length > 15 ? '<br>…' : '') + '</div>';
                }

                // Chiffres de performance (diagnostic des lenteurs)
                var share = elapsed ? Math.round(apiMs / elapsed * 100) : 0;
                html += '<div class="small text-muted mt-2">' + esc(fmt(t('syncDuration'), {
                    time: fmtDuration(elapsed), avg: fmtDuration(okCount ? elapsed / okCount : 0), api: share, calls: apiCalls
                })) + '</div>';
                var slow = timings.slice().sort(function (x, y) { return y.ms - x.ms; }).slice(0, 3);
                if (slow.length) {
                    html += '<div class="small text-muted">' + esc(t('syncSlowest')) + ' '
                        + slow.map(function (x) { return esc(x.ref + ' (' + fmtDuration(x.ms) + ', ' + x.calls + ')'); }).join(', ') + '</div>';
                }
                result.innerHTML = html + '</div>';

                tab.finish(bad ? 'warn' : 'done');

                // Journal + rafraîchissement des statistiques de la fiche
                fetchJson({
                    action: 'sync_log', account_id: accountId, duration: Math.round(elapsed / 1000),
                    domains: okCount, records_added: added, records_updated: updated,
                    errors: errors.join('\n'), cancelled: cancelled ? 1 : 0,
                }).then(function () { refreshStats(); refreshProviderStats(); },
                         function () { refreshStats(); refreshProviderStats(); });
            }

            function next() {
                if (cancelRequested)         { finish(false, true); return; }
                if (index >= domains.length) { finish(false, false); return; }
                var ref = domains[index];
                showProgress(index, domains.length, ref);
                fetchJson({ action: 'sync_domain', account_id: accountId, domain_ref: ref })
                    .then(function (d) {
                        fails = 0;
                        if (d.success) {
                            okCount++;
                            added   += d.records_added   || 0;
                            updated += d.records_updated || 0;
                            apiMs    += d.api_ms    || 0;
                            apiCalls += d.api_calls || 0;
                            timings.push({ ref: ref, ms: d.duration_ms || 0, calls: d.api_calls || 0 });
                            (d.errors || []).forEach(function (e) { errors.push(ref + ' : ' + e); });
                            (d.changes || []).forEach(function (c) { changes.push(ref + ' — ' + c); });
                        } else {
                            errors.push(ref + ' : ' + d.message);
                        }
                        index++; next();
                    })
                    .catch(function (e) {
                        errors.push(ref + ' : ' + commErr(e));
                        fails++; index++;
                        if (fails >= 3) { finish(true, false); return; }
                        next();
                    });
            }

            result.innerHTML = '<div class="alert alert-info d-flex align-items-center">' + SPINNER
                + '<span>' + esc(t('syncStarting')) + '</span></div>';

            fetchJson({ action: 'get_sync_domains', account_id: accountId, restrict_entities: 1 })
                .then(function (d) {
                    if (!d.success) {
                        btnSync.disabled = false; syncRunning = false; tab.finish('warn');
                        result.innerHTML = '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i>' + esc(d.message) + '</div>';
                        return;
                    }
                    domains = d.domains || [];
                    if (domains.length === 0) {
                        btnSync.disabled = false; syncRunning = false; tab.finish('done');
                        result.innerHTML = '<div class="alert alert-info">' + esc(t('syncNothing')) + '</div>';
                        return;
                    }
                    next();
                })
                .catch(function (e) {
                    btnSync.disabled = false; syncRunning = false; tab.finish('warn');
                    result.innerHTML = '<div class="alert alert-danger">' + esc(commErr(e)) + '</div>';
                });
        });
    }

    // -----------------------------------------------------------------------
    // Statistiques : comptage chez l'hébergeur (à la demande)
    // -----------------------------------------------------------------------
    var btnStats = document.getElementById('dns-btn-stats');
    function refreshProviderStats() {
        var slot = document.getElementById('dns-stat-provider');
        var nl   = document.getElementById('dns-stat-notlinked');
        var chk  = document.getElementById('dns-stat-checked');
        if (!btnStats || !slot) return;
        btnStats.disabled = true;
        slot.innerHTML = SPINNER + esc(t('statsRunning'));
        fetchJson({ action: 'account_stats', account_id: accountId })
            .then(function (d) {
                btnStats.disabled = false;
                if (d.success) {
                    slot.textContent = d.provider_count;
                    if (nl)  nl.textContent  = d.not_linked;
                    if (chk) chk.textContent = d.checked || '';
                } else {
                    slot.innerHTML = '<span class="text-danger">' + esc(d.message) + '</span>';
                }
            })
            .catch(function (e) {
                btnStats.disabled = false;
                slot.innerHTML = '<span class="text-danger">' + esc(commErr(e)) + '</span>';
            });
    }
    if (btnStats) {
        btnStats.addEventListener('click', refreshProviderStats);
        // Chiffres jamais vérifiés ou anciens (> 10 min) : un appel léger à l'ouverture de la fiche
        if (data.providerStatAge === null || data.providerStatAge === undefined || data.providerStatAge > 600) {
            refreshProviderStats();
        }
    }

    // Recherche de nouveaux domaines : navigation vers la page d'import, qui interroge
    // l'hébergeur avant de s'afficher. On montre tout de suite que ça travaille (la durée
    // dépend d'un seul appel API : pas de pourcentage réel à afficher).
    var linkSearch = document.getElementById('dns-btn-sync-interactive');
    if (linkSearch) {
        linkSearch.addEventListener('click', function () {
            result.innerHTML =
                '<div class="border rounded p-3">'
                + '<div class="d-flex align-items-center mb-2">' + SPINNER + '<span>' + esc(t('searchRunning')) + '</span></div>'
                + '<div class="progress" style="height:1rem;border:1px solid var(--tblr-border-color, #adb5bd);background:var(--tblr-bg-surface-secondary, #f1f3f5)">'
                + '<div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" role="progressbar" style="width:100%"></div></div>'
                + '</div>';
            tab.labels.sync = t('tabSearch');
            tab.start(true);
        });
    }

    // Retour arrière du navigateur : la page peut être restaurée depuis le cache avec d'anciens
    // chiffres. On les rafraîchit dans ce cas.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { refreshStats(); refreshProviderStats(); }
    });
    try {
        var nav = performance.getEntriesByType('navigation')[0];
        if (nav && nav.type === 'back_forward') { refreshStats(); }
    } catch (e) { /* sans importance */ }
    }

    if (doAuth) initAuth();
    if (doMain) initMain();
};

// Démarrage. Les onglets natifs de GLPI sont chargés après la page et ne contiennent chacun qu'une
// partie des éléments : on retente donc l'initialisation à chaque modification de la page (la plupart du
// temps sans effet, car chaque zone n'est branchée qu'une fois).
(function () {
    var timer = null;
    function schedule() {
        if (timer) return;
        timer = setTimeout(function () { timer = null; window.initDnsManagerForm(); }, 60);
    }
    function start() {
        if (window.MutationObserver && document.body) {
            new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
        }
        schedule();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
