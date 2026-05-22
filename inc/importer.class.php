<?php
/**
 * DNSManage - Importeur générique domaines + enregistrements
 *
 * Gère l'import depuis n'importe quel provider vers les tables GLPI natives
 * (glpi_domains et glpi_domainrecords) en maintenant le mapping dans
 * les tables du plugin.
 */

class PluginDnsmanageImporter
{
    private PluginDnsmanageProviderInterface $provider;
    private array $account;

    /** Compteurs pour le log de synchronisation */
    private int $domainsAdded   = 0;
    private int $domainsUpdated = 0;
    private int $recordsAdded   = 0;
    private int $recordsUpdated = 0;
    private array $errors = [];

    public function __construct(int $accountId)
    {
        $data = PluginDnsmanageAccount::getWithCredentials($accountId);

        if (!$data) {
            throw new \InvalidArgumentException("Compte #$accountId introuvable.");
        }

        $this->account  = $data['account'];
        $this->provider = PluginDnsmanageProviderFactory::create(
            $data['account']['provider_type'],
            $data['credentials'],
            $data['account']['endpoint']
        );
    }

    // ------------------------------------------------------------------
    // Point d'entrée principal
    // ------------------------------------------------------------------

    /**
     * Lance la synchronisation complète pour ce compte.
     *
     * @return array{added: int, updated: int, records_added: int, records_updated: int, errors: array}
     */
    public function sync(): array
    {
        $logId = $this->startLog();

        try {
            $this->provider->testConnection();
        } catch (\Exception $e) {
            $this->finishLog($logId, 'failed', $e->getMessage());
            throw $e;
        }

        try {
            $domains = $this->provider->listDomains();

            foreach ($domains as $domainData) {
                try {
                    $glpiDomainId = $this->importDomain($domainData);
                    $this->importRecords($domainData['ref'], $glpiDomainId);
                } catch (\Exception $e) {
                    $this->errors[] = "[{$domainData['name']}] " . $e->getMessage();
                }
            }

            PluginDnsmanageAccount::updateLastSync((int) $this->account['id']);

            $status = empty($this->errors) ? 'success' : 'partial';
            $this->finishLog($logId, $status);
        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->finishLog($logId, 'failed', $e->getMessage());
        }

        return [
            'added'          => $this->domainsAdded,
            'updated'        => $this->domainsUpdated,
            'records_added'  => $this->recordsAdded,
            'records_updated'=> $this->recordsUpdated,
            'errors'         => $this->errors,
        ];
    }

    // ------------------------------------------------------------------
    // Import d'un domaine
    // ------------------------------------------------------------------

    /**
     * Importe ou met à jour un domaine dans GLPI.
     *
     * @param  array<string,string> $domainData  Données issues du provider
     * @return int                               ID GLPI du domaine
     */
    private function importDomain(array $domainData): int
    {
        global $DB;

        $accountId   = (int) $this->account['id'];
        $providerRef = $domainData['ref'];

        // Chercher si ce domaine est déjà mappé
        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => [
                'accounts_id'  => $accountId,
                'provider_ref' => $providerRef,
            ],
        ])->current();

        if ($existing) {
            // Mise à jour du domaine GLPI existant
            $glpiDomainId = (int) $existing['domains_id'];
            $this->updateGlpiDomain($glpiDomainId, $domainData);

            $DB->update('glpi_plugin_dnsmanager_domains', [
                'last_sync_at' => date('Y-m-d H:i:s'),
                'sync_status'  => 'ok',
                'sync_message' => null,
            ], ['id' => $existing['id']]);

            $this->domainsUpdated++;
            return $glpiDomainId;
        }

        // Création du domaine dans GLPI
        $glpiDomainId = $this->createGlpiDomain($domainData);

        $DB->insert('glpi_plugin_dnsmanager_domains', [
            'accounts_id'  => $accountId,
            'domains_id'   => $glpiDomainId,
            'provider_ref' => $providerRef,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'sync_status'  => 'ok',
        ]);

        $this->domainsAdded++;
        return $glpiDomainId;
    }

    /**
     * Crée un domaine GLPI natif.
     */
    private function createGlpiDomain(array $domainData): int
    {
        global $DB;

        // Chercher si le domaine existe déjà dans GLPI (par nom)
        $existingDomain = $DB->request([
            'FROM'  => 'glpi_domains',
            'WHERE' => ['name' => $domainData['name']],
        ])->current();

        if ($existingDomain) {
            return (int) $existingDomain['id'];
        }

        $domain = new Domain();
        $id = $domain->add([
            'name'        => $domainData['name'],
            'comment'     => $domainData['comment'] ?? '',
            'entities_id' => $this->account['entities_id'] ?? 0,
            'is_recursive'=> $this->account['is_recursive'] ?? 0,
            'is_active'   => 1,
        ]);

        if (!$id) {
            throw new \RuntimeException("Impossible de créer le domaine '{$domainData['name']}' dans GLPI.");
        }

        return (int) $id;
    }

    /**
     * Met à jour un domaine GLPI natif.
     */
    private function updateGlpiDomain(int $glpiDomainId, array $domainData): void
    {
        $domain = new Domain();
        $domain->update([
            'id'      => $glpiDomainId,
            'comment' => $domainData['comment'] ?? '',
        ]);
    }

    // ------------------------------------------------------------------
    // Import des enregistrements DNS d'une zone
    // ------------------------------------------------------------------

    /**
     * Importe ou met à jour tous les enregistrements d'une zone.
     */
    private function importRecords(string $zoneRef, int $glpiDomainId): void
    {
        global $DB;

        $records   = $this->provider->listRecords($zoneRef);
        $accountId = (int) $this->account['id'];

        foreach ($records as $recordData) {
            try {
                $this->importRecord($recordData, $glpiDomainId, $accountId);
            } catch (\Exception $e) {
                $this->errors[] = "[{$zoneRef}/{$recordData['type']} {$recordData['name']}] " . $e->getMessage();
            }
        }
    }

    /**
     * Importe ou met à jour un enregistrement DNS.
     */
    private function importRecord(array $recordData, int $glpiDomainId, int $accountId): void
    {
        global $DB;

        $providerRef = $recordData['ref'];

        // Chercher si cet enregistrement est déjà mappé
        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_records',
            'WHERE' => [
                'accounts_id'  => $accountId,
                'provider_ref' => $providerRef,
            ],
        ])->current();

        if ($existing) {
            $glpiRecordId = (int) $existing['domainrecords_id'];
            $this->updateGlpiRecord($glpiRecordId, $recordData);

            $DB->update('glpi_plugin_dnsmanager_records', [
                'last_sync_at' => date('Y-m-d H:i:s'),
                'sync_status'  => 'ok',
            ], ['id' => $existing['id']]);

            $this->recordsUpdated++;
            return;
        }

        // Création de l'enregistrement dans GLPI
        $glpiRecordId = $this->createGlpiRecord($recordData, $glpiDomainId);

        $DB->insert('glpi_plugin_dnsmanager_records', [
            'accounts_id'      => $accountId,
            'domainrecords_id' => $glpiRecordId,
            'provider_ref'     => $providerRef,
            'is_editable'      => 0,
            'last_sync_at'     => date('Y-m-d H:i:s'),
            'sync_status'      => 'ok',
        ]);

        $this->recordsAdded++;
    }

    /**
     * Crée un enregistrement DNS GLPI natif.
     */
    private function createGlpiRecord(array $recordData, int $glpiDomainId): int
    {
        // Résoudre l'ID du type DNS dans GLPI
        $domainRecordType = $this->resolveRecordType($recordData['type']);

        $record = new DomainRecord();
        $id = $record->add([
            'domains_id'       => $glpiDomainId,
            'name'             => $recordData['name'],
            'domainrecordtypes_id' => $domainRecordType,
            'ttl'              => $recordData['ttl'] ?? 0,
            'data'             => $recordData['target'],
            'comment'          => 'Importé via DNSManage depuis ' . $this->account['provider_type'],
            'entities_id'      => $this->account['entities_id'] ?? 0,
        ]);

        if (!$id) {
            throw new \RuntimeException("Impossible de créer l'enregistrement DNS '{$recordData['name']}' dans GLPI.");
        }

        return (int) $id;
    }

    /**
     * Met à jour un enregistrement DNS GLPI natif.
     */
    private function updateGlpiRecord(int $glpiRecordId, array $recordData): void
    {
        $domainRecordType = $this->resolveRecordType($recordData['type']);

        $record = new DomainRecord();
        $record->update([
            'id'                   => $glpiRecordId,
            'domainrecordtypes_id' => $domainRecordType,
            'ttl'                  => $recordData['ttl'] ?? 0,
            'data'                 => $recordData['target'],
        ]);
    }

    /**
     * Résout ou crée le type d'enregistrement DNS dans GLPI.
     */
    private function resolveRecordType(string $type): int
    {
        global $DB;

        $type = strtoupper(trim($type));

        $existing = $DB->request([
            'FROM'  => 'glpi_domainrecordtypes',
            'WHERE' => ['name' => $type],
        ])->current();

        if ($existing) {
            return (int) $existing['id'];
        }

        // Créer le type s'il n'existe pas
        $DB->insert('glpi_domainrecordtypes', [
            'name'    => $type,
            'comment' => 'Créé automatiquement par DNSManage',
        ]);

        return (int) $DB->insertId();
    }

    // ------------------------------------------------------------------
    // Journalisation
    // ------------------------------------------------------------------

    private function startLog(): int
    {
        global $DB;

        $DB->insert('glpi_plugin_dnsmanager_synclogs', [
            'accounts_id' => (int) $this->account['id'],
            'started_at'  => date('Y-m-d H:i:s'),
            'status'      => 'running',
        ]);

        return (int) $DB->insertId();
    }

    private function finishLog(int $logId, string $status, string $errorMsg = ''): void
    {
        global $DB;

        $DB->update('glpi_plugin_dnsmanager_synclogs', [
            'finished_at'     => date('Y-m-d H:i:s'),
            'status'          => $status,
            'domains_added'   => $this->domainsAdded,
            'domains_updated' => $this->domainsUpdated,
            'records_added'   => $this->recordsAdded,
            'records_updated' => $this->recordsUpdated,
            'error_log'       => $errorMsg ?: (empty($this->errors) ? null : implode("\n", $this->errors)),
        ], ['id' => $logId]);
    }
}
