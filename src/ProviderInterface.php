<?php

namespace GlpiPlugin\Dnsmanager;

interface ProviderInterface
{
    public function __construct(array $credentials, string $endpoint = '');
    public function testConnection(): bool;

    public static function getLabel(): string;
    public static function getEndpoints(): array;
    public static function getCredentialFields(): array;

    public function listDomains(): array;
    public function listRecords(string $zoneRef): array;
    public function getDomainInfo(string $zoneRef): array;

    /**
     * Retourne le TTL par défaut de la zone (SOA TTL), utilisé par les
     * enregistrements ayant un TTL à 0 (= "hérite du défaut de zone").
     * Retourne null si le provider n'expose pas cette notion ou en cas d'échec.
     */
    public function getZoneDefaultTtl(string $zoneRef): ?int;

    // Phase 3
    public function createRecord(string $zoneRef, array $data): string;
    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool;
    public function deleteRecord(string $zoneRef, string $recordRef): bool;
}
