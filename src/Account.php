<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;
use CommonGLPI;
use Html;
use MassiveAction;
use Plugin;
use Session;
use Supplier;

class Account extends CommonDBTM
{
    /**
     * Valeur de `account_type` pour les comptes de fournisseurs DNS. Prévu pour la future
     * fusion, dans une table unique, des comptes de plusieurs plugins (DNS, mail, sauvegarde...) :
     * chaque plugin ne lit et n'écrit que ses propres comptes. Posée à la création, jamais
     * modifiable depuis le formulaire. Les lectures PAR IDENTIFIANT (fiche d'un compte) ne
     * filtrent pas ; les listes et parcours (liste, tâche automatique) filtrent.
     */
    public const TYPE_DNS = 1;

    public static $rightname = 'plugin_dnsmanager_account';
    public $dohistory  = true;
    public $recursive  = true;  // Active la case "Sous-entités"

    /**
     * Indique à GLPI que cet objet peut être récursif
     * même sans sous-entités existantes.
     */
    public function maybeRecursive(): bool
    {
        return true;
    }

    public function isEntityAssign(): bool
    {
        return true;
    }

    public function canRecurs(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Compte DNS Hébergeurs', 'Comptes DNS Hébergeurs', $nb, 'dnsmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_accounts';
    }

    public static function getIcon(): string
    {
        return 'ti ti-world';
    }

    public static function getFormURL($full = true): string
    {
        return Plugin::getWebDir('dnsmanager', $full) . '/front/account.form.php';
    }

    public static function getFormURLWithID($id = 0, $full = true): string
    {
        return self::getFormURL($full) . '?id=' . (int)$id;
    }

    public static function getSearchURL($full = true): string
    {
        return Plugin::getWebDir('dnsmanager', $full) . '/front/account.php';
    }

    // ------------------------------------------------------------------
    // Formulaire GLPI 11 (Twig)
    // ------------------------------------------------------------------

    /**
     * Onglets de la fiche : l'onglet principal (formulaire) puis le Journal de synchronisation.
     * L'onglet principal doit être déclaré explicitement : sans lui, ajouter un autre onglet ferait
     * disparaître le formulaire.
     */
    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        // Libellé de l'onglet principal (même clé que celle posée par addDefaultFormTab)
        $ong[static::getType() . '$main'] = __('Hébergeur général', 'dnsmanager');
        $this->addStandardTab(AccountTabs::class, $ong, $options);
        $this->addStandardTab(Synclog::class, $ong, $options);
        return $ong;
    }

    /**
     * Variables communes à la fiche de base et aux onglets (Extension, Contacts, Authentification).
     */
    public function getTemplateVars(array $options = []): array
    {
        $providers = ProviderFactory::getAvailableProviders();
        $allProviderData = [];
        foreach ($providers as $type => $label) {
            $allProviderData[$type] = [
                'endpoints' => ProviderFactory::getEndpoints($type),
                'fields'    => ProviderFactory::getCredentialFields($type),
            ];
        }

        $ID          = (int)($this->fields['id'] ?? 0);
        $credentials = [];
        if ($ID > 0) {
            $credentials = Credential::getForAccount($ID);
        }

        // Vérifier si le plugin accounts est disponible
        $accountsClass = class_exists('\GlpiPlugin\Accounts\Account')
            ? '\GlpiPlugin\Accounts\Account'
            : null;

        // Filtrer les dropdowns Administrateur/Technicien/Financier par type de compte
        // (configurable dans le paramétrage du plugin, "Fournisseur" par défaut)
        $accountsTypeCondition = [];
        if ($accountsClass) {
            global $DB;
            $typeRow = $DB->request([
                'FROM'  => 'glpi_plugin_accounts_accounttypes',
                'WHERE' => ['name' => PluginConfig::getContactAccountType()],
            ])->current();
            if ($typeRow) {
                $accountsTypeCondition = ['plugin_accounts_accounttypes_id' => (int)$typeRow['id']];
            }
        }

        // Statistiques du compte (comptes existants uniquement)
        $stats = ((int)($this->fields['id'] ?? 0) > 0) ? self::getStats((int)$this->fields['id']) : null;

        return [
            'item'                   => $this,
            'params'                 => $options,
            'providers'              => $providers,
            'allProviderData'        => json_encode($allProviderData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT),
            'credentials'            => json_encode($credentials,    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT),
            'currentEndpoint'        => addslashes($this->fields['endpoint'] ?? ''),
            'webdir'                 => Plugin::getWebDir('dnsmanager'),
            'can_sync'               => Right::canSync(),
            'accounts_class'         => $accountsClass,
            'accounts_type_condition' => $accountsTypeCondition,
            'stats'                  => $stats,
        ];
    }

    public function showForm($ID, array $options = []): bool
    {
        // initForm() gère correctement création (ID=0) et édition
        $this->initForm($ID, $options);

        $twig_vars = $this->getTemplateVars($options);
        // La fiche de base ne reprend du gabarit GLPI que le nom : les autres champs (type de provider,
        // groupes, état, commentaire) sont placés par le plugin, dans l'ordre voulu.
        $twig_vars['field_order'] = ['name'];

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@dnsmanager/account.form.html.twig',
            $twig_vars
        );

        return true;
    }

    // ------------------------------------------------------------------
    // Options de recherche
    // ------------------------------------------------------------------

    public function rawSearchOptions(): array
    {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Nom', 'dnsmanager'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'massiveaction' => false,
            'datatype'      => 'number',
        ];

        $tab[] = [
            'id'            => 10,
            'table'         => self::getTable(),
            'field'         => 'provider_type',
            'name'          => __('Provider', 'dnsmanager'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 11,
            'table'         => self::getTable(),
            'field'         => 'endpoint',
            'name'          => __('Endpoint', 'dnsmanager'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 12,
            'table'         => self::getTable(),
            'field'         => 'domain_whitelist',
            'name'          => __('Liste blanche', 'dnsmanager'),
            'datatype'      => 'string',
            'massiveaction' => true,
        ];

        $tab[] = [
            'id'            => 13,
            'table'         => self::getTable(),
            'field'         => 'renewal_months',
            'name'          => __('Préavis de facturation (mois)', 'dnsmanager'),
            'datatype'      => 'number',
            'massiveaction' => true,
        ];

        $tab[] = [
            'id'            => 14,
            'table'         => self::getTable(),
            'field'         => 'is_active',
            'name'          => __('Actif', 'dnsmanager'),
            'datatype'      => 'bool',
            'massiveaction' => true,
        ];

        $tab[] = [
            'id'            => 15,
            'table'         => self::getTable(),
            'field'         => 'last_sync_at',
            'name'          => __('Dernière sync', 'dnsmanager'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 16,
            'table'         => self::getTable(),
            'field'         => 'comment',
            'name'          => __('Commentaire', 'dnsmanager'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 17,
            'table'         => 'glpi_suppliers',
            'field'         => 'name',
            'name'          => __('Fournisseur', 'dnsmanager'),
            'datatype'      => 'dropdown',
            'massiveaction' => true,
            'joinparams'    => [
                'jointype'  => 'field',
                'linkfield' => 'suppliers_id',
            ],
        ];

        $tab[] = [
            'id'            => 18,
            'table'         => self::getTable(),
            'field'         => 'provider_key_name',
            'name'          => __('Nom de la clé chez le provider', 'dnsmanager'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => __('Entité'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    // ------------------------------------------------------------------
    // Actions massives
    // ------------------------------------------------------------------

    public function getSpecificMassiveActions($checkitem = null): array
    {
        $actions = parent::getSpecificMassiveActions($checkitem);

        $sep = MassiveAction::CLASS_ACTION_SEPARATOR;
        $actions[self::class . $sep . 'change_supplier']  = __('Modifier le fournisseur', 'dnsmanager');
        $actions[self::class . $sep . 'change_whitelist'] = __('Modifier la liste blanche', 'dnsmanager');
        $actions[self::class . $sep . 'change_renewal']   = __('Modifier le préavis de facturation (mois)', 'dnsmanager');
        $actions[self::class . $sep . 'sync']             = __('Synchroniser maintenant', 'dnsmanager');
        $actions[self::class . $sep . 'search_domains']   = __('Rechercher nouveaux domaines', 'dnsmanager');

        return $actions;
    }

    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        switch ($ma->getAction()) {
            case 'change_supplier':
                Supplier::dropdown(['name' => 'suppliers_id', 'value' => 0]);
                echo Html::submit(__('Appliquer', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;

            case 'change_whitelist':
                echo Html::input('domain_whitelist', ['placeholder' => 'example.com, example.fr']);
                echo Html::submit(__('Appliquer', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;

            case 'change_renewal':
                echo Html::input('renewal_months', ['type' => 'number', 'min' => 1, 'max' => 12, 'value' => 1, 'style' => 'width:80px']);
                echo Html::submit(__('Appliquer', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;

            case 'sync':
                // Synchro pilotée depuis la fenêtre (domaine par domaine, annulable). Si la
                // sélection est introuvable, on retombe sur l'ancien bouton (requête unique).
                global $DB;
                $items = method_exists($ma, 'getItems') ? $ma->getItems() : [];
                $ids   = array_map('intval', array_keys($items[self::class] ?? []));
                if (empty($ids)) {
                    echo Html::submit(__('Lancer la synchronisation', 'dnsmanager'), ['name' => 'massiveaction']);
                    return true;
                }
                $accounts = [];
                foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $ids], 'ORDER' => 'name ASC']) as $row) {
                    $accounts[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
                }
                \Glpi\Application\View\TemplateRenderer::getInstance()->display('@dnsmanager/mass.sync.html.twig', [
                    'accounts' => $accounts,
                    'webdir'   => Plugin::getWebDir('dnsmanager'),
                ]);
                return true;

            case 'search_domains':
                echo Html::submit(__('Lancer la recherche', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;
        }
        return parent::showMassiveActionsSubForm($ma);
    }

    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ): void {
        global $DB;

        // Contournement bug GLPI 11 namespace + MassiveAction::remainings
        // On ne peut pas appeler itemDone() car remainings[$itemtype] est null
        // On accède directement à $ma->results via Reflection sur la propriété results
        $action = $ma->getAction();

        $handledActions = ['change_supplier', 'change_whitelist', 'change_renewal', 'sync', 'search_domains'];
        if (!in_array($action, $handledActions, true)) {
            parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
            return;
        }

        $ok = 0;
        $ko = 0;
        $messages = [];

        switch ($action) {
            case 'change_supplier':
                $supplierId = (int)($ma->POST['suppliers_id'] ?? 0);
                foreach ($ids as $id) {
                    try {
                        $DB->update(self::getTable(), ['suppliers_id' => $supplierId, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
                        $ok++;
                    } catch (\Exception $e) { $ko++; $messages[] = $e->getMessage(); }
                }
                break;

            case 'change_whitelist':
                $whitelist = trim($ma->POST['domain_whitelist'] ?? '');
                foreach ($ids as $id) {
                    try {
                        $DB->update(self::getTable(), ['domain_whitelist' => $whitelist, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
                        $ok++;
                    } catch (\Exception $e) { $ko++; $messages[] = $e->getMessage(); }
                }
                break;

            case 'change_renewal':
                $months = max(1, (int)($ma->POST['renewal_months'] ?? 1));
                foreach ($ids as $id) {
                    try {
                        $DB->update(self::getTable(), ['renewal_months' => $months, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
                        $ok++;
                    } catch (\Exception $e) { $ko++; $messages[] = $e->getMessage(); }
                }
                break;

            case 'sync':
                foreach ($ids as $id) {
                    try {
                        // Mode interactif = false pour les actions massives
                        // Les nouveaux domaines sont ignorés et signalés
                        $importer = new Importer($id, false);
                        // Action massive déclenchée par un utilisateur : restreindre à son entité active
                        $importer->restrictToActiveEntities();
                        $result   = $importer->sync();
                        $ok++;

                        // Vérifier s'il y a des domaines à importer
                        $domainImporter = new \GlpiPlugin\Dnsmanager\DomainImporter($id, true);
                        self::notifyNewDomains($id, $domainImporter->analyze());
                    } catch (\Exception $e) {
                        $ko++;
                        $messages[] = $e->getMessage();
                    }
                }
                break;

            case 'search_domains':
                // Recherche seule : n'écrit rien dans GLPI et ne synchronise aucun enregistrement
                foreach ($ids as $id) {
                    try {
                        $domainImporter = new \GlpiPlugin\Dnsmanager\DomainImporter($id, true);
                        $analysis       = $domainImporter->analyze();
                        $account        = Account::getWithCredentials($id);
                        $accountName    = htmlspecialchars($account['account']['name'] ?? '#' . $id);
                        $importUrl      = Plugin::getWebDir('dnsmanager') . '/front/domain.import.php?account_id=' . $id;

                        $found = self::notifyNewDomains($id, $analysis);
                        $other = count($analysis['to_restore']) + count($analysis['to_delete']);
                        if ($other > 0) {
                            \Session::addMessageAfterRedirect(
                                sprintf(
                                    __('"%s" : %d domaine(s) à restaurer, %d absent(s) chez l\'hébergeur (à supprimer) — <a href="%s" class="btn btn-sm btn-warning ms-2">Ouvrir l\'import</a>', 'dnsmanager'),
                                    $accountName, count($analysis['to_restore']), count($analysis['to_delete']), $importUrl
                                ),
                                false, WARNING, true
                            );
                        } elseif (!$found) {
                            \Session::addMessageAfterRedirect(
                                sprintf(__('"%s" : aucun nouveau domaine à importer, restaurer ou supprimer.', 'dnsmanager'), $accountName),
                                false, INFO, true
                            );
                        }
                        $ok++;
                    } catch (\Exception $e) {
                        $ko++;
                        $messages[] = $e->getMessage();
                    }
                }
                break;
        }

        // Mettre à jour les résultats directement sans passer par itemDone()
        try {
            $ref = new \ReflectionProperty($ma, 'results');
            $ref->setAccessible(true);
            $results = $ref->getValue($ma);
            $results['ok']       += $ok;
            $results['ko']       += $ko;
            $results['messages']  = array_merge($results['messages'] ?? [], $messages);
            $ref->setValue($ma, $results);
        } catch (\Throwable) {
            // Fallback si Reflection échoue
        }

        // Vider remainings pour cet itemtype pour éviter le crash
        try {
            $ref2 = new \ReflectionProperty($ma, 'remainings');
            $ref2->setAccessible(true);
            $remainings = $ref2->getValue($ma) ?? [];
            unset($remainings[self::class]);
            $ref2->setValue($ma, $remainings);
        } catch (\Throwable) {}
    }

    // ------------------------------------------------------------------
    // Préparation des données avant sauvegarde
    // ------------------------------------------------------------------

    /**
     * GLPI filtre les champs inconnus — on force provider_type et endpoint
     * qui viennent de <select> HTML et non de dropdowns GLPI natifs.
     */
    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        $input = $this->cleanInput($input);
        if (is_array($input)) {
            $input['account_type'] = self::TYPE_DNS;
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        $input = $this->cleanInput($input);
        if (is_array($input)) {
            unset($input['account_type']);   // jamais modifiable depuis le formulaire
        }
        return $input;
    }

    private function cleanInput(array $input): array
    {
        // S'assurer que ces champs sont bien présents et propres
        foreach (['provider_type', 'endpoint', 'domain_whitelist', 'renewal_months', 'suppliers_id', 'is_active', 'is_recursive'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = $input[$field];
            }
        }
        // Supprimer les champs credentials qui ne doivent pas aller en base
        foreach (array_keys($input) as $key) {
            if (str_starts_with($key, 'cred_')) {
                unset($input[$key]);
            }
        }
        return $input;
    }

    // ------------------------------------------------------------------
    // Helpers CRUD
    // ------------------------------------------------------------------

    public static function getWithCredentials(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$row) return null;
        return ['account' => $row, 'credentials' => Credential::getForAccount($id)];
    }

    public static function getActiveAccounts(): array
    {
        global $DB;
        $accounts = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_active' => 1, 'account_type' => self::TYPE_DNS], 'ORDER' => 'name ASC']) as $row) {
            $accounts[] = $row;
        }
        return $accounts;
    }

    public static function updateLastSync(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['last_sync_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function getProvider(int $id): ProviderInterface
    {
        $data = self::getWithCredentials($id);
        if (!$data) throw new \RuntimeException("Compte #$id introuvable.");
        return ProviderFactory::create(
            $data['account']['provider_type'],
            $data['credentials'],
            $data['account']['endpoint']
        );
    }

    /**
     * Indique si la modification bidirectionnelle (GLPI -> provider) est autorisée
     * pour ce compte. Uniquement via la directive serveur PLUGIN_DNSMANAGER_PUSH_ACCOUNTS
     * (voir PushGuard) : aucun réglage de l'interface n'intervient. OVH uniquement.
     */
    public static function hasBidirectionalSync(int $accountId): bool
    {
        global $DB;

        if (!PushGuard::isEnabledForAccount($accountId)) return false;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['id' => $accountId],
        ])->current();

        if (!$row) return false;

        // Périmètre actuel : OVH uniquement
        return ($row['provider_type'] ?? '') === 'ovh';
    }

    /**
     * Ajoute le message "N nouveau(x) domaine(s) à importer" (avec lien vers l'import)
     * si l'analyse en a trouvé. Retourne true si un message a été ajouté.
     */
    private static function notifyNewDomains(int $accountId, array $analysis): bool
    {
        if (empty($analysis['to_import'])) {
            return false;
        }
        $names     = implode(', ', array_column(array_column($analysis['to_import'], 'domain_data'), 'name'));
        $account   = self::getWithCredentials($accountId);
        $importUrl = Plugin::getWebDir('dnsmanager') . '/front/domain.import.php?account_id=' . $accountId;
        \Session::addMessageAfterRedirect(
            sprintf(
                __('%d nouveau(x) domaine(s) à importer depuis "%s" : %s — <a href="%s" class="btn btn-sm btn-warning ms-2">Importer maintenant</a>', 'dnsmanager'),
                count($analysis['to_import']),
                htmlspecialchars($account['account']['name'] ?? '#' . $accountId),
                htmlspecialchars($names),
                $importUrl
            ),
            false,
            WARNING,
            true  // allow HTML
        );
        return true;
    }

    /**
     * Statistiques d'un compte : domaines rattachés dans GLPI et dates de synchronisation.
     * (Le nombre de domaines chez l'hébergeur est obtenu à la demande, par appel API.)
     */
    public static function getStats(int $accountId): array
    {
        global $DB;

        $cnt = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => 'glpi_plugin_dnsmanager_domains',
            'INNER JOIN' => ['glpi_domains' => ['ON' => ['glpi_domains' => 'id', 'glpi_plugin_dnsmanager_domains' => 'domains_id']]],
            'WHERE'      => ['glpi_plugin_dnsmanager_domains.accounts_id' => $accountId, 'glpi_domains.is_deleted' => 0],
        ])->current();

        // Dernière synchronisation d'un domaine de ce compte
        $lastDomain = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['accounts_id' => $accountId],
            'ORDER' => 'last_sync_at DESC',
            'LIMIT' => 1,
        ])->current();

        // Dernier passage enregistré au journal (CRON ou synchro rapide)
        $lastLog = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_synclogs',
            'WHERE' => ['accounts_id' => $accountId, 'status' => ['<>', 'running']],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();

        $logText = '';
        if ($lastLog && !empty($lastLog['finished_at'])) {
            $logText = \Html::convDateTime($lastLog['finished_at'])
                . ' (' . match ($lastLog['status']) {
                    'success' => __('réussie', 'dnsmanager'),
                    'partial' => __('partielle', 'dnsmanager'),
                    default   => __('en échec', 'dnsmanager'),
                } . ')';
        }

        // Dernier comptage chez l'hébergeur (mémorisé par l'action account_stats)
        $acc = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $accountId]])->current();
        $checkedAt = $acc['stat_provider_checked_at'] ?? null;

        return [
            'provider_domains'    => isset($acc['stat_provider_domains'])    ? (int)$acc['stat_provider_domains']    : null,
            'provider_not_linked' => isset($acc['stat_provider_not_linked']) ? (int)$acc['stat_provider_not_linked'] : null,
            'provider_checked'    => $checkedAt ? sprintf(__('vérifié le %s', 'dnsmanager'), \Html::convDateTime($checkedAt)) : '',
            'provider_age'        => $checkedAt ? max(0, time() - strtotime($checkedAt)) : null,
            'glpi_domains'      => (int)($cnt['cpt'] ?? 0),
            'last_records_sync' => ($lastDomain && !empty($lastDomain['last_sync_at'])) ? \Html::convDateTime($lastDomain['last_sync_at']) : '',
            'last_log'          => $logText,
        ];
    }
}
