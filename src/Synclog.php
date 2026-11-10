<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;
use CronTask;

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
