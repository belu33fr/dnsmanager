<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;
use CronTask;

class Synclog extends CommonDBTM
{
    public static $rightname = 'config';

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
        $accounts = Account::getActiveAccounts();
        if (empty($accounts)) { $task->log('Aucun compte actif.'); return 0; }

        $hasError = false;
        foreach ($accounts as $account) {
            try {
                $importer = new Importer((int)$account['id']);
                $result   = $importer->sync();
                $task->log(sprintf('[%s] OK — %d domaines, %d enregistrements', $account['name'], $result['updated'], $result['records_added'] + $result['records_updated']));
                if (!empty($result['errors'])) { $hasError = true; }
            } catch (\Exception $e) {
                $hasError = true;
                $task->log('[' . $account['name'] . '] ERREUR : ' . $e->getMessage());
            }
            $task->addVolume(1);
        }
        return $hasError ? -1 : 1;
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
