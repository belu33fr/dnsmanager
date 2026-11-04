<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Credential;
use GlpiPlugin\Dnsmanager\Importer;
use GlpiPlugin\Dnsmanager\DomainImporter;
use GlpiPlugin\Dnsmanager\ProviderFactory;
use GlpiPlugin\Dnsmanager\ApiMetrics;
use GlpiPlugin\Dnsmanager\Synclog;

include('../../../inc/includes.php');
set_time_limit(120); // 2 minutes max par requête

Session::checkLoginUser();
Session::checkRight('plugin_dnsmanager_account', READ);

header('Content-Type: application/json');

// Libérer le verrou de session dès que les droits sont vérifiés : sans cela, une
// requête longue (synchronisation) bloque toutes les autres requêtes de la même
// session (test de connexion, préférences, autres onglets) jusqu'à sa fin.
session_write_close();

// $DB doit être déclaré ici : ce script est chargé dans une portée de fonction par GLPI 11,
// et plusieurs actions l'utilisent sans le redéclarer.
global $DB;

$action    = $_POST['action'] ?? '';
$accountId = (int)($_POST['account_id'] ?? 0);

try {
    switch ($action) {

        case 'sync':
            Session::checkRight('plugin_dnsmanager_sync', UPDATE);
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
            $importer = new Importer($accountId);
            // Synchro manuelle déclenchée par un utilisateur : restreindre à son entité active
            $importer->restrictToActiveEntities();
            $result   = $importer->sync();
            echo json_encode(['success' => true] + $result);
            break;

        case 'test':
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
            $provider = Account::getProvider($accountId);
            $provider->testConnection();
            $result = ['success' => true];
            // Si le provider expose un diagnostic (ex: Gandi - organisations détectées), l'inclure
            if (method_exists($provider, 'getDiagnosticMessage')) {
                $diag = $provider->getDiagnosticMessage();
                if (!empty($diag)) $result['message'] = $diag;
            }
            echo json_encode($result);
            break;

        case 'test_form':
            $providerType = trim($_POST['provider_type'] ?? '');
            $endpoint     = trim($_POST['endpoint']      ?? '');
            if (!$providerType) throw new \InvalidArgumentException('Type de provider manquant.');

            $fields      = ProviderFactory::getCredentialFields($providerType);
            $credentials = $accountId ? Credential::getForAccount($accountId) : [];

            foreach ($fields as $field) {
                $val = trim($_POST['cred_' . $field['key']] ?? '');
                if ($val !== '') $credentials[$field['key']] = $val;
            }

            $missing = [];
            foreach ($fields as $field) {
                if ($field['required'] && empty($credentials[$field['key']])) $missing[] = $field['label'];
            }
            if (!empty($missing)) throw new \InvalidArgumentException('Champs manquants : ' . implode(', ', $missing));

            $provider = ProviderFactory::create($providerType, $credentials, $endpoint);
            $provider->testConnection();
            $result = ['success' => true];
            // Si le provider expose un diagnostic (ex: Gandi - organisations détectées), l'inclure
            if (method_exists($provider, 'getDiagnosticMessage')) {
                $diag = $provider->getDiagnosticMessage();
                if (!empty($diag)) $result['message'] = $diag;
            }
            echo json_encode($result);
            break;

        case 'get_sync_domains':
        // Retourne la liste des provider_ref des domaines mappés pour ce compte
        Session::checkRight('plugin_dnsmanager_sync', UPDATE);
        if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
        $domains  = [];
        // Synchro manuelle d'un utilisateur : uniquement les domaines de ses entités actives
        $restrict = !empty($_POST['restrict_entities']);
        $allowedEntities = $restrict ? Session::getActiveEntities() : [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['accounts_id' => $accountId],
        ]) as $row) {
            // Vérifier que le domaine GLPI existe et n'est pas en corbeille
            $glpiDomain = $DB->request([
                'FROM'  => 'glpi_domains',
                'WHERE' => ['id' => (int)$row['domains_id'], 'is_deleted' => 0],
            ])->current();
            if ($glpiDomain && (!$restrict || in_array((int)$glpiDomain['entities_id'], $allowedEntities, true))) {
                $domains[] = $row['provider_ref'];
            }
        }
        echo json_encode(['success' => true, 'domains' => $domains]);
        break;

    case 'import_domain':
        global $DB;
        $accountId  = (int)($_POST['account_id'] ?? 0);
        $encodedRef = trim($_POST['encoded_ref'] ?? '');
        $entitiesId = (int)($_POST['entities_id'] ?? 0);
        if (!$accountId || !$encodedRef || !$entitiesId) {
            echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
            break;
        }
        try {
            $providerRef = base64_decode($encodedRef);

            // Vérification doublon : domaine déjà présent dans GLPI ?
            $existing = $DB->request([
                'FROM'  => 'glpi_domains',
                'WHERE' => ['name' => $providerRef, 'is_deleted' => 0],
            ])->current();
            if ($existing) {
                // Domaine déjà présent - créer juste le mapping si absent
                $mapping = $DB->request([
                    'FROM'  => 'glpi_plugin_dnsmanager_domains',
                    'WHERE' => ['accounts_id' => $accountId, 'provider_ref' => $providerRef],
                ])->current();
                if (!$mapping) {
                    $DB->insert('glpi_plugin_dnsmanager_domains', [
                        'accounts_id'  => $accountId,
                        'domains_id'   => (int)$existing['id'],
                        'provider_ref' => $providerRef,
                        'last_sync_at' => date('Y-m-d H:i:s'),
                        'sync_status'  => 'existing',
                    ]);
                }
                echo json_encode(['success' => true, 'message' => $providerRef . ' (déjà présent, mapping créé)']);
                break;
            }

            $domainImporter = new DomainImporter($accountId, true);
            $domainImporter->importDomain($providerRef, $entitiesId);
            echo json_encode(['success' => true, 'message' => $providerRef]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'restore_domain':
        $accountId   = (int)($_POST['account_id'] ?? 0);
        $domainId    = (int)($_POST['domain_id'] ?? 0);
        $providerRef = trim($_POST['provider_ref'] ?? '');
        if (!$accountId || !$domainId) {
            echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
            break;
        }
        try {
            $domainImporter = new DomainImporter($accountId, true);
            $domainImporter->restoreDomain($domainId);
            $importer = new Importer($accountId, false);
            $importer->syncDomainByRef($providerRef);
            echo json_encode(['success' => true, 'message' => $providerRef]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'delete_domain':
        $accountId = (int)($_POST['account_id'] ?? 0);
        $domainId  = (int)($_POST['domain_id'] ?? 0);
        if (!$accountId || !$domainId) {
            echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
            break;
        }
        try {
            $domain = new \Domain();
            $domain->delete(['id' => $domainId]);
            echo json_encode(['success' => true, 'message' => '#' . $domainId]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'sync_domain':
            $accountId = (int)($_POST['account_id'] ?? 0);
            $domainRef = trim($_POST['domain_ref'] ?? '');
            if (!$accountId || !$domainRef) {
                echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
                break;
            }
            $t0       = microtime(true);
            $importer = new Importer($accountId, false);
            $result   = $importer->syncDomainByRef($domainRef);
            $metrics  = ApiMetrics::snapshot();
            echo json_encode([
                'success'         => true,
                'message'         => ($result['records_added'] ?? 0) . ' créé(s), ' . ($result['records_updated'] ?? 0) . ' mis à jour.',
                'records_added'   => (int)($result['records_added']   ?? 0),
                'records_updated' => (int)($result['records_updated'] ?? 0),
                'errors'          => array_values($result['errors'] ?? []),
                'duration_ms'     => (int)round((microtime(true) - $t0) * 1000),
                'api_calls'       => $metrics['calls'],
                'api_ms'          => $metrics['ms'],
            ]);
            break;

        case 'account_stats_local':
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
            echo json_encode(['success' => true] + Account::getStats($accountId));
            break;

        case 'sync_log':
            // Journal d'une synchro rapide (pilotée par le navigateur, domaine par domaine)
            Session::checkRight('plugin_dnsmanager_sync', UPDATE);
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
            $duration = max(0, min(86400, (int)($_POST['duration'] ?? 0)));
            $errors   = trim((string)($_POST['errors'] ?? ''));
            $cancelled = !empty($_POST['cancelled']);
            $finished = time();
            $DB->insert(Synclog::getTable(), [
                'accounts_id'     => $accountId,
                'started_at'      => date('Y-m-d H:i:s', $finished - $duration),
                'finished_at'     => date('Y-m-d H:i:s', $finished),
                'status'          => $cancelled ? 'partial' : ($errors === '' ? 'success' : 'failed'),
                'domains_added'   => 0,
                'domains_updated' => max(0, (int)($_POST['domains'] ?? 0)),
                'records_added'   => max(0, (int)($_POST['records_added'] ?? 0)),
                'records_updated' => max(0, (int)($_POST['records_updated'] ?? 0)),
                'error_log'       => ($cancelled || $errors !== '')
                    ? mb_substr('Sync rapide (manuelle) :' . ($cancelled ? "\nAnnulée par l'utilisateur." : '') . ($errors !== '' ? "\n" . $errors : ''), 0, 8000)
                    : null,
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'account_stats':
            // Comptage chez l'hébergeur (à la demande : un appel API léger, jamais automatique)
            global $DB;
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
            $provider = Account::getProvider($accountId);
            if (!method_exists($provider, 'listDomainRefs')) {
                throw new \RuntimeException('Comptage non disponible pour ce provider.');
            }
            $refs   = $provider->listDomainRefs();
            $mapped = [];
            foreach ($DB->request(['FROM' => 'glpi_plugin_dnsmanager_domains', 'WHERE' => ['accounts_id' => $accountId]]) as $row) {
                $mapped[(string)$row['provider_ref']] = true;
            }
            $notLinked = 0;
            foreach ($refs as $ref) {
                if (!isset($mapped[(string)$ref])) $notLinked++;
            }
            // Mémoriser pour l'affichage (évite "—" à chaque ouverture de la fiche)
            $DB->update(Account::getTable(), [
                'stat_provider_domains'    => count($refs),
                'stat_provider_not_linked' => $notLinked,
                'stat_provider_checked_at' => date('Y-m-d H:i:s'),
            ], ['id' => $accountId]);
            $fresh = Account::getStats($accountId);
            echo json_encode([
                'success'        => true,
                'provider_count' => count($refs),
                'not_linked'     => $notLinked,
                'checked'        => $fresh['provider_checked'],
            ]);
            break;

        default:
            throw new \InvalidArgumentException("Action inconnue : $action");
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
