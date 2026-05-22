<?php
/**
 * DNSManage - Gestion des comptes provider
 */

class PluginDnsmanageAccount extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return _n('Compte DNS', 'Comptes DNS', $nb, 'dnsmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_accounts';
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    /**
     * Crée un compte avec ses credentials chiffrés.
     *
     * @param  array<string,mixed>  $input         Données du compte (name, provider_type, endpoint, ...)
     * @param  array<string,string> $credentials   Paires clé/valeur en clair
     * @return int                                 ID du compte créé
     */
    public static function createWithCredentials(array $input, array $credentials): int
    {
        global $DB;

        $input['date_creation'] = date('Y-m-d H:i:s');
        $input['date_mod']      = date('Y-m-d H:i:s');

        $DB->insert('glpi_plugin_dnsmanager_accounts', $input);
        $accountId = $DB->insertId();

        PluginDnsmanageCredential::saveForAccount($accountId, $credentials);

        return (int) $accountId;
    }

    /**
     * Met à jour un compte et ses credentials.
     *
     * @param  int                  $id          ID du compte
     * @param  array<string,mixed>  $input       Données à mettre à jour
     * @param  array<string,string> $credentials Nouveaux credentials (vides = pas de changement)
     */
    public static function updateWithCredentials(int $id, array $input, array $credentials): void
    {
        global $DB;

        $input['date_mod'] = date('Y-m-d H:i:s');
        $DB->update('glpi_plugin_dnsmanager_accounts', $input, ['id' => $id]);

        if (!empty(array_filter($credentials))) {
            PluginDnsmanageCredential::saveForAccount($id, $credentials);
        }
    }

    /**
     * Retourne un compte par son ID avec ses credentials déchiffrés.
     *
     * @return array{account: array<string,mixed>, credentials: array<string,string>}|null
     */
    public static function getWithCredentials(int $id): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_accounts',
            'WHERE' => ['id' => $id],
        ])->current();

        if (!$row) {
            return null;
        }

        return [
            'account'     => $row,
            'credentials' => PluginDnsmanageCredential::getForAccount($id),
        ];
    }

    /**
     * Liste tous les comptes actifs.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function getActiveAccounts(): array
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_accounts',
            'WHERE' => ['is_active' => 1],
            'ORDER' => 'name ASC',
        ]);

        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = $row;
        }
        return $accounts;
    }

    /**
     * Met à jour la date de dernière synchronisation.
     */
    public static function updateLastSync(int $id): void
    {
        global $DB;
        $DB->update(
            'glpi_plugin_dnsmanager_accounts',
            ['last_sync_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
    }

    /**
     * Instancie le provider associé à ce compte.
     */
    public static function getProvider(int $id): PluginDnsmanageProviderInterface
    {
        $data = self::getWithCredentials($id);

        if (!$data) {
            throw new \RuntimeException("Compte #$id introuvable.");
        }

        return PluginDnsmanageProviderFactory::create(
            $data['account']['provider_type'],
            $data['credentials'],
            $data['account']['endpoint']
        );
    }

    // ------------------------------------------------------------------
    // Droits
    // ------------------------------------------------------------------

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight(self::$rightname, DELETE);
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }
}
