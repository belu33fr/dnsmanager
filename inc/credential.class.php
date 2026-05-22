<?php
/**
 * DNSManage - Gestion des credentials (chiffrés en AES)
 */

class PluginDnsmanageCredential extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_credentials';
    }

    /**
     * Enregistre (ou met à jour) les credentials chiffrés d'un compte.
     *
     * @param int                  $accountId   ID du compte
     * @param array<string,string> $credentials Paires clé/valeur en clair
     */
    public static function saveForAccount(int $accountId, array $credentials): void
    {
        global $DB;

        foreach ($credentials as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $encrypted = PluginDnsmanageConfig::encrypt((string) $value);
            $now       = date('Y-m-d H:i:s');

            // Vérifier si la clé existe déjà
            $existing = $DB->request([
                'FROM'  => 'glpi_plugin_dnsmanager_credentials',
                'WHERE' => [
                    'accounts_id' => $accountId,
                    'cred_key'    => $key,
                ],
            ])->current();

            if ($existing) {
                $DB->update(
                    'glpi_plugin_dnsmanager_credentials',
                    ['cred_value' => $encrypted, 'date_mod' => $now],
                    ['id' => $existing['id']]
                );
            } else {
                $DB->insert('glpi_plugin_dnsmanager_credentials', [
                    'accounts_id' => $accountId,
                    'cred_key'    => $key,
                    'cred_value'  => $encrypted,
                    'date_mod'    => $now,
                ]);
            }
        }
    }

    /**
     * Récupère et déchiffre les credentials d'un compte.
     *
     * @param  int $accountId
     * @return array<string,string>  Paires clé/valeur en clair
     */
    public static function getForAccount(int $accountId): array
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_credentials',
            'WHERE' => ['accounts_id' => $accountId],
        ]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['cred_key']] = PluginDnsmanageConfig::decrypt($row['cred_value']);
        }

        return $result;
    }

    /**
     * Supprime tous les credentials d'un compte.
     */
    public static function deleteForAccount(int $accountId): void
    {
        global $DB;
        $DB->delete('glpi_plugin_dnsmanager_credentials', ['accounts_id' => $accountId]);
    }
}
