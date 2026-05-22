<?php
/**
 * DNSManage - Liste des comptes provider
 */

include('../../../inc/includes.php');

Session::checkRight('config', READ);

Html::header(
    __('Comptes DNS provider', 'dnsmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginDnsmanageMenu'
);

$accounts  = PluginDnsmanageAccount::getActiveAccounts();
$providers = PluginDnsmanageProviderFactory::getAvailableProviders();
$webdir    = Plugin::getWebDir('dnsmanager');

?>
<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="ti ti-world me-2"></i><?= __('Comptes DNS provider', 'dnsmanager') ?></h2>
        <?php if (Session::haveRight('config', UPDATE)): ?>
            <a href="account.form.php" class="btn btn-primary">
                <i class="ti ti-plus me-1"></i><?= __('Ajouter un compte', 'dnsmanager') ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($accounts)): ?>
        <div class="alert alert-info">
            <i class="ti ti-info-circle me-2"></i>
            <?= __('Aucun compte DNS configuré. Commencez par ajouter un compte provider.', 'dnsmanager') ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th><?= __('Nom', 'dnsmanager') ?></th>
                        <th><?= __('Provider', 'dnsmanager') ?></th>
                        <th><?= __('Endpoint', 'dnsmanager') ?></th>
                        <th><?= __('Dernière sync', 'dnsmanager') ?></th>
                        <th><?= __('Statut', 'dnsmanager') ?></th>
                        <th><?= __('Actions', 'dnsmanager') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $account):
                    $providerLabel = $providers[$account['provider_type']] ?? $account['provider_type'];
                    $lastSync = $account['last_sync_at']
                        ? Html::convDateTime($account['last_sync_at'])
                        : '<span class="text-muted">' . __('Jamais', 'dnsmanager') . '</span>';
                ?>
                    <tr>
                        <td><a href="account.form.php?id=<?= $account['id'] ?>"><?= htmlspecialchars($account['name']) ?></a></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($providerLabel) ?></span></td>
                        <td><?= htmlspecialchars($account['endpoint']) ?></td>
                        <td><?= $lastSync ?></td>
                        <td>
                            <?php if ($account['is_active']): ?>
                                <span class="badge bg-success"><?= __('Actif', 'dnsmanager') ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= __('Inactif', 'dnsmanager') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-primary me-1 btn-sync"
                                    data-id="<?= $account['id'] ?>"
                                    title="<?= __('Synchroniser maintenant', 'dnsmanager') ?>">
                                <i class="ti ti-refresh"></i>
                            </button>
                            <button class="btn btn-sm btn-info me-1 btn-test"
                                    data-id="<?= $account['id'] ?>"
                                    title="<?= __('Tester la connexion', 'dnsmanager') ?>">
                                <i class="ti ti-plug"></i>
                            </button>
                            <?php if (Session::haveRight('config', UPDATE)): ?>
                                <a href="account.form.php?id=<?= $account['id'] ?>"
                                   class="btn btn-sm btn-warning me-1"
                                   title="<?= __('Modifier', 'dnsmanager') ?>">
                                    <i class="ti ti-edit"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div id="sync-result" class="mt-3"></div>
</div>

<script>
// URL absolue vers ajax/sync.php via CFG_GLPI.root_doc (GLPI 11)
const SYNC_URL = CFG_GLPI.root_doc + '/plugins/dnsmanager/ajax/sync.php';

// Dans GLPI 11 / Symfony, le token CSRF est dans la balise <meta name="glpi-csrf-token">
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
            'Content-Type':      'application/x-www-form-urlencoded',
            'X-Glpi-Csrf-Token': csrfToken,
            'X-Requested-With':  'XMLHttpRequest',
        },
        body: new URLSearchParams(params).toString(),
    });
}

document.querySelectorAll('.btn-sync').forEach(btn => {
    btn.addEventListener('click', function () {
        const id     = this.dataset.id;
        const result = document.getElementById('sync-result');
        const icon   = this.querySelector('i');

        icon.classList.add('ti-spin');
        this.disabled = true;
        result.innerHTML = '<div class="alert alert-info"><i class="ti ti-loader me-2"></i>Synchronisation en cours...</div>';

        glpiFetch(SYNC_URL, {action: 'sync', account_id: id})
            .then(r => r.json())
            .then(data => {
                icon.classList.remove('ti-spin');
                this.disabled = false;
                if (data.success) {
                    result.innerHTML = '<div class="alert alert-success">'
                        + '<i class="ti ti-circle-check me-2"></i>'
                        + '<strong>Synchronisation terminée.</strong> '
                        + data.added + ' domaine(s) ajouté(s), '
                        + data.updated + ' mis à jour, '
                        + data.records_added + ' enregistrement(s) ajouté(s), '
                        + data.records_updated + ' mis à jour.'
                        + (data.errors.length > 0 ? '<br><strong>Erreurs :</strong> ' + data.errors.join('<br>') : '')
                        + '</div>';
                } else {
                    result.innerHTML = '<div class="alert alert-danger">'
                        + '<i class="ti ti-circle-x me-2"></i><strong>Erreur :</strong> ' + data.message
                        + '</div>';
                }
            })
            .catch(() => {
                icon.classList.remove('ti-spin');
                this.disabled = false;
                result.innerHTML = '<div class="alert alert-danger">Erreur de communication avec le serveur.</div>';
            });
    });
});

document.querySelectorAll('.btn-test').forEach(btn => {
    btn.addEventListener('click', function () {
        const id     = this.dataset.id;
        const result = document.getElementById('sync-result');
        const icon   = this.querySelector('i');

        icon.classList.add('ti-spin');
        this.disabled = true;
        result.innerHTML = '<div class="alert alert-info"><i class="ti ti-loader me-2"></i>Test en cours...</div>';

        glpiFetch(SYNC_URL, {action: 'test', account_id: id})
            .then(r => r.json())
            .then(data => {
                icon.classList.remove('ti-spin');
                this.disabled = false;
                if (data.success) {
                    result.innerHTML = '<div class="alert alert-success"><i class="ti ti-circle-check me-2"></i>Connexion réussie !</div>';
                } else {
                    result.innerHTML = '<div class="alert alert-danger"><i class="ti ti-circle-x me-2"></i><strong>Connexion échouée :</strong> ' + data.message + '</div>';
                }
            })
            .catch(() => {
                icon.classList.remove('ti-spin');
                this.disabled = false;
                result.innerHTML = '<div class="alert alert-danger">Erreur de communication.</div>';
            });
    });
});
</script>
<?php
Html::footer();
