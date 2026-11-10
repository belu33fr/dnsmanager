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
    private string $origin         = 'manual';
    private array $changes         = [];
    private string $lastChangeDetail = '';
    private array $skippedDomains  = [];
    private ?int  $internetTypeId  = null;

    private const TABLE_FACTURATION   = 'glpi_plugin_fields_domainfacturations';
    private const TABLE_ADMINISTRATIF = 'glpi_plugin_fields_domainadministratifdomaines';
    private const TABLE_COMPLEMENT     = 'glpi_plugin_fields_domaindomaineadministratifcomplements';

    private bool $interactive;

    /** Restreint la synchro aux domaines dont l'entité est active en session (synchro manuelle uniquement) */
    private bool $restrictToActiveEntities = false;
    private array $allowedEntities = [];

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
     * Restreint la synchro aux domaines dont l'entité GLPI fait partie des
     * entités actives de la session courante (+ sous-entités si sélectionné).
     * Réservé à la synchro manuelle déclenchée par un utilisateur — le CRON
     * planifié doit toujours traiter l'intégralité des domaines du compte.
     */
    /** Origine de la synchro, tracée dans le journal par domaine : cron, quick, import, manual */
    public function setOrigin(string $origin): self
    {
        $this->origin = $origin;
        return $this;
    }

    /** Compteurs avant le traitement d'un domaine (pour en déduire ce que CE domaine a changé) */
    private function snapshot(): array
    {
        return ['a' => $this->recordsAdded, 'u' => $this->recordsUpdated, 'e' => count($this->errors)];
    }

    /** Trace un passage dans le journal du domaine ; ne doit jamais faire échouer la synchro */
    private function logDomainRun(int $glpiDomainId, array $snap, ?string $forceStatus = null): void
    {
        try {
            $errors = array_slice($this->errors, (int)$snap['e']);
            $status = $forceStatus ?? ($errors === [] ? 'success' : 'partial');
            DomainLog::record(
                $glpiDomainId,
                (int)$this->account['id'],
                $this->origin,
                $status,
                $this->recordsAdded - (int)$snap['a'],
                $this->recordsUpdated - (int)$snap['u'],
                $errors === [] ? null : implode("\n", $errors)
            );
        } catch (\Throwable $e) {
            // le journal est un confort : on ignore sa propre erreur
        }
    }

    public function restrictToActiveEntities(): self
    {
        $this->restrictToActiveEntities = true;
        $this->allowedEntities          = \Session::getActiveEntities();
        return $this;
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
        $this->changes        = [];

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

        $snap = $this->snapshot();
        try {
            // Sync du domaine
            $domainInfo = $this->provider->getDomainInfo($providerRef);
            $this->updateGlpiDomainDates($glpiDomainId, $glpiDomain, $domainInfo);
            $this->updateFacturation($glpiDomainId, $domainInfo);
            $registrarContacts = $this->resolveRegistrarContacts($domainInfo);
            $this->updateAdministratifDomaine($glpiDomainId, (int)$glpiDomain['entities_id'], $registrarContacts);
            $this->updateComplement($glpiDomainId, (int)$glpiDomain['entities_id'], $providerRef);
            $this->updateGroups($glpiDomainId);

            // Sync des enregistrements
            $this->importRecords($providerRef, $glpiDomainId);

            // Mettre à jour le mapping
            $DB->update('glpi_plugin_dnsmanager_domains', [
                'last_sync_at' => date('Y-m-d H:i:s'),
                'sync_status'  => 'ok',
            ], ['id' => (int)$mapping['id']]);
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->logDomainRun($glpiDomainId, $snap, 'failed');
            throw $e;
        }
        $this->logDomainRun($glpiDomainId, $snap);

        return [
            'records_added'   => $this->recordsAdded,
            'records_updated' => $this->recordsUpdated,
            'errors'          => $this->errors,
            'changes'         => $this->changes,
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
                $glpiDomainId = null;
                $snap         = $this->snapshot();
                try {
                    $glpiDomainId = $this->syncDomain($domainData);
                    if ($glpiDomainId === null) {
                        $this->domainsSkipped++;
                        $this->skippedDomains[] = $domainData['name'];
                        continue;
                    }
                    $this->importRecords($domainData['ref'], $glpiDomainId);
                    $this->logDomainRun($glpiDomainId, $snap);
                } catch (\Exception $e) {
                    $this->errors[] = "[{$domainData['name']}] " . $e->getMessage();
                    if ($glpiDomainId !== null) {
                        $this->logDomainRun($glpiDomainId, $snap, 'failed');
                    }
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

        // Le domaine GLPI est cherché par nom ET par type : un domaine externe (Internet) et un domaine
        // interne (DNS AD) peuvent porter le même nom sans se confondre.
        $glpiDomain = $DB->request(['FROM' => 'glpi_domains', 'WHERE' => [
            'name' => $domainData['name'], 'is_deleted' => 0, 'domaintypes_id' => $this->getInternetTypeId(),
        ]])->current();
        if (!$glpiDomain) return null;

        // Synchro manuelle restreinte à l'entité active : ignorer les domaines hors périmètre
        if ($this->restrictToActiveEntities && !in_array((int)$glpiDomain['entities_id'], $this->allowedEntities, true)) {
            return null;
        }

        $glpiDomainId = (int)$glpiDomain['id'];
        $accountId    = (int)$this->account['id'];
        $providerRef  = $domainData['ref'];

        $domainInfo = $this->provider->getDomainInfo($providerRef);
        $this->updateGlpiDomainDates($glpiDomainId, $glpiDomain, $domainInfo);
        $this->updateFacturation($glpiDomainId, $domainInfo);
        $registrarContacts = $this->resolveRegistrarContacts($domainInfo);
        $this->updateAdministratifDomaine($glpiDomainId, (int)$glpiDomain['entities_id'], $registrarContacts);
        $this->updateComplement($glpiDomainId, (int)$glpiDomain['entities_id'], $providerRef);
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

    /**
     * Résout les contacts registrar (nichandle OVH, etc.) vers des IDs
     * GlpiPlugin\Accounts\Account. Crée automatiquement le compte s'il
     * n'existe pas encore (nom = identifiant registrar). Ne modifie
     * jamais la table domaine directement — retourne juste la résolution,
     * appliquée ensuite par updateAdministratifDomaine() dans le respect
     * des verrous utilisateur.
     *
     * @return array<string,int> fieldName => accounts_id résolu
     */
    /** Cache mémoire (le temps d'une synchro) pour éviter de requêter à chaque contact résolu */
    private ?int $cachedContactStateId = null;
    private ?int $cachedContactTypeId  = null;

    /** Statut "Actif" (glpi_plugin_accounts_accountstates), résolu par nom pour rester portable */
    private function getDefaultAccountStateId(): int
    {
        global $DB;
        if ($this->cachedContactStateId !== null) return $this->cachedContactStateId;

        $row = $DB->request(['FROM' => 'glpi_plugin_accounts_accountstates', 'WHERE' => ['name' => 'Actif']])->current();
        $this->cachedContactStateId = $row ? (int)$row['id'] : 0;
        return $this->cachedContactStateId;
    }

    /** Type "Fournisseur" (glpi_plugin_accounts_accounttypes), résolu par nom pour rester portable */
    private function getDefaultAccountTypeId(): int
    {
        global $DB;
        if ($this->cachedContactTypeId !== null) return $this->cachedContactTypeId;

        $row = $DB->request(['FROM' => 'glpi_plugin_accounts_accounttypes', 'WHERE' => ['name' => PluginConfig::getContactAccountType()]])->current();
        $this->cachedContactTypeId = $row ? (int)$row['id'] : 0;
        return $this->cachedContactTypeId;
    }

    private function resolveRegistrarContacts(array $domainInfo): array
    {
        global $DB;
        $resolved = [];

        if (!$DB->tableExists('glpi_plugin_accounts_accounts')) return $resolved;

        $map = [
            'admin_contact'   => 'plugin_accounts_accounts_id_administrateurfield',
            'tech_contact'    => 'plugin_accounts_accounts_id_technicienfield',
            'billing_contact' => 'plugin_accounts_accounts_id_financierfield',
        ];

        foreach ($map as $infoKey => $fieldName) {
            $identity = trim($domainInfo[$infoKey] ?? '');
            if ($identity === '') continue;
            $login = trim($domainInfo[$infoKey . '_login'] ?? '');

            $account = $DB->request([
                'FROM'  => 'glpi_plugin_accounts_accounts',
                'WHERE' => ['name' => $identity, 'is_deleted' => 0],
            ])->current();

            if ($account) {
                $resolved[$fieldName] = (int)$account['id'];
                continue;
            }

            // Compte absent : le créer automatiquement (pas de clé de chiffrement requise
            // tant qu'aucun identifiant/mot de passe n'est fourni)
            try {
                $accountClass = '\\GlpiPlugin\\Accounts\\Account';
                if (class_exists($accountClass)) {
                    $newAccount = new $accountClass();
                    $addData = [
                        'name'                             => $identity,
                        'entities_id'                      => (int)($this->account['entities_id'] ?? 0),
                        'comment'                          => 'Créé automatiquement depuis le contact registrar (DNSManage)',
                        'plugin_accounts_accountstates_id'  => $this->getDefaultAccountStateId(),
                        'plugin_accounts_accounttypes_id'   => $this->getDefaultAccountTypeId(),
                        'groups_id'                         => (int)($this->account['contact_groups_id'] ?? 0),
                    ];
                    if ($login !== '') {
                        $addData['login'] = $login;
                    }
                    $newId = $newAccount->add($addData);
                    if ($newId) {
                        $resolved[$fieldName] = (int)$newId;
                        error_log("DNSManage: compte contact '$identity' créé avec succès, id=$newId");
                    } else {
                        error_log("DNSManage: échec silencieux création compte contact '$identity' - add() a retourné une valeur fausse (droits, champ requis manquant ?)");
                    }
                } else {
                    error_log("DNSManage: classe $accountClass introuvable - plugin Accounts absent ou inactif ?");
                }
            } catch (\Exception $e) {
                error_log('DNSManage: échec création compte contact "' . $identity . '" - ' . $e->getMessage());
            }
        }

        return $resolved;
    }

    private function updateComplement(int $glpiDomainId, int $entitiesId, string $providerRef = ''): void
    {
        global $DB;
        $table = self::TABLE_COMPLEMENT;
        if (!$DB->tableExists($table)) return;

        $suppliersId = (int)($this->account['suppliers_id'] ?? 0);

        // TTL par défaut de la zone (SOA TTL côté OVH, null si non supporté par le provider)
        $ttlDefault = null;
        if ($providerRef !== '' && $DB->fieldExists($table, 'ttldfautfield')) {
            try {
                $ttlDefault = $this->provider->getZoneDefaultTtl($providerRef);
            } catch (\Exception) {
                $ttlDefault = null;
            }
        }

        $data = ['suppliers_id_fournisseurfieldtwo' => $suppliersId];
        if ($ttlDefault !== null) {
            $data['ttldfautfield'] = $ttlDefault;
        }

        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['items_id' => $glpiDomainId]])->current();
        if ($existing) {
            $DB->update($table, $data, ['items_id' => $glpiDomainId]);
        } else {
            $DB->insert($table, array_merge([
                'items_id'                     => $glpiDomainId,
                'itemtype'                     => 'Domain',
                'plugin_fields_containers_id'  => 13,
                'entities_id'                  => $entitiesId,
                'is_recursive'                 => 0,
                'nepasrenouvelerfield'          => 0,
                'avertifield'                  => 0,
            ], $data));
        }
    }

    /**
     * Met à jour les groupes du domaine si non déjà renseignés
     * type=1 → Groupe normal, type=2 → Groupe responsable (tech)
     */
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

    /**
     * Met à jour le bloc "Administratif Domaine" (Fields) pour un domaine.
     * Priorité par champ (sauf compte d'hébergeur, toujours mis à jour) :
     *   1. Verrouillé (checkbox lock) → ne jamais toucher
     *   2. Contact registrar résolu (voir resolveRegistrarContacts()) → l'utiliser
     *   3. Sinon → valeur par défaut du compte provider
     *
     * @param array<string,int> $registrarContacts fieldName => accounts_id résolu depuis le registrar
     */
    private function updateAdministratifDomaine(int $glpiDomainId, int $entitiesId, array $registrarContacts = []): void
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
            // 1. Ne pas écraser si verrouillé
            if ($existing && !empty($existing[$lockField])) continue;

            // 2. Priorité au contact registrar résolu s'il existe pour ce champ
            if (isset($registrarContacts[$field])) {
                $data[$field] = $registrarContacts[$field];
                continue;
            }

            // 3. Sinon, valeur par défaut du compte provider
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

    /**
     * Identifiant du type de domaine GLPI géré par ce compte : « Internet » pour OVH et Gandi,
     * le type « DNS AD » (configurable) pour un serveur DNS Windows. (Le nom de la méthode date
     * du temps où seul le type Internet existait.)
     */
    private function getInternetTypeId(): int
    {
        if ($this->internetTypeId !== null) return $this->internetTypeId;
        return $this->internetTypeId = DomainTypes::idForProvider((string)($this->account['provider_type'] ?? ''));
    }

    private function importRecords(string $zoneRef, int $glpiDomainId): void
    {
        global $DB;

        // Flag anti-boucle : les écritures ci-dessous viennent de la synchro,
        // pas d'une action utilisateur — les futurs hooks PRE_ITEM_xxx et ITEM_xxx
        // sur DomainRecord doivent les ignorer (ne rien repousser vers OVH).
        DomainLock::markSyncInProgress($glpiDomainId);
        try {
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
                    // Garde-fou : ne jamais mettre en corbeille une proportion anormalement
                    // élevée des enregistrements d'un domaine en une seule synchro. Une liste
                    // provider incomplète (panne API, rate limiting...) ne doit jamais se
                    // traduire par une suppression massive silencieuse — mieux vaut annuler
                    // le nettoyage de ce passage et remonter une alerte claire.
                    $toTrash = [];
                    foreach ($DB->request([
                        'FROM'  => 'glpi_plugin_dnsmanager_records',
                        'WHERE' => [
                            'accounts_id'      => $accountId,
                            'domainrecords_id' => $domainRecordIds,
                        ],
                    ]) as $mapping) {
                        if (!in_array($mapping['provider_ref'], $ovhRefs, true)) {
                            $toTrash[] = $mapping;
                        }
                    }

                    $totalMapped = count($domainRecordIds);
                    $ratioToTrash = $totalMapped > 0 ? count($toTrash) / $totalMapped : 0;

                    // Seuil : plus de 50% des enregistrements mappés à supprimer d'un coup
                    // (et au moins 3, pour ne pas bloquer les tout petits domaines de test)
                    if (count($toTrash) >= 3 && $ratioToTrash > 0.5) {
                        throw new \RuntimeException(sprintf(
                            'Nettoyage annulé par sécurité : %d enregistrement(s) sur %d (%.0f%%) seraient mis en corbeille en une seule synchro — proportion anormalement élevée, probablement le signe d\'une liste provider incomplète plutôt que de suppressions réelles. Vérifiez manuellement avant de relancer.',
                            count($toTrash), $totalMapped, $ratioToTrash * 100
                        ));
                    }

                    foreach ($toTrash as $mapping) {
                        // Mettre en corbeille dans GLPI
                        $record = new \DomainRecord();
                        $record->delete(['id' => (int)$mapping['domainrecords_id']]);
                        // Supprimer le mapping
                        $DB->delete('glpi_plugin_dnsmanager_records', ['id' => $mapping['id']]);
                    }
                }
            }
        } finally {
            DomainLock::unmarkSyncInProgress($glpiDomainId);
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
                if ($changed) {
                    $this->recordsUpdated++;
                    $this->noteChange(__('modifié', 'dnsmanager'), $recordData, $this->lastChangeDetail);
                }
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
                $this->noteChange(__('restauré', 'dnsmanager'), $recordData);
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
            $this->noteChange(__('rattaché', 'dnsmanager'), $recordData);
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
            $this->noteChange(__('rattaché', 'dnsmanager'), $recordData);
            return;
        }

        // L'entité du record doit être celle du DOMAINE (pas du compte provider)
        $glpiDomainEntity = $DB->request([
            'FROM'   => 'glpi_domains',
            'WHERE'  => ['id' => $glpiDomainId],
            'FIELDS' => ['entities_id'],
        ])->current();
        $recordEntitiesId = $glpiDomainEntity ? (int)$glpiDomainEntity['entities_id'] : ($this->account['entities_id'] ?? 0);

        $record = new \DomainRecord();
        $id = $record->add([
            'domains_id'           => $glpiDomainId,
            'name'                 => $recordName,
            'domainrecordtypes_id' => $typeId,
            'ttl'                  => $ttl,
            'data'                 => $recordData_,
            'comment'              => 'Importé via DNSManage',
            'entities_id'          => $recordEntitiesId,
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
            $this->noteChange(__('créé', 'dnsmanager'), $recordData);
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

        // Différences détectées (type, TTL, valeur)
        $short = static fn($v): string => mb_strlen((string)$v) > 60 ? mb_substr((string)$v, 0, 60) . '…' : (string)$v;
        $diffs = [];
        if ((int)$current['domainrecordtypes_id'] !== $newTypeId) $diffs[] = 'type: ' . (int)$current['domainrecordtypes_id'] . ' → ' . $newTypeId;
        if ((int)$current['ttl'] !== $newTtl)                      $diffs[] = 'ttl: ' . (int)$current['ttl'] . ' → ' . $newTtl;
        if ($current['data'] !== $newData)                         $diffs[] = 'data: «' . $short($current['data']) . '» → «' . $short($newData) . '»';

        // Pas de changement → rien à faire
        if (empty($diffs)) {
            return false;
        }

        $record = new \DomainRecord();
        $record->update([
            'id'                  => $glpiRecordId,
            'domainrecordtypes_id' => $newTypeId,
            'ttl'                 => $newTtl,
            'data'                => $newData,
        ]);

        // Vérifier que la modification a réellement été enregistrée. GLPI peut la refuser
        // (droits du profil sur les types d'enregistrement, validation) ou l'altérer. Sans
        // cette vérification, la même différence réapparaît à chaque synchronisation et
        // l'enregistrement est compté "modifié" à chaque passage.
        $after = $DB->request(['FROM' => 'glpi_domainrecords', 'WHERE' => ['id' => $glpiRecordId]])->current();
        if (!$after
            || (int)$after['domainrecordtypes_id'] !== $newTypeId
            || (int)$after['ttl'] !== $newTtl
            || $after['data'] !== $newData) {
            $this->errors[] = sprintf(
                '%s %s : modification refusée ou altérée par GLPI (%s)',
                $current['name'] ?? '#' . $glpiRecordId, $recordData['type'] ?? '', implode('; ', $diffs)
            );
            $this->lastChangeDetail = '';
            return false;
        }

        $this->lastChangeDetail = implode('; ', $diffs);
        return true;
    }

    /** Garde la trace d'un changement (affiché dans le résumé de la synchro rapide) */
    private function noteChange(string $kind, array $recordData, string $detail = ''): void
    {
        if (count($this->changes) >= 50) return;
        $this->changes[] = sprintf(
            '%s : %s %s%s',
            $kind, $recordData['name'] ?? '?', $recordData['type'] ?? '?', $detail !== '' ? ' (' . $detail . ')' : ''
        );
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
        // new DateTime('') vaut "maintenant" : une date vide doit rester vide, sinon un domaine
        // sans date connue (ex. serveur DNS interne) recevrait la date du jour.
        if (trim($date) === '') return '';
        try { return (new \DateTime($date))->format('Y-m-d'); }
        catch (\Exception) { return ''; }
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
