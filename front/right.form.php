<?php

use GlpiPlugin\Dnsmanager\Right;

include('../../../inc/includes.php');

Session::checkRight('plugin_dnsmanager_config', UPDATE);

if (isset($_POST['update'])) {
    global $DB;

    $profileId = (int)$_POST['profiles_id'];
    $rights = [
        Right::RIGHT_ACCOUNT,
        Right::RIGHT_CONFIG,
        Right::RIGHT_SYNC,
        Right::RIGHT_CACHE,
    ];

    foreach ($rights as $rightName) {
        $bits = $_POST[$rightName] ?? [];
        $value = 0;
        foreach ([Right::READ, Right::CREATE, Right::UPDATE, Right::DELETE, Right::PURGE] as $bit) {
            if (!empty($bits[$bit])) $value |= $bit;
        }

        $existing = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['profiles_id' => $profileId, 'name' => $rightName],
        ])->current();

        if ($existing) {
            $DB->update('glpi_profilerights', ['rights' => $value], ['id' => $existing['id']]);
        } else {
            $DB->insert('glpi_profilerights', ['profiles_id' => $profileId, 'name' => $rightName, 'rights' => $value]);
        }
    }

    Html::back();
}
