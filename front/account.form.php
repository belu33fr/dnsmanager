<?php
/**
 * DNSManage - Formulaire de création / édition d'un compte provider
 */

include('../../../inc/includes.php');

Session::checkRight('config', READ);

$id     = (int) ($_GET['id'] ?? 0);
$action = $_POST['action'] ?? '';

// ------------------------------------------------------------------
// Traitement POST : sauvegarde
// ------------------------------------------------------------------
if ($action === 'save') {
    Session::checkRight('config', UPDATE);

    $input = [
        'name'          => trim($_POST['name'] ?? ''),
        'provider_type' => trim($_POST['provider_type'] ?? ''),
        'endpoint'      => trim($_POST['endpoint'] ?? ''),
        'is_active'     => (int) ($_POST['is_active'] ?? 1),
        'entities_id'   => (int) ($_POST['entities_id'] ?? 0),
        'comment'       => trim($_POST['comment'] ?? ''),
    ];

    $providerType = $input['provider_type'];
    $fields       = PluginDnsmanageProviderFactory::getCredentialFields($providerType);
    $credentials  = [];
    foreach ($fields as $field) {
        $val = trim($_POST['cred_' . $field['key']] ?? '');
        if ($val !== '') {
            $credentials[$field['key']] = $val;
        }
    }

    if ($id) {
        PluginDnsmanageAccount::updateWithCredentials($id, $input, $credentials);
        Session::addMessageAfterRedirect(__('Compte mis à jour avec succès.', 'dnsmanager'), true, INFO);
    } else {
        $id = PluginDnsmanageAccount::createWithCredentials($input, $credentials);
        Session::addMessageAfterRedirect(__('Compte créé avec succès.', 'dnsmanager'), true, INFO);
    }

    Html::redirect(Plugin::getWebDir('dnsmanager') . '/front/account.form.php?id=' . $id);
    exit;
}

// ------------------------------------------------------------------
// Traitement POST : suppression
// ------------------------------------------------------------------
if ($action === 'delete') {
    Session::checkRight('config', UPDATE);

    global $DB;
    $DB->delete('glpi_plugin_dnsmanager_accounts', ['id' => $id]);
    Session::addMessageAfterRedirect(__('Compte supprimé.', 'dnsmanager'), true, INFO);
    Html::redirect(Plugin::getWebDir('dnsmanager') . '/front/account.php');
    exit;
}

// ------------------------------------------------------------------
// Chargement données existantes
// ------------------------------------------------------------------
$account     = [];
$credentials = [];

if ($id) {
    $data = PluginDnsmanageAccount::getWithCredentials($id);
    if (!$data) {
        Html::displayNotFoundError();
        exit;
    }
    $account     = $data['account'];
    $credentials = $data['credentials'];
}

$isNew            = empty($account);
$providers        = PluginDnsmanageProviderFactory::getAvailableProviders();
$selectedProvider = $account['provider_type'] ?? array_key_first($providers);
$currentEndpoint  = $account['endpoint'] ?? '';

// Données provider injectées en PHP → pas d'appel AJAX
$allProviderDataPhp = [];
foreach ($providers as $type => $label) {
    $allProviderDataPhp[$type] = [
        'endpoints' => PluginDnsmanageProviderFactory::getEndpoints($type),
        'fields'    => PluginDnsmanageProviderFactory::getCredentialFields($type),
    ];
}
$allProviderDataJson     = json_encode($allProviderDataPhp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$existingCredentialsJson = json_encode($credentials,        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$currentEndpointJs       = addslashes($currentEndpoint);
$webdir                  = Plugin::getWebDir('dnsmanager');

Html::header(
    $isNew ? __('Nouveau compte DNS', 'dnsmanager') : htmlspecialchars($account['name']),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginDnsmanageMenu'
);

?>
<div class="container-fluid mt-3" style="max-width:800px">

    <h2>
        <i class="ti ti-world me-2"></i>
        <?= $isNew ? __('Nouveau compte DNS provider', 'dnsmanager') : htmlspecialchars($account['name']) ?>
    </h2>

    <?php Html::displayMessageAfterRedirect(); ?>

    <form method="post" action="account.form.php" id="account-form">
        <input type="hidden" name="action" value="save">
        <?php if ($id): ?>
            <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>
        <!-- Token CSRF injecté via Session::getNewCSRFToken() -->
        <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">

        <!-- Informations générales -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <i class="ti ti-info-circle me-2"></i><?= __('Informations générales', 'dnsmanager') ?>
            </div>
            <div class="card-body">

                <div class="mb-3">
                    <label class="form-label fw-bold"><?= __('Nom du compte', 'dnsmanager') ?> *</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= htmlspecialchars($account['name'] ?? '') ?>"
                           placeholder="Mon compte OVH Pro">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold"><?= __('Provider', 'dnsmanager') ?> *</label>
                    <select name="provider_type" id="provider-select" class="form-select" required>
                        <?php foreach ($providers as $type => $label): ?>
                            <option value="<?= htmlspecialchars($type) ?>"
                                <?= $type === $selectedProvider ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3" id="endpoint-section">
                    <label class="form-label fw-bold"><?= __('Endpoint / Région', 'dnsmanager') ?></label>
                    <select name="endpoint" id="endpoint-select" class="form-select">
                        <!-- Rempli par JS via données PHP injectées -->
                    </select>
                    <div class="form-text text-muted">Choisissez la région correspondant à votre compte.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold"><?= __('Commentaire', 'dnsmanager') ?></label>
                    <textarea name="comment" class="form-control" rows="2"><?= htmlspecialchars($account['comment'] ?? '') ?></textarea>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1"
                           id="is_active" <?= ($account['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active"><?= __('Compte actif', 'dnsmanager') ?></label>
                </div>
            </div>
        </div>

        <!-- Credentials -->
        <div class="card mb-3">
            <div class="card-header bg-warning">
                <i class="ti ti-key me-2"></i><?= __('Authentification', 'dnsmanager') ?>
            </div>
            <div class="card-body" id="credentials-section">
                <p class="text-muted"><i class="ti ti-loader me-1"></i>Chargement...</p>
            </div>
        </div>

        <!-- Boutons -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <button type="submit" class="btn btn-success">
                <i class="ti ti-device-floppy me-1"></i><?= __('Enregistrer', 'dnsmanager') ?>
            </button>
            <a href="account.php" class="btn btn-secondary">
                <i class="ti ti-x me-1"></i><?= __('Annuler', 'dnsmanager') ?>
            </a>
            <button type="button" id="btn-test-connection" class="btn btn-info ms-auto">
                <i class="ti ti-plug me-1"></i><?= __('Tester la connexion', 'dnsmanager') ?>
            </button>
            <?php if ($id && Session::haveRight('config', UPDATE)): ?>
                <button type="button" class="btn btn-danger"
                        onclick="if(confirm('<?= __('Supprimer ce compte ?', 'dnsmanager') ?>')) document.getElementById('delete-form').submit()">
                    <i class="ti ti-trash me-1"></i><?= __('Supprimer', 'dnsmanager') ?>
                </button>
            <?php endif; ?>
        </div>

        <div id="test-result" class="mb-3"></div>
    </form>

    <?php if ($id): ?>
    <form id="delete-form" method="post" action="account.form.php">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">
    </form>
    <?php endif; ?>
</div>

<script>
// ------------------------------------------------------------------
// GLPI 11 / Symfony : le token CSRF est dans <meta name="glpi-csrf-token">
// On ne le lit PAS depuis un champ caché de formulaire car il peut être
// consommé ou périmé. On le relit depuis la meta à chaque requête AJAX.
// ------------------------------------------------------------------
function getGlpiCsrfToken() {
    const meta = document.querySelector('meta[name="glpi-csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function glpiFetch(url, params) {
    // GLPI 11 : le CSRF pour /ajax/ requiert les deux headers :
    // - X-Glpi-Csrf-Token  : le token lu depuis la meta tag
    // - X-Requested-With   : XMLHttpRequest (identifie la requête comme AJAX)
    const metaTag   = document.querySelector('meta[name="glpi-csrf-token"]');
    const csrfToken = metaTag ? metaTag.content : '';
    return fetch(url, {
        method:  'POST',
        headers: {
            'Content-Type':       'application/x-www-form-urlencoded',
            'X-Glpi-Csrf-Token':  csrfToken,
            'X-Requested-With':   'XMLHttpRequest',
        },
        body: new URLSearchParams(params).toString(),
    });
}

// ------------------------------------------------------------------
// Données provider injectées par PHP — aucun appel AJAX
// ------------------------------------------------------------------
const allProviderData     = <?= $allProviderDataJson ?>;
const existingCredentials = <?= $existingCredentialsJson ?>;
const currentEndpoint     = '<?= $currentEndpointJs ?>';
// URL absolue vers ajax/sync.php — CFG_GLPI.root_doc garantit le bon chemin
const SYNC_URL = CFG_GLPI.root_doc + '/plugins/dnsmanager/ajax/sync.php';

document.addEventListener('DOMContentLoaded', function () {
    loadProviderData(document.getElementById('provider-select').value);
    document.getElementById('provider-select').addEventListener('change', function () {
        loadProviderData(this.value);
    });
});

function loadProviderData(providerType) {
    const data = allProviderData[providerType];
    if (!data) return;
    renderEndpoints(data.endpoints || {});
    renderCredentials(data.fields   || []);
}

function renderEndpoints(endpoints) {
    const section = document.getElementById('endpoint-section');
    const sel     = document.getElementById('endpoint-select');
    sel.innerHTML = '';

    const keys = Object.keys(endpoints);
    if (keys.length === 0) {
        section.style.display = 'none';
        return;
    }
    section.style.display = '';
    keys.forEach(function (key) {
        const opt       = document.createElement('option');
        opt.value       = key;
        opt.textContent = endpoints[key];
        if (key === currentEndpoint) opt.selected = true;
        sel.appendChild(opt);
    });
    if (!sel.value && keys.length > 0) sel.value = keys[0];
}

function renderCredentials(fields) {
    const section   = document.getElementById('credentials-section');
    section.innerHTML = '';
    const hasExisting = Object.keys(existingCredentials).length > 0;

    if (!fields || fields.length === 0) {
        section.innerHTML = '<p class="text-muted">Aucun credential requis.</p>';
        return;
    }

    fields.forEach(function (field) {
        const div   = document.createElement('div');
        div.className = 'mb-3';

        const label       = document.createElement('label');
        label.className   = 'form-label fw-bold';
        label.textContent = field.label + (field.required ? ' *' : '');

        const input     = document.createElement('input');
        input.type      = (field.type === 'password') ? 'password' : 'text';
        input.name      = 'cred_' + field.key;
        input.className = 'form-control font-monospace';

        if (field.type === 'password') {
            input.placeholder = hasExisting ? '(inchangé — laisser vide pour conserver)' : '••••••••';
        } else if (existingCredentials[field.key]) {
            input.value = existingCredentials[field.key];
        }

        div.appendChild(label);
        div.appendChild(input);

        if (field.help) {
            const help       = document.createElement('div');
            help.className   = 'form-text text-muted';
            help.textContent = field.help;
            div.appendChild(help);
        }
        section.appendChild(div);
    });
}

// ------------------------------------------------------------------
// Bouton "Tester la connexion"
// ------------------------------------------------------------------
document.getElementById('btn-test-connection').addEventListener('click', function () {
    const form     = document.getElementById('account-form');
    const formData = new FormData(form);
    const params   = {};

    // Extraire manuellement les champs utiles (évite de re-soumettre action=save)
    params['action']        = 'test_form';
    params['provider_type'] = formData.get('provider_type') || '';
    params['endpoint']      = formData.get('endpoint') || '';
    if (formData.get('id')) params['account_id'] = formData.get('id');

    // Récupérer les credentials saisis
    document.querySelectorAll('[name^="cred_"]').forEach(function (el) {
        if (el.value.trim() !== '') params[el.name] = el.value;
    });

    const result  = document.getElementById('test-result');
    const btnIcon = this.querySelector('i');
    this.disabled = true;
    btnIcon.className = 'ti ti-loader me-1';
    result.innerHTML  = '<div class="alert alert-info"><i class="ti ti-loader me-2"></i>Test en cours...</div>';

    glpiFetch(SYNC_URL, params)
        .then(r => r.json())
        .then(data => {
            this.disabled = false;
            btnIcon.className = 'ti ti-plug me-1';
            if (data.success) {
                result.innerHTML = '<div class="alert alert-success"><i class="ti ti-circle-check me-2"></i>Connexion réussie !</div>';
            } else {
                result.innerHTML = '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i><strong>Échec :</strong> ' + data.message + '</div>';
            }
        })
        .catch(function () {
            this.disabled = false;
            btnIcon.className = 'ti ti-plug me-1';
            result.innerHTML = '<div class="alert alert-danger">Erreur de communication avec le serveur.</div>';
        }.bind(this));
});
</script>
<?php
Html::footer();
