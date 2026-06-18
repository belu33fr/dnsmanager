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

    // Phase 3
    public function createRecord(string $zoneRef, array $data): string;
    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool;
    public function deleteRecord(string $zoneRef, string $recordRef): bool;
}
