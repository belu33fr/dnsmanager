<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Session;

class Synclog extends CommonDBTM
{
    public static $rightname = 'plugin_dnsmanager_account';

    public static function getTypeName($nb = 0): string
    {
        return _n('Journal de sync DNS', 'Journaux de sync DNS', $nb, 'dnsmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_synclogs';
    }

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'SyncAllAccounts' => ['description' => 'Synchronisation automatique DNS Hébergeurs'],
            default           => [],
        };
    }

    public static function cronSyncAllAccounts(CronTask $task): int
    {
        // Lancée à la main depuis l'interface web, la tâche s'exécute dans la requête de l'utilisateur et
        // garde le verrou de sa session pendant toute sa durée : ses autres onglets restent bloqués.
        // On le libère ; en ligne de commande (cron système), il n'y a pas de session.
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $accounts = Account::getActiveAccounts();
        if (empty($accounts)) { $task->log('Aucun compte actif.'); return 0; }

        $total = count($accounts);
        $ok    = 0;
        $ko    = 0;
        foreach ($accounts as $account) {
            // Throwable (et pas seulement Exception) : une erreur PHP grave sur UN compte ne doit
            // pas interrompre la tâche et empêcher le traitement des comptes suivants.
            try {
                $importer = new Importer((int)$account['id']);
                $importer->setOrigin('cron');
                $result   = $importer->sync();
                $task->log(sprintf('[%s] OK — %d domaines, %d enregistrements', $account['name'], $result['updated'], $result['records_added'] + $result['records_updated']));
                if (!empty($result['errors'])) {
                    $ko++;
                } else {
                    $ok++;
                }
            } catch (\Throwable $e) {
                $ko++;
                $task->log('[' . $account['name'] . '] ERREUR : ' . $e->getMessage());
            }
            $task->addVolume(1);
        }
        $task->log(sprintf('Hébergeurs traités : %d sur %d actifs (sans erreur : %d, avec erreur : %d)', $ok + $ko, $total, $ok, $ko));
        return $ko > 0 ? -1 : 1;
    }

    /** Onglet « Journal » de la fiche d'un hébergeur (lecture seule) */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Account && (int)$item->getID() > 0 && Session::haveRight(Account::$rightname, READ)) {
            return self::createTabEntry(__('Journal', 'dnsmanager'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof Account)) {
            return false;
        }
        global $DB;
        $logs = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['accounts_id' => (int)$item->getID()], 'ORDER' => 'started_at DESC', 'LIMIT' => 50]) as $row) {
            $logs[] = $row;
        }
        echo "<div class='container-fluid mt-3'>";
        self::renderLogsTable($logs, [], false);
        echo "</div>";
        return true;
    }

    /**
     * Tableau du journal par compte (page du journal et onglet de la fiche d'un hébergeur).
     *
     * @param array<int,string> $accounts noms des comptes, par identifiant (colonne « Compte » si demandée)
     */
    public static function renderLogsTable(array $logs, array $accounts, bool $withAccountColumn): void
    {
        if (empty($logs)) {
            echo "<div class='alert alert-info'>" . __('Aucune synchronisation enregistrée.', 'dnsmanager') . "</div>";
            return;
        }
        $cols = [__('Démarré', 'dnsmanager'), __('Durée', 'dnsmanager'), __('Statut', 'dnsmanager'),
                 __('Domaines', 'dnsmanager'), __('Enregistrements', 'dnsmanager'), __('Erreurs', 'dnsmanager')];
        if ($withAccountColumn) {
            array_unshift($cols, __('Compte', 'dnsmanager'));
        }
        echo "<div class='table-responsive'>";
        echo "<table class='table table-striped table-sm'><thead class='table-dark'><tr>";
        foreach ($cols as $col) {
            echo "<th>$col</th>";
        }
        echo "</tr></thead><tbody>";

        foreach ($logs as $log) {
            $duration = ($log['started_at'] && $log['finished_at'])
                ? (strtotime($log['finished_at']) - strtotime($log['started_at'])) . 's'
                : '';
            $badge = match ($log['status']) {
                'success' => '<span class="badge bg-success">OK</span>',
                'partial' => '<span class="badge bg-warning text-dark">Partiel</span>',
                'failed'  => '<span class="badge bg-danger">Échec</span>',
                'running' => '<span class="badge bg-info">En cours</span>',
                default   => '<span class="badge bg-secondary">' . htmlspecialchars($log['status']) . '</span>',
            };

            echo "<tr>";
            if ($withAccountColumn) {
                echo "<td>" . htmlspecialchars($accounts[$log['accounts_id']] ?? '#' . $log['accounts_id']) . "</td>";
            }
            echo "<td>" . \Html::convDateTime($log['started_at']) . "</td>";
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

    public static function getLastLogs(int $limit = 20): array
    {
        global $DB;
        $logs = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'started_at DESC', 'LIMIT' => $limit]) as $row) {
            $logs[] = $row;
        }
        return $logs;
    }
}
