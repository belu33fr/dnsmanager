<?php
/**
 * DNSManage - Gestion de la configuration et du chiffrement AES
 */

class PluginDnsmanageConfig extends CommonDBTM
{
    public static $rightname = 'config';

    private static ?string $encryptionKey = null;

    // ------------------------------------------------------------------
    // Clé de chiffrement
    // ------------------------------------------------------------------

    /**
     * Retourne la clé de chiffrement stockée en base.
     */
    public static function getEncryptionKey(): string
    {
        if (self::$encryptionKey === null) {
            global $DB;
            $row = $DB->request([
                'FROM'  => 'glpi_plugin_dnsmanager_configs',
                'WHERE' => ['config_key' => 'encryption_key'],
            ])->current();

            self::$encryptionKey = $row['config_value'] ?? '';
        }

        return self::$encryptionKey;
    }

    // ------------------------------------------------------------------
    // Chiffrement / Déchiffrement AES-256-CBC
    // ------------------------------------------------------------------

    /**
     * Chiffre une valeur sensible avant stockage.
     */
    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key    = hex2bin(self::getEncryptionKey());
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new \RuntimeException('Erreur lors du chiffrement.');
        }

        // Stockage : base64(iv + cipher)
        return base64_encode($iv . $cipher);
    }

    /**
     * Déchiffre une valeur sensible récupérée de la base.
     */
    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $key  = hex2bin(self::getEncryptionKey());
        $raw  = base64_decode($encrypted);

        if (strlen($raw) < 16) {
            return '';
        }

        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $plain !== false ? $plain : '';
    }

    // ------------------------------------------------------------------
    // Lecture/écriture config générale
    // ------------------------------------------------------------------

    public static function getConfig(string $key, mixed $default = null): mixed
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_configs',
            'WHERE' => ['config_key' => $key],
        ])->current();

        return $row ? $row['config_value'] : $default;
    }

    public static function setConfig(string $key, mixed $value): void
    {
        global $DB;

        $exists = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_configs',
            'WHERE' => ['config_key' => $key],
        ])->count() > 0;

        if ($exists) {
            $DB->update('glpi_plugin_dnsmanager_configs', ['config_value' => $value], ['config_key' => $key]);
        } else {
            $DB->insert('glpi_plugin_dnsmanager_configs', ['config_key' => $key, 'config_value' => $value]);
        }
    }
}
