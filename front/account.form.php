<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Credential;

include('../../../inc/includes.php');

Session::checkRight('plugin_dnsmanager_account', READ);

$account = new Account();

if (isset($_POST['add'])) {
    Session::checkRight('plugin_dnsmanager_account', UPDATE);

    // Sauvegarder le compte
    $input = $_POST;
    unset($input['add']);

    // Extraire les credentials avant l'add
    $credentials = [];
    foreach (array_keys($_POST) as $key) {
        if (str_starts_with($key, 'cred_')) {
            $val = trim($_POST[$key]);
            if ($val !== '') $credentials[substr($key, 5)] = $val;
            unset($input[$key]);
        }
    }

    $newID = $account->add($input);
    if ($newID && !empty($credentials)) {
        Credential::saveForAccount($newID, $credentials);
    }

    if ($_SESSION['glpibackcreated'] ?? false) {
        Html::redirect(Account::getFormURL() . '?id=' . $newID);
    }
    Html::back();

} elseif (isset($_POST['update'])) {
    Session::checkRight('plugin_dnsmanager_account', UPDATE);

    $id    = (int)$_POST['id'];
    $input = $_POST;

    $credentials = [];
    foreach (array_keys($_POST) as $key) {
        if (str_starts_with($key, 'cred_')) {
            $val = trim($_POST[$key]);
            if ($val !== '') $credentials[substr($key, 5)] = $val;
            unset($input[$key]);
        }
    }

    $account->update($input);
    if (!empty($credentials)) {
        Credential::saveForAccount($id, $credentials);
    }
    Html::back();

} elseif (isset($_POST['delete'])) {
    Session::checkRight('plugin_dnsmanager_account', UPDATE);
    $account->delete($_POST);
    $account->redirectToList();

} elseif (isset($_POST['purge'])) {
    Session::checkRight('plugin_dnsmanager_account', UPDATE);
    $account->delete($_POST, 1);
    $account->redirectToList();

} elseif (isset($_POST['sync'])) {
    // Synchronisation manuelle depuis la fiche
    Session::checkRight('plugin_dnsmanager_account', READ);
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    try {
        $importer = new Importer($id);
        $result   = $importer->sync();
        echo json_encode(['success' => true] + $result);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;

} elseif (isset($_POST['test_connection'])) {
    // Test de connexion depuis la fiche
    Session::checkRight('plugin_dnsmanager_account', READ);
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    try {
        $provider = Account::getProvider($id);
        $provider->testConnection();
        echo json_encode(['success' => true]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;

} else {
    $ID = (int)($_GET['id'] ?? 0);

    Html::header(
        Account::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'tools',
        Account::class,
        'account'
    );

    // GLPI 11 : display() gère création et édition
    $account->display([
        'id'          => $ID,
        'formoptions' => "data-track-changes='true'",
    ]);

    Html::footer();
}
