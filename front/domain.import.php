<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Menu;
use GlpiPlugin\Dnsmanager\DomainImporter;
use GlpiPlugin\Dnsmanager\Importer;
use GlpiPlugin\Dnsmanager\Right;
use Glpi\Application\View\TemplateRenderer;

include('../../../inc/includes.php');
set_time_limit(120); // 2 minutes max

Session::checkRight('plugin_dnsmanager_sync', UPDATE);

$accountId = (int)($_REQUEST['account_id'] ?? 0);
if (!$accountId) {
    Html::back();
}

// Traitement du formulaire d'import
if (isset($_POST['action']) && $_POST['action'] === 'import') {

    $domainImporter = new DomainImporter($accountId, true);
    $analysis       = $domainImporter->analyze();
    $errors         = [];
    $imported       = 0;
    $restored       = 0;
    $deleted        = 0;

    // 1. Restaurer les domaines en corbeille
    foreach ($_POST['restore'] ?? [] as $providerRef) {
        foreach ($analysis['to_restore'] as $item) {
            if ($item['domain_data']['ref'] === $providerRef) {
                try {
                    $domainImporter->restoreDomain((int)$item['glpi_domain']['id']);
                    $restored++;
                } catch (\Exception $e) {
                    $errors[] = "[Restauration {$item['domain_data']['name']}] " . $e->getMessage();
                }
            }
        }
    }

    // 2. Importer les nouveaux domaines avec entité choisie
    foreach ($_POST['import'] ?? [] as $encodedRef => $entitiesId) {
        if (empty($entitiesId) || (int)$entitiesId <= 0) continue; // pas d'entité choisie → ignorer
        // Décoder le ref base64
        $providerRef = base64_decode($encodedRef);
        if (empty($providerRef)) continue;
        try {
            $domainImporter->importDomain($providerRef, (int)$entitiesId);
            $imported++;
        } catch (\Exception $e) {
            $errors[] = "[Import $providerRef] " . $e->getMessage();
        }
    }

    // 3. Supprimer (corbeille) les domaines absents du provider
    foreach ($_POST['delete'] ?? [] as $providerRef) {
        foreach ($analysis['to_delete'] as $item) {
            if ($item['mapping']['provider_ref'] === $providerRef) {
                try {
                    $domainImporter->deleteDomain((int)$item['glpi_domain']['id'], $providerRef);
                    $deleted++;
                } catch (\Exception $e) {
                    $errors[] = "[Suppression {$item['glpi_domain']['name']}] " . $e->getMessage();
                }
            }
        }
    }

    // 4. Lancer la synchronisation complète
    try {
        $importer = new Importer($accountId, false); // non-interactif
        $result   = $importer->sync();
        $msg = sprintf(
            __('%d domaine(s) importé(s), %d restauré(s), %d supprimé(s). Sync : %d domaine(s), %d enregistrement(s).', 'dnsmanager'),
            $imported, $restored, $deleted,
            $result['updated'] ?? 0,
            ($result['records_added'] ?? 0) + ($result['records_updated'] ?? 0)
        );
        if (!empty($errors) || !empty($result['errors'])) {
            $msg .= ' ' . __('Erreurs :', 'dnsmanager') . ' ' . implode(', ', array_merge($errors, $result['errors'] ?? []));
        }
        Session::addMessageAfterRedirect($msg, true, empty($errors) ? INFO : WARNING);
    } catch (\Exception $e) {
        Session::addMessageAfterRedirect($e->getMessage(), true, ERROR);
    }

    Html::redirect(Plugin::getWebDir('dnsmanager') . '/front/account.form.php?id=' . $accountId);
    exit;
}

// Affichage de la page d'import (GET)
Html::header(
    __('Import de domaines DNS', 'dnsmanager'),
    $_SERVER['PHP_SELF'],
    Menu::MENU_SECTOR,
    'account'
);

$domainImporter = new DomainImporter($accountId, true);
$analysis       = $domainImporter->analyze();
$entities       = DomainImporter::getImportableEntities();

// Si rien à faire → retourner à la fiche avec un message. La recherche ne synchronise
// PAS les enregistrements (c'est le rôle de "Sync rapide") : une version précédente
// lançait ici, sans le dire, une synchronisation complète de tous les domaines.
if (empty($analysis['to_import']) && empty($analysis['to_restore']) && empty($analysis['to_delete'])) {
    Session::addMessageAfterRedirect(
        sprintf(
            __("Recherche terminée : aucun nouveau domaine à importer, à restaurer ni à supprimer (%d domaine(s) déjà dans GLPI). Utilisez « Sync rapide » pour mettre à jour les enregistrements.", 'dnsmanager'),
            count($analysis['to_sync'])
        ),
        true,
        INFO
    );
    Html::redirect(Plugin::getWebDir('dnsmanager') . '/front/account.form.php?id=' . $accountId);
    exit;
}

// Générer les dropdowns Entity filtrés selon la config
$importableEntities = \GlpiPlugin\Dnsmanager\DomainImporter::getImportableEntities();

// Si la config est vide → toutes les entités accessibles
$entityCondition = empty($importableEntities)
    ? $_SESSION['glpiactiveentities']
    : array_column($importableEntities, 'id');

// Condition SQL structurelle (parent-based) pour le filtre dans la modal
// Contrairement à une liste figée d'IDs, ce filtre reste valide pour les
// entités créées après le chargement de la page (nouveaux enfants).
$modalCondition = \GlpiPlugin\Dnsmanager\DomainImporter::getImportableEntitiesCondition();
$modalEntityDropdown = ''; // généré dans le template via itemtype_dropdown

$toImportWithEncoding = [];
foreach ($analysis['to_import'] as $domain) {
    $encodedRef = base64_encode($domain['ref']);
    $domain['encoded_ref'] = $encodedRef;
    $toImportWithEncoding[] = $domain;
}
$entityDropdowns = []; // non utilisé
$dropdownParams = []; // non utilisé

TemplateRenderer::getInstance()->display('@dnsmanager/domain.import.html.twig', [
    'account_id'        => $accountId,
    'to_import'         => $toImportWithEncoding,
    'to_restore'        => $analysis['to_restore'],
    'to_delete'         => $analysis['to_delete'],
    'to_sync'           => $analysis['to_sync'],
    'entities'          => $entities,
    'entity_condition'  => $entityCondition,
    'dropdown_params'   => $dropdownParams,
    'modal_condition'   => $modalCondition,
    'webdir'            => Plugin::getWebDir('dnsmanager'),
]);

Html::footer();
