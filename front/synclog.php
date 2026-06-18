<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Synclog;
use Html;

include('../../../inc/includes.php');

Session::checkRight('config', READ);

Html::header(
    Synclog::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    Account::class,
    'synclog'
);

$logs     = Synclog::getLastLogs(50);
$accounts = [];
global $DB;
foreach ($DB->request(['FROM' => Account::getTable()]) as $row) {
    $accounts[$row['id']] = $row['name'];
}

echo "<div class='container-fluid mt-3'>";
echo "<h2><i class='ti ti-history me-2'></i>" . Synclog::getTypeName(2) . "</h2>";

if (empty($logs)) {
    echo "<div class='alert alert-info'>Aucune synchronisation enregistrée.</div>";
} else {
    echo "<div class='table-responsive'>";
    echo "<table class='table table-striped table-sm'><thead class='table-dark'><tr>";
    foreach (['Compte', 'Démarré', 'Durée', 'Statut', 'Domaines', 'Enregistrements', 'Erreurs'] as $col) {
        echo "<th>$col</th>";
    }
    echo "</tr></thead><tbody>";

    foreach ($logs as $log) {
        $accountName = $accounts[$log['accounts_id']] ?? '#' . $log['accounts_id'];
        $duration    = ($log['started_at'] && $log['finished_at'])
            ? (strtotime($log['finished_at']) - strtotime($log['started_at'])) . 's'
            : '';
        $badge = match($log['status']) {
            'success' => '<span class="badge bg-success">OK</span>',
            'partial' => '<span class="badge bg-warning text-dark">Partiel</span>',
            'failed'  => '<span class="badge bg-danger">Échec</span>',
            'running' => '<span class="badge bg-info">En cours</span>',
            default   => '<span class="badge bg-secondary">' . htmlspecialchars($log['status']) . '</span>',
        };

        echo "<tr>";
        echo "<td>" . htmlspecialchars($accountName) . "</td>";
        echo "<td>" . Html::convDateTime($log['started_at']) . "</td>";
        echo "<td>$duration</td>";
        echo "<td>$badge</td>";
        echo "<td>{$log['domains_added']} ajouté(s) / {$log['domains_updated']} MàJ</td>";
        echo "<td>{$log['records_added']} ajouté(s) / {$log['records_updated']} MàJ</td>";
        echo "<td>";
        if (!empty($log['error_log'])) {
            echo "<button class='btn btn-sm btn-outline-danger' data-bs-toggle='collapse' data-bs-target='#log-{$log['id']}'>";
            echo "<i class='ti ti-alert-triangle me-1'></i>Voir";
            echo "</button>";
            echo "<div class='collapse' id='log-{$log['id']}'>";
            echo "<pre class='mt-2 text-danger small'>" . htmlspecialchars($log['error_log']) . "</pre>";
            echo "</div>";
        } else {
            echo "<span class='text-success'>—</span>";
        }
        echo "</td></tr>";
    }
    echo "</tbody></table></div>";
}
echo "</div>";

Html::footer();
