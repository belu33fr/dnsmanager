<?php

use GlpiPlugin\Dnsmanager\Account;

include('../../../inc/includes.php');

Session::checkRight('plugin_dnsmanager_account', READ);

Html::header(
    Account::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    Account::class,
    'account'
);

Search::show(Account::class);

Html::footer();
