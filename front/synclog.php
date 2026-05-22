<?php
/**
 * DNSManage - Journal de synchronisation
 */

include('../../../inc/includes.php');

Session::checkRight('config', READ);

Html::header(
    __('Journaux de synchronisation', 'dnsmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginDnsmanageMenu'
);

$logs     = PluginDnsmanageSynclog::getLastLogs(50);
$accounts = [];

// Précharger les noms de comptes
global $DB;
foreach ($DB->request(['FROM' => 'glpi_plugin_dnsmanager_accounts']) as $row) {
    $accounts[$row['id']] = $row['name'];
}

echo "<div class='container-fluid mt-3'>";
echo "<h2><i class='fas fa-history me-2'></i>" . __('Journaux de synchronisation', 'dnsmanager') . "</h2>";

if (empty($logs)) {
    echo "<div class='alert alert-info'>";
    echo "<i class='fas fa-info-circle me-2'></i>" . __('Aucune synchronisation enregistrée.', 'dnsmanager');
    echo "</div>";
} else {
    echo "<div class='table-responsive'>";
    echo "<table class='table table-striped table-sm'>";
    echo "<thead class='table-dark'><tr>";
    echo "<th>" . __('Compte', 'dnsmanager') . "</th>";
    echo "<th>" . __('Démarré', 'dnsmanager') . "</th>";
    echo "<th>" . __('Durée', 'dnsmanager') . "</th>";
    echo "<th>" . __('Statut', 'dnsmanager') . "</th>";
    echo "<th>" . __('Domaines', 'dnsmanager') . "</th>";
    echo "<th>" . __('Enregistrements', 'dnsmanager') . "</th>";
    echo "<th>" . __('Erreurs', 'dnsmanager') . "</th>";
    echo "</tr></thead><tbody>";

    foreach ($logs as $log) {
        $accountName = $accounts[$log['accounts_id']] ?? '#' . $log['accounts_id'];

        // Calcul durée
        $duration = '';
        if ($log['started_at'] && $log['finished_at']) {
            $diff = strtotime($log['finished_at']) - strtotime($log['started_at']);
            $duration = $diff . 's';
        }

        // Badge statut
        $statusBadge = match($log['status']) {
            'success' => '<span class="badge bg-success">OK</span>',
            'partial' => '<span class="badge bg-warning text-dark">Partiel</span>',
            'failed'  => '<span class="badge bg-danger">Échec</span>',
            'running' => '<span class="badge bg-info">En cours</span>',
            default   => '<span class="badge bg-secondary">' . htmlspecialchars($log['status']) . '</span>',
        };

        $hasError = !empty($log['error_log']);

        echo "<tr class='" . ($hasError ? 'table-warning' : '') . "'>";
        echo "<td>" . htmlspecialchars($accountName) . "</td>";
        echo "<td>" . Html::convDateTime($log['started_at']) . "</td>";
        echo "<td>" . $duration . "</td>";
        echo "<td>$statusBadge</td>";
        echo "<td>" . (int)$log['domains_added'] . " ajouté(s) / " . (int)$log['domains_updated'] . " MàJ</td>";
        echo "<td>" . (int)$log['records_added'] . " ajouté(s) / " . (int)$log['records_updated'] . " MàJ</td>";
        echo "<td>";
        if ($hasError) {
            echo "<button class='btn btn-sm btn-outline-danger' data-bs-toggle='collapse' data-bs-target='#log-{$log['id']}'>";
            echo "<i class='fas fa-exclamation-triangle me-1'></i>" . __('Voir', 'dnsmanager');
            echo "</button>";
            echo "<div class='collapse' id='log-{$log['id']}'>";
            echo "<pre class='mt-2 text-danger small'>" . htmlspecialchars($log['error_log']) . "</pre>";
            echo "</div>";
        } else {
            echo "<span class='text-success'>—</span>";
        }
        echo "</td>";
        echo "</tr>";
    }

    echo "</tbody></table></div>";
}

echo "</div>";

Html::footer();
