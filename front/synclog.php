<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Menu;
use GlpiPlugin\Dnsmanager\Synclog;
use Html;

include('../../../inc/includes.php');

// Même droit que celui qui affiche l'entrée du menu (plugin), pas le droit GLPI "config"
Session::checkRight(Account::$rightname, READ);

Html::header(
    Synclog::getTypeName(2),
    $_SERVER['PHP_SELF'],
    Menu::MENU_SECTOR,
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

Synclog::renderLogsTable($logs, $accounts, true);
echo "</div>";

Html::footer();
