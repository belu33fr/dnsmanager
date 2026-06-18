<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;

class Credential extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_credentials';
    }

    public static function saveForAccount(int $accountId, array $credentials): void
    {
        global $DB;
        foreach ($credentials as $key => $value) {
            if ($value === '' || $value === null) continue;
            $encrypted = Config::encrypt((string) $value);
            $now       = date('Y-m-d H:i:s');
            $existing  = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['accounts_id' => $accountId, 'cred_key' => $key],
            ])->current();
            if ($existing) {
                $DB->update(self::getTable(),
                    ['cred_value' => $encrypted, 'date_mod' => $now],
                    ['id' => $existing['id']]
                );
            } else {
                $DB->insert(self::getTable(), [
                    'accounts_id' => $accountId,
                    'cred_key'    => $key,
                    'cred_value'  => $encrypted,
                    'date_mod'    => $now,
                ]);
            }
        }
    }

    public static function getForAccount(int $accountId): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['accounts_id' => $accountId]]) as $row) {
            $result[$row['cred_key']] = Config::decrypt($row['cred_value']);
        }
        return $result;
    }
}
