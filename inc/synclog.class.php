<?php
/**
 * DNSManage - Journal de synchronisation et tâche CRON
 */

class PluginDnsmanageSynclog extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return _n('Journal de sync', 'Journaux de sync', $nb, 'dnsmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_synclogs';
    }

    // ------------------------------------------------------------------
    // Tâche CRON
    // ------------------------------------------------------------------

    /**
     * Déclare la tâche CRON auprès de GLPI.
     */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'SyncAllAccounts' => [
                'description' => __('Synchronisation automatique de tous les comptes DNS actifs', 'dnsmanager'),
            ],
            default => [],
        };
    }

    /**
     * Exécution de la tâche CRON : synchronise tous les comptes actifs.
     */
    public static function cronSyncAllAccounts(CronTask $task): int
    {
        $accounts = PluginDnsmanageAccount::getActiveAccounts();

        if (empty($accounts)) {
            $task->log('Aucun compte DNS actif trouvé.');
            return 0; // Rien à faire
        }

        $totalDomains = 0;
        $totalRecords = 0;
        $hasError     = false;

        foreach ($accounts as $account) {
            try {
                $importer = new PluginDnsmanageImporter((int) $account['id']);
                $result   = $importer->sync();

                $totalDomains += $result['added'] + $result['updated'];
                $totalRecords += $result['records_added'] + $result['records_updated'];

                $task->log(sprintf(
                    '[%s] Sync OK — %d domaines, %d enregistrements',
                    $account['name'],
                    $result['added'] + $result['updated'],
                    $result['records_added'] + $result['records_updated']
                ));

                if (!empty($result['errors'])) {
                    $hasError = true;
                    foreach ($result['errors'] as $err) {
                        $task->log('[' . $account['name'] . '] ERREUR : ' . $err);
                    }
                }
            } catch (\Exception $e) {
                $hasError = true;
                $task->log('[' . $account['name'] . '] ERREUR CRITIQUE : ' . $e->getMessage());
            }

            $task->addVolume(1);
        }

        $task->log(sprintf(
            'Bilan : %d domaine(s), %d enregistrement(s) traité(s).',
            $totalDomains,
            $totalRecords
        ));

        return $hasError ? -1 : 1; // 1 = succès, -1 = succès partiel
    }

    // ------------------------------------------------------------------
    // Lecture des logs
    // ------------------------------------------------------------------

    /**
     * Retourne les derniers logs pour un compte donné.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function getLastLogsForAccount(int $accountId, int $limit = 10): array
    {
        global $DB;

        $rows = $DB->request([
            'FROM'    => 'glpi_plugin_dnsmanager_synclogs',
            'WHERE'   => ['accounts_id' => $accountId],
            'ORDER'   => 'started_at DESC',
            'LIMIT'   => $limit,
        ]);

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = $row;
        }
        return $logs;
    }

    /**
     * Retourne le dernier log de tous les comptes.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function getLastLogs(int $limit = 20): array
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_synclogs',
            'ORDER' => 'started_at DESC',
            'LIMIT' => $limit,
        ]);

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = $row;
        }
        return $logs;
    }

    /**
     * Nettoie les logs plus anciens que N jours.
     */
    public static function purgeOldLogs(int $days = 30): int
    {
        global $DB;

        $date = date('Y-m-d H:i:s', strtotime("-$days days"));

        $DB->delete('glpi_plugin_dnsmanager_synclogs', [
            ['started_at' => ['<', $date]],
        ]);

        return $DB->affectedRows();
    }
}
