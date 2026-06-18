<?php

namespace GlpiPlugin\Dnsmanager;

class ProviderFactory
{
    private const PROVIDERS = [
        'ovh'   => OvhProvider::class,
        'gandi' => GandiProvider::class,
    ];

    public static function create(string $type, array $credentials, string $endpoint = ''): ProviderInterface
    {
        if (!isset(self::PROVIDERS[$type])) {
            throw new \InvalidArgumentException("Provider '$type' non supporté.");
        }
        $class = self::PROVIDERS[$type];
        return new $class($credentials, $endpoint);
    }

    public static function getAvailableProviders(): array
    {
        $result = [];
        foreach (self::PROVIDERS as $type => $class) {
            $result[$type] = $class::getLabel();
        }
        return $result;
    }

    public static function getCredentialFields(string $type): array
    {
        return isset(self::PROVIDERS[$type]) ? self::PROVIDERS[$type]::getCredentialFields() : [];
    }

    public static function getEndpoints(string $type): array
    {
        return isset(self::PROVIDERS[$type]) ? self::PROVIDERS[$type]::getEndpoints() : [];
    }

    public static function isSupported(string $type): bool
    {
        return isset(self::PROVIDERS[$type]);
    }
}
