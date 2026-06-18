<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Provider Gandi API v5
 * Doc: https://api.gandi.net/docs/
 * Auth: Bearer (Personal Access Token)
 */
class GandiProvider implements ProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.gandi.net/v5';

    // Cache des infos domaines pour éviter les doubles appels
    private array $domainCache = [];

    public function __construct(array $credentials, string $endpoint = 'gandi-v5')
    {
        $this->apiKey = $credentials['api_key'] ?? '';

        if ($endpoint === 'gandi-sandbox') {
            $this->baseUrl = 'https://api.sandbox.gandi.net/v5';
        }
    }

    public static function getLabel(): string
    {
        return 'Gandi';
    }

    public static function getEndpoints(): array
    {
        return [
            'gandi-v5'      => 'Gandi Production (api.gandi.net)',
            'gandi-sandbox' => 'Gandi Sandbox (api.sandbox.gandi.net)',
        ];
    }

    public static function getCredentialFields(): array
    {
        return [
            [
                'key'      => 'api_key',
                'label'    => 'Personal Access Token (PAT)',
                'type'     => 'password',
                'required' => true,
                'help'     => 'Créer sur https://admin.gandi.net → Sécurité → Personal Access Tokens',
            ],
        ];
    }

    public function testConnection(): bool
    {
        $response = $this->request('GET', '/domain/domains?per_page=1');
        return is_array($response);
    }

    public function listDomains(): array
    {
        $domains = [];
        $page    = 1;
        $perPage = 100;

        do {
            $data = $this->request('GET', "/domain/domains?page={$page}&per_page={$perPage}");
            if (!is_array($data)) break;

            foreach ($data as $domain) {
                $fqdn      = $domain['fqdn'] ?? '';
                $nameserver = $domain['nameserver']['current'] ?? '';

                if (!$fqdn) continue;

                // Mettre en cache les infos domaine (dates, etc.)
                $this->domainCache[$fqdn] = $domain;

                // Ne retourner que les domaines utilisant LiveDNS Gandi
                // Les domaines avec nameserver "other" sont gérés par un tiers
                if ($nameserver !== 'livedns') continue;

                $domains[] = [
                    'name' => $fqdn,
                    'ref'  => $fqdn,
                ];
            }

            $page++;
        } while (count($data) === $perPage);

        return $domains;
    }

    public function getDomainInfo(string $domainRef): array
    {
        // Utiliser le cache si disponible
        if (isset($this->domainCache[$domainRef])) {
            $domain = $this->domainCache[$domainRef];
        } else {
            $domain = $this->request('GET', "/domain/domains/{$domainRef}");
        }

        if (!is_array($domain)) return [];

        $dates = $domain['dates'] ?? [];

        return [
            'creation_date'   => $dates['registry_created_at'] ?? $dates['created_at'] ?? '',
            'expiration_date' => $dates['registry_ends_at'] ?? '',
        ];
    }

    public function listRecords(string $zoneRef): array
    {
        $data = $this->request('GET', "/livedns/domains/{$zoneRef}/records");
        if (!is_array($data)) return [];

        $records = [];
        foreach ($data as $rrset) {
            $name   = $rrset['rrset_name'] ?? '@';
            $type   = strtoupper($rrset['rrset_type'] ?? '');
            $ttl    = (int)($rrset['rrset_ttl'] ?? 10800);
            $values = $rrset['rrset_values'] ?? [];

            // Ignorer SOA (géré automatiquement par Gandi)
            if ($type === 'SOA') continue;

            foreach ($values as $idx => $value) {
                $records[] = [
                    'name'   => $name,
                    'type'   => $type,
                    'ttl'    => $ttl,
                    'target' => $value,
                    'ref'    => $zoneRef . '/' . $name . '/' . $type . '/' . $idx,
                ];
            }
        }

        return $records;
    }

    public function createRecord(string $zoneRef, array $data): string { return ''; }
    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool { return false; }
    public function deleteRecord(string $zoneRef, string $recordRef): bool { return false; }

    private function request(string $method, string $path): mixed
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: GLPI-DNSManage/1.0',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("cURL error: $error");
        }

        if ($httpCode >= 400) {
            $msg = json_decode($body, true)['message']
                ?? json_decode($body, true)['cause']
                ?? $body;
            throw new \RuntimeException("Gandi API error $httpCode: $msg");
        }

        return json_decode($body, true);
    }
}
