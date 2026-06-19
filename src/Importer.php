<?php

namespace GlpiPlugin\Dnsmanager;

use Domain;
use DomainRecord;

class Importer
{
    private ProviderInterface $provider;
    private array $account;
    private array $domainWhitelist = [];
    private int   $renewalMonths   = 1;
    private int   $domainsUpdated  = 0;
    private int   $domainsSkipped  = 0;
    private int   $recordsAdded    = 0;
    private int   $recordsUpdated  = 0;
    private array $errors          = [];
    private array $skippedDomains  = [];
    private ?int  $internetTypeId  = null;

    private const TABLE_FACTURATION   = 'glpi_plugin_fields_domainfacturations';
    private const TABLE_ADMINISTRATIF = 'glpi_plugin_fields_domainadministratifdomaines';
    private const TABLE_COMPLEMENT     = 'glpi_plugin_fields_domaindomaineadministratifcomplements';

    private bool $interactive;

    public function __construct(int $accountId, bool $interactive = false)
    {
        $data = Account::getWithCredentials($accountId);
        if (!$data) throw new \InvalidArgumentException("Compte #$accountId introuvable.");

        $this->account       = $data['account'];
        $this->interactive   = $interactive;
        $this->provider      = ProviderFactory::create(
            $data['account']['provider_type'],
            $data['credentials'],
            $data['account']['endpoint']
        );
        $this->renewalMonths = max(1, (int)($data['account']['renewal_months'] ?? 1));

        $whitelist = trim($data['account']['domain_whitelist'] ?? '');
        if ($whitelist !== '') {
            $this->domainWhitelist = array_map('trim', array_filter(explode(',', strtolower($whitelist))));
        }
    }

    /**
     * Synchronise un seul domaine par sa référence provider
     */
    public function syncDomainByRef(string $providerRef): array
    {
        global $DB;

        $this->recordsAdded   = 0;
        $this->recordsUpdated = 0;
        $this->errors         = [];

        // Récupérer le mapping
        $mapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => [
                'accounts_id'  => (int)$this->account['id'],
                'provider_ref' => $providerRef,
            ],
        ])->current();

        if (!$mapping) {
            throw new \RuntimeException("Domaine '$providerRef' non trouvé dans le mapping.");
        }

        $glpiDomainId = (int)$mapping['domains_id'];

        $glpiDomain = $DB->request([
            'FROM'  => 'glpi_domains',
            'WHERE' => ['id' => $glpiDomainId, 'is_deleted' => 0],
        ])->current();

        if (!$glpiDomain) {
            throw new \RuntimeException("Domaine GLPI #$glpiDomainId introuvable ou en corbeille.");
        }

        // Sync du domaine
        $domainInfo = $this->provider->getDomainInfo($providerRef);
        $this->updateGlpiDomainDates($glpiDomainId, $glpiDomain, $domainInfo);
        $this->updateFacturation($glpiDomainId, $domainInfo);
        $this->updateContacts($glpiDomainId, $domainInfo);
        $this->updateAdministratifDomaine($glpiDomainId, (int)$glpiDomain['entities_id']);

        // Sync des enregistrements
        $this->importRecords($providerRef, $glpiDomainId);

        // Mettre à jour le mapping
        $DB->update('glpi_plugin_dnsmanager_domains', [
            'last_sync_at' => date('Y-m-d H:i:s'),
            'sync_status'  => 'ok',
        ], ['id' => (int)$mapping['id']]);

        return [
            'records_added'   => $this->recordsAdded,
            'records_updated' => $this->recordsUpdated,
            'errors'          => $this->errors,
        ];
    }

    public function sync(): array
    {
        global $DB;

        $logId = $this->startLog();
        try {
            $this->provider->testConnection();
        } catch (\Exception $e) {
            $this->finishLog($logId, 'failed', $e->getMessage());
            throw $e;
        }

        // Nettoyer les mappings de domaines orphelins
        // (domaines supprimés ou purgés de GLPI mais encore dans le cache)
        $accountId = (int)$this->account['id'];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['accounts_id' => $accountId],
        ]) as $mapping) {
            $glpiDomain = $DB->request([
                'FROM'  => 'glpi_domains',
                'WHERE' => ['id' => (int)$mapping['domains_id']],
            ])->current();

            if (!$glpiDomain) {
                // Domaine purgé → supprimer les records du cache pour ce domaine
                foreach ($DB->request([
                    'FROM'  => 'glpi_plugin_dnsmanager_records',
                    'WHERE' => ['accounts_id' => $accountId],
                ]) as $record) {
                    // Vérifier si le domainrecord appartient au domaine purgé
                    $dr = $DB->request([
                        'FROM'  => 'glpi_domainrecords',
                        'WHERE' => ['id' => $record['domainrecords_id'], 'domains_id' => (int)$mapping['domains_id']],
                    ])->current();
                    if ($dr || !$DB->request(['FROM' => 'glpi_domainrecords', 'WHERE' => ['id' => $record['domainrecords_id']]])->current()) {
                        $DB->delete('glpi_plugin_dnsmanager_records', ['id' => $record['id']]);
                    }
                }
                $DB->delete('glpi_plugin_dnsmanager_domains', ['id' => $mapping['id']]);
            }
        }

        try {
            foreach ($this->provider->listDomains() as $domainData) {
                try {
                    $glpiDomainId = $this->syncDomain($domainData);
                    if ($glpiDomainId === null) {
                        $this->domainsSkipped++;
                        $this->skippedDomains[] = $domainData['name'];
                        continue;
                    }
                    $this->importRecords($domainData['ref'], $glpiDomainId);
                } catch (\Exception $e) {
                    $this->errors[] = "[{$domainData['name']}] " . $e->getMessage();
                }
            }

            Account::updateLastSync((int)$this->account['id']);
            $this->finishLog($logId, empty($this->errors) ? 'success' : 'partial');
        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->finishLog($logId, 'failed', $e->getMessage());
        }

        return [
            'added'           => 0,
            'updated'         => $this->domainsUpdated,
            'skipped'         => $this->domainsSkipped,
            'skipped_domains' => $this->skippedDomains,
            'records_added'   => $this->recordsAdded,
            'records_updated' => $this->recordsUpdated,
            'errors'          => $this->errors,
        ];
    }

    private function syncDomain(array $domainData): ?int
    {
        global $DB;

        $domainName = strtolower(trim($domainData['name']));

        if (!empty($this->domainWhitelist) && !in_array($domainName, $this->domainWhitelist, true)) return null;

        $glpiDomain = $DB->request(['FROM' => 'glpi_domains', 'WHERE' => ['name' => $domainData['name'], 'is_deleted' => 0]])->current();
        if (!$glpiDomain) return null;

        if ((int)($glpiDomain['domaintypes_id'] ?? 0) !== $this->getInternetTypeId()) return null;

        $glpiDomainId = (int)$glpiDomain['id'];
        $accountId    = (int)$this->account['id'];
        $providerRef  = $domainData['ref'];

        $domainInfo = $this->provider->getDomainInfo($providerRef);
        $this->updateGlpiDomainDates($glpiDomainId, $glpiDomain, $domainInfo);
        $this->updateFacturation($glpiDomainId, $domainInfo);
        $this->updateContacts($glpiDomainId, $domainInfo);
        $this->updateAdministratifDomaine($glpiDomainId, (int)$glpiDomain['entities_id']);
        $this->updateComplement($glpiDomainId, (int)$glpiDomain['entities_id']);
        $this->updateGroups($glpiDomainId);

        $mapping = $DB->request(['FROM' => 'glpi_plugin_dnsmanager_domains', 'WHERE' => ['accounts_id' => $accountId, 'provider_ref' => $providerRef]])->current();
        if ($mapping) {
            $DB->update('glpi_plugin_dnsmanager_domains', ['domains_id' => $glpiDomainId, 'last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => 'ok', 'sync_message' => null], ['id' => $mapping['id']]);
        } else {
            $DB->insert('glpi_plugin_dnsmanager_domains', ['accounts_id' => $accountId, 'domains_id' => $glpiDomainId, 'provider_ref' => $providerRef, 'last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => 'ok']);
        }

        $this->domainsUpdated++;
        return $glpiDomainId;
    }

    private function updateGlpiDomainDates(int $glpiDomainId, array $glpiDomain, array $domainInfo): void
    {
        global $DB;
        $update = [];

        if (!empty($domainInfo['creation_date'])) {
            $newDate = $this->parseDate($domainInfo['creation_date']);
            $curDate = !empty($glpiDomain['date_domaincreation']) ? (new \DateTime($glpiDomain['date_domaincreation']))->format('Y-m-d') : '';
            if ($newDate && $newDate !== $curDate) $update['date_domaincreation'] = $newDate;
        }

        if (!empty($domainInfo['expiration_date'])) {
            $newDate = $this->parseDate($domainInfo['expiration_date']);
            $curDate = !empty($glpiDomain['date_expiration']) ? (new \DateTime($glpiDomain['date_expiration']))->format('Y-m-d') : '';
            if ($newDate && $newDate !== $curDate) $update['date_expiration'] = $newDate;
        }

        if (!empty($update)) $DB->update('glpi_domains', $update, ['id' => $glpiDomainId]);
    }

    private function updateFacturation(int $glpiDomainId, array $domainInfo): void
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE_FACTURATION) || empty($domainInfo['expiration_date'])) return;

        try {
            $renewalStr = (new \DateTime($domainInfo['expiration_date']))->modify("-{$this->renewalMonths} month")->format('Y-m-d');
            $existing   = $DB->request(['FROM' => self::TABLE_FACTURATION, 'WHERE' => ['items_id' => $glpiDomainId]])->current();

            if ($existing) {
                if (empty(trim($existing['datederenouvellementfield'] ?? ''))) {
                    $DB->update(self::TABLE_FACTURATION, ['datederenouvellementfield' => $renewalStr], ['items_id' => $glpiDomainId]);
                }
            } else {
                $DB->insert(self::TABLE_FACTURATION, [
                    'items_id'                  => $glpiDomainId,
                    'itemtype'                  => 'Domain',
                    'plugin_fields_containers_id' => 17,
                    'entities_id'               => $this->account['entities_id'] ?? 0,
                    'is_recursive'              => 0,
                    'refarticlefield'           => '',
                    'quantitfield'              => 1,
                    'datederenouvellementfield' => $renewalStr,
                ]);
            }
        } catch (\Exception $e) {
            $this->errors[] = "Facturation: " . $e->getMessage();
        }
    }

    private function updateContacts(int $glpiDomainId, array $domainInfo): void
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE_ADMINISTRATIF) || !$DB->tableExists('glpi_plugin_accounts_accounts')) return;

        $existing = $DB->request(['FROM' => self::TABLE_ADMINISTRATIF, 'WHERE' => ['items_id' => $glpiDomainId]])->current();
        $updates  = [];

        foreach ([
            'admin_contact'   => 'plugin_accounts_accounts_id_administrateurfield',
            'tech_contact'    => 'plugin_accounts_accounts_id_technicienfield',
            'billing_contact' => 'plugin_accounts_accounts_id_financierfield',
        ] as $infoKey => $fieldName) {
            $nichandle = trim($domainInfo[$infoKey] ?? '');
            if ($nichandle === '') continue;

            $account = $DB->request(['FROM' => 'glpi_plugin_accounts_accounts', 'WHERE' => ['name' => $nichandle, 'is_deleted' => 0]])->current();
            if (!$account) continue;

            $newId = (int)$account['id'];
            $curId = (int)($existing[$fieldName] ?? 0);
            if ($newId !== $curId) $updates[$fieldName] = $newId;
        }

        if (empty($updates)) return;

        if ($existing) {
            $DB->update(self::TABLE_ADMINISTRATIF, $updates, ['items_id' => $glpiDomainId]);
        } else {
            $updates['items_id']                    = $glpiDomainId;
            $updates['itemtype']                    = 'Domain';
            $updates['plugin_fields_containers_id'] = 35;
            $updates['entities_id']                 = $this->account['entities_id'] ?? 0;
            $updates['users_id_proprietairefield']  = 0;
            $DB->insert(self::TABLE_ADMINISTRATIF, $updates);
        }
    }

    /**
     * Met à jour le bloc "Administratif Domaine" (Fields) pour un domaine
     */
    /**
     * Met à jour les groupes du domaine si non déjà renseignés
     * type=1 → Groupe normal, type=2 → Groupe responsable (tech)
     */
    private function updateComplement(int $glpiDomainId, int $entitiesId): void
    {
        global $DB;
        $table = self::TABLE_COMPLEMENT;
        if (!$DB->tableExists($table)) return;
        $suppliersId = (int)($this->account['suppliers_id'] ?? 0);
        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['items_id' => $glpiDomainId]])->current();
        if ($existing) {
            $DB->update($table, ['suppliers_id_fournisseurfieldtwo' => $suppliersId], ['items_id' => $glpiDomainId]);
        } else {
            $DB->insert($table, [
                'items_id'                         => $glpiDomainId,
                'itemtype'                         => 'Domain',
                'plugin_fields_containers_id'      => 13,
                'entities_id'                      => $entitiesId,
                'is_recursive'                     => 0,
                'nepasrenouvelerfield'              => 0,
                'avertifield'                      => 0,
                'suppliers_id_fournisseurfieldtwo' => $suppliersId,
            ]);
        }
    }

    private function updateGroups(int $glpiDomainId): void
    {
        global $DB;

        $groupsNormal = (int)($this->account['groups_id']      ?? 0);
        $groupsTech   = (int)($this->account['groups_id_tech'] ?? 0);

        foreach ([
            1 => $groupsNormal,
            2 => $groupsTech,
        ] as $type => $groupId) {
            if (!$groupId) continue;

            // Vérifier si déjà renseigné
            $existing = $DB->request([
                'FROM'  => 'glpi_groups_items',
                'WHERE' => ['itemtype' => 'Domain', 'items_id' => $glpiDomainId, 'type' => $type],
            ])->current();

            if (!$existing) {
                $DB->insert('glpi_groups_items', [
                    'groups_id' => $groupId,
                    'itemtype'  => 'Domain',
                    'items_id'  => $glpiDomainId,
                    'type'      => $type,
                ]);
            }
        }
    }

    private function updateAdministratifDomaine(int $glpiDomainId, int $entitiesId): void
    {
        global $DB;

        $table = 'glpi_plugin_fields_domainadministratifdomaines';
        if (!$DB->tableExists($table)) return;

        // Toujours mettre à jour le compte d'hébergeur
        $data = [
            'plugin_dnsmanager_accounts_id_comptedhbergeurfield' => (int)$this->account['id'],
        ];

        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['items_id' => $glpiDomainId]])->current();

        // Respecter les champs verrouillés
        $lockMap = [
            'plugin_accounts_accounts_id_administrateurfield' => 'administrateurlockfield',
            'plugin_accounts_accounts_id_technicienfield'     => 'technicienlockfield',
            'plugin_accounts_accounts_id_financierfield'      => 'financierlockfield',
            'users_id_proprietairefield'                      => 'proprietairelockfield',
        ];

        $accountMap = [
            'plugin_accounts_accounts_id_administrateurfield' => 'plugin_accounts_accounts_id_administrateurfield',
            'plugin_accounts_accounts_id_technicienfield'     => 'plugin_accounts_accounts_id_technicienfield',
            'plugin_accounts_accounts_id_financierfield'      => 'plugin_accounts_accounts_id_financierfield',
            'users_id_proprietairefield'                      => 'users_id_proprietairefield',
        ];

        foreach ($lockMap as $field => $lockField) {
            // Ne pas écraser si verrouillé
            if ($existing && !empty($existing[$lockField])) continue;
            $data[$field] = (int)($this->account[$accountMap[$field]] ?? 0);
        }

        if ($existing) {
            $DB->update($table, $data, ['items_id' => $glpiDomainId]);
        } else {
            $DB->insert($table, array_merge($data, [
                'items_id'                    => $glpiDomainId,
                'itemtype'                    => 'Domain',
                'plugin_fields_containers_id' => 35,
                'entities_id'                 => $entitiesId,
                'is_recursive'                => 0,
                'administrateurlockfield'     => 0,
                'technicienlockfield'         => 0,
                'financierlockfield'          => 0,
                'proprietairelockfield'       => 0,
            ]));
        }
    }

    private function getInternetTypeId(): int
    {
        global $DB;
        if ($this->internetTypeId !== null) return $this->internetTypeId;

        $existing = $DB->request(['FROM' => 'glpi_domaintypes', 'WHERE' => ['name' => 'Internet']])->current();
        if ($existing) {
            $this->internetTypeId = (int)$existing['id'];
        } else {
            $DB->insert('glpi_domaintypes', ['name' => 'Internet', 'comment' => 'Créé par DNSManage']);
            $this->internetTypeId = (int)$DB->insertId();
        }
        return $this->internetTypeId;
    }

    private function importRecords(string $zoneRef, int $glpiDomainId): void
    {
        global $DB;
        $accountId   = (int)$this->account['id'];
        $ovhRefs     = [];

        // Importer tous les records OVH
        foreach ($this->provider->listRecords($zoneRef) as $recordData) {
            try {
                $this->importRecord($recordData, $glpiDomainId, $accountId);
                $ovhRefs[] = $recordData['ref'];
            } catch (\Exception $e) {
                $this->errors[] = "[{$zoneRef} {$recordData['type']}] " . $e->getMessage();
            }
        }

        // Supprimer (corbeille) les records GLPI absents d'OVH
        // Filtrer UNIQUEMENT les mappings du domaine courant via domainrecords_id
        if (!empty($ovhRefs)) {
            // Récupérer les IDs GLPI des records de ce domaine
            $domainRecordIds = [];
            foreach ($DB->request([
                'FROM'  => 'glpi_domainrecords',
                'WHERE' => ['domains_id' => $glpiDomainId, 'is_deleted' => 0],
            ]) as $dr) {
                $domainRecordIds[] = (int)$dr['id'];
            }

            if (!empty($domainRecordIds)) {
                foreach ($DB->request([
                    'FROM'  => 'glpi_plugin_dnsmanager_records',
                    'WHERE' => [
                        'accounts_id'      => $accountId,
                        'domainrecords_id' => $domainRecordIds,
                    ],
                ]) as $mapping) {
                    if (!in_array($mapping['provider_ref'], $ovhRefs, true)) {
                        // Mettre en corbeille dans GLPI
                        $record = new \DomainRecord();
                        $record->delete(['id' => (int)$mapping['domainrecords_id']]);
                        // Supprimer le mapping
                        $DB->delete('glpi_plugin_dnsmanager_records', ['id' => $mapping['id']]);
                    }
                }
            }
        }
    }

    private function importRecord(array $recordData, int $glpiDomainId, int $accountId): void
    {
        global $DB;
        $providerRef = $recordData['ref'];
        $existing    = $DB->request(['FROM' => 'glpi_plugin_dnsmanager_records', 'WHERE' => ['accounts_id' => $accountId, 'provider_ref' => $providerRef]])->current();

        if ($existing) {
            $domainRecordId = (int)$existing['domainrecords_id'];

            // Vérifier que le record GLPI existe encore (peut avoir été supprimé manuellement)
            $glpiRecord = $DB->request([
                'FROM'  => 'glpi_domainrecords',
                'WHERE' => ['id' => $domainRecordId, 'is_deleted' => 0],
            ])->current();

            if ($glpiRecord) {
                // Record existe → mise à jour si nécessaire
                $changed = $this->updateGlpiRecord($domainRecordId, $recordData);
                $status  = $changed ? 'updated_from_provider' : 'synced';
                $DB->update('glpi_plugin_dnsmanager_records',
                    ['last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => $status],
                    ['id' => $existing['id']]
                );
                if ($changed) $this->recordsUpdated++;
                return;
            }

            // Vérifier si le record est en poubelle (is_deleted=1)
            $deletedRecord = $DB->request([
                'FROM'  => 'glpi_domainrecords',
                'WHERE' => ['id' => $domainRecordId, 'is_deleted' => 1],
            ])->current();

            if ($deletedRecord) {
                // Sortir de la poubelle et mettre à jour
                $record = new \DomainRecord();
                $record->restore(['id' => $domainRecordId]);
                $this->updateGlpiRecord($domainRecordId, $recordData);
                $DB->update('glpi_plugin_dnsmanager_records',
                    ['last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => 'updated_from_provider'],
                    ['id' => $existing['id']]
                );
                $this->recordsUpdated++;
                return;
            }

            // Record introuvable même en poubelle → supprimer mapping orphelin et recréer
            $DB->delete('glpi_plugin_dnsmanager_records', ['id' => $existing['id']]);
            // Le code continue pour créer un nouveau record
        }

        $ttl       = (int)($recordData['ttl'] ?? 0);
        $typeId    = $this->resolveRecordType($recordData['type']);
        $recordName = $recordData['name'];
        $recordData_ = $recordData['target'];

        // Chercher un record GLPI existant sans mapping (orphelin)
        // pour éviter les doublons
        $orphan = $DB->request([
            'FROM'  => 'glpi_domainrecords',
            'WHERE' => [
                'domains_id'           => $glpiDomainId,
                'name'                 => $recordName,
                'domainrecordtypes_id' => $typeId,
                'data'                 => $recordData_,
                'is_deleted'           => 0,
            ],
        ])->current();

        if ($orphan) {
            // Record orphelin trouvé → créer juste le mapping
            $id = (int)$orphan['id'];
            $DB->insert('glpi_plugin_dnsmanager_records', [
                'accounts_id'      => $accountId,
                'domainrecords_id' => $id,
                'provider_ref'     => $providerRef,
                'is_editable'      => 0,
                'last_sync_at'     => date('Y-m-d H:i:s'),
                'sync_status'      => 'created_from_provider',
            ]);
            $this->recordsAdded++;
            return;
        }

        // Chercher aussi en corbeille
        $orphanDeleted = $DB->request([
            'FROM'  => 'glpi_domainrecords',
            'WHERE' => [
                'domains_id'           => $glpiDomainId,
                'name'                 => $recordName,
                'domainrecordtypes_id' => $typeId,
                'data'                 => $recordData_,
                'is_deleted'           => 1,
            ],
        ])->current();

        if ($orphanDeleted) {
            // Restaurer le record et créer le mapping
            $record = new \DomainRecord();
            $record->restore(['id' => $orphanDeleted['id']]);
            $id = (int)$orphanDeleted['id'];
            $DB->insert('glpi_plugin_dnsmanager_records', [
                'accounts_id'      => $accountId,
                'domainrecords_id' => $id,
                'provider_ref'     => $providerRef,
                'is_editable'      => 0,
                'last_sync_at'     => date('Y-m-d H:i:s'),
                'sync_status'      => 'updated_from_provider',
            ]);
            $this->recordsUpdated++;
            return;
        }

        $record = new \DomainRecord();
        $id = $record->add([
            'domains_id'           => $glpiDomainId,
            'name'                 => $recordName,
            'domainrecordtypes_id' => $typeId,
            'ttl'                  => $ttl,
            'data'                 => $recordData_,
            'comment'              => 'Importé via DNSManage',
            'entities_id'          => $this->account['entities_id'] ?? 0,
        ]);

        // GLPI peut avoir forcé TTL=3600 par défaut — on remet la bonne valeur
        if ($id && $ttl !== 3600) {
            global $DB;
            $DB->update('glpi_domainrecords', ['ttl' => $ttl], ['id' => $id]);
        }

        if (!$id) throw new \RuntimeException("Impossible de créer l'enregistrement '{$recordData['name']}'.");

        error_log("DNSManage INSERT mapping: domain=$glpiDomainId ref=$providerRef glpiId=$id accountId=$accountId");
        try {
            $DB->insert('glpi_plugin_dnsmanager_records', [
                'accounts_id'      => $accountId,
                'domainrecords_id' => (int)$id,
                'provider_ref'     => $providerRef,
                'is_editable'      => 0,
                'last_sync_at'     => date('Y-m-d H:i:s'),
                'sync_status'      => 'created_from_provider',
            ]);
            error_log("DNSManage INSERT mapping OK: insertId=" . $DB->insertId());
            $this->recordsAdded++;
        } catch (\Exception $e) {
            error_log("DNSManage INSERT mapping FAILED: domain=$glpiDomainId ref=$providerRef glpiId=$id err=" . $e->getMessage());
            $this->errors[] = "Mapping record $providerRef : " . $e->getMessage();
        }
    }

    private function updateGlpiRecord(int $glpiRecordId, array $recordData): bool
    {
        global $DB;
        $current = $DB->request([
            'FROM'  => 'glpi_domainrecords',
            'WHERE' => ['id' => $glpiRecordId, 'is_deleted' => 0],
        ])->current();
        if (!$current) return false;

        $newTypeId = $this->resolveRecordType($recordData['type']);
        $newTtl    = (int)($recordData['ttl'] ?? 0);
        $newData   = $recordData['target'];

        // Pas de changement → rien à faire
        if ((int)$current['domainrecordtypes_id'] === $newTypeId
            && (int)$current['ttl'] === $newTtl
            && $current['data'] === $newData) {
            return false;
        }

        $record = new \DomainRecord();
        $record->update([
            'id'                  => $glpiRecordId,
            'domainrecordtypes_id' => $newTypeId,
            'ttl'                 => $newTtl,
            'data'                => $newData,
        ]);
        return true;
    }

    private function resolveRecordType(string $type): int
    {
        global $DB;
        $type     = strtoupper(trim($type));
        $existing = $DB->request(['FROM' => 'glpi_domainrecordtypes', 'WHERE' => ['name' => $type]])->current();
        if ($existing) return (int)$existing['id'];
        $DB->insert('glpi_domainrecordtypes', ['name' => $type, 'comment' => 'Créé par DNSManage']);
        return (int)$DB->insertId();
    }

    private function parseDate(string $date): string
    {
        try { return (new \DateTime($date))->format('Y-m-d'); } catch (\Exception) { return ''; }
    }

    private function startLog(): int
    {
        global $DB;
        $DB->insert(Synclog::getTable(), ['accounts_id' => (int)$this->account['id'], 'started_at' => date('Y-m-d H:i:s'), 'status' => 'running']);
        return (int)$DB->insertId();
    }

    private function finishLog(int $logId, string $status, string $errorMsg = ''): void
    {
        global $DB;
        $notes = [];
        if (!empty($this->skippedDomains)) $notes[] = 'Ignorés : ' . implode(', ', $this->skippedDomains);
        if (!empty($this->errors))         $notes[] = implode("\n", $this->errors);
        if ($errorMsg)                     $notes[] = $errorMsg;

        $DB->update(Synclog::getTable(), [
            'finished_at'     => date('Y-m-d H:i:s'),
            'status'          => $status,
            'domains_added'   => 0,
            'domains_updated' => $this->domainsUpdated,
            'records_added'   => $this->recordsAdded,
            'records_updated' => $this->recordsUpdated,
            'error_log'       => empty($notes) ? null : implode("\n---\n", $notes),
        ], ['id' => $logId]);
    }
}
