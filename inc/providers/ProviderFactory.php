<?php
/**
 * DNSManage - Factory des providers DNS
 */

class PluginDnsmanageProviderFactory
{
    /**
     * Mapping type → classe PHP
     * Ajouter ici chaque nouveau provider.
     */
    private const PROVIDERS = [
        'ovh'        => 'PluginDnsmanageOvhProvider',
        // 'gandi'      => 'PluginDnsmanageGandiProvider',
        // 'cloudflare' => 'PluginDnsmanageCloudflareProvider',
    ];

    /**
     * Instancie le provider correspondant au type demandé.
     *
     * @param  string              $type        Type de provider (ex. 'ovh')
     * @param  array<string,string> $credentials Credentials déchiffrés
     * @param  string              $endpoint    Endpoint configuré sur le compte
     * @return PluginDnsmanageProviderInterface
     * @throws \InvalidArgumentException        Si le type n'est pas supporté
     */
    public static function create(
        string $type,
        array $credentials,
        string $endpoint = ''
    ): PluginDnsmanageProviderInterface {
        if (!isset(self::PROVIDERS[$type])) {
            throw new \InvalidArgumentException(
                sprintf("Provider '%s' non supporté. Providers disponibles : %s", $type, implode(', ', array_keys(self::PROVIDERS)))
            );
        }

        $class = self::PROVIDERS[$type];

        if (!class_exists($class)) {
            throw new \RuntimeException("Classe provider '$class' introuvable.");
        }

        return new $class($credentials, $endpoint);
    }

    /**
     * Retourne la liste de tous les providers disponibles.
     *
     * @return array<string, string>  ['ovh' => 'OVH', ...]
     */
    public static function getAvailableProviders(): array
    {
        $result = [];
        foreach (self::PROVIDERS as $type => $class) {
            if (class_exists($class)) {
                $result[$type] = $class::getLabel();
            }
        }
        return $result;
    }

    /**
     * Retourne la définition des champs de credentials d'un provider.
     *
     * @param  string $type  Type de provider
     * @return array<int, array<string,mixed>>
     */
    public static function getCredentialFields(string $type): array
    {
        if (!isset(self::PROVIDERS[$type])) {
            return [];
        }
        $class = self::PROVIDERS[$type];
        if (!class_exists($class)) {
            return [];
        }
        return $class::getCredentialFields();
    }

    /**
     * Retourne les endpoints d'un provider.
     *
     * @param  string $type  Type de provider
     * @return array<string,string>
     */
    public static function getEndpoints(string $type): array
    {
        if (!isset(self::PROVIDERS[$type])) {
            return [];
        }
        $class = self::PROVIDERS[$type];
        if (!class_exists($class)) {
            return [];
        }
        return $class::getEndpoints();
    }

    /**
     * Vérifie si un type de provider est supporté.
     */
    public static function isSupported(string $type): bool
    {
        return isset(self::PROVIDERS[$type]);
    }
}
