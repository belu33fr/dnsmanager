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
                'help'     => 'Créer sur https://admin.gandi.net → Sécurité → Personal Access Tokens. Un token = une seule organisation Gandi : créez un compte DNS Hébergeurs distinct par organisation.',
            ],
        ];
    }

    /** @var array Dernier diagnostic de connexion (orgs trouvées, erreurs) */
    private array $lastDiagnostic = [];

    public function testConnection(): bool
    {
        $response = $this->request('GET', '/domain/domains?per_page=1');

        // Diagnostic : lister les organisations détectées pour vérification
        $this->lastDiagnostic = ['personal' => true, 'organizations' => [], 'org_error' => null];
        try {
            $orgs = $this->request('GET', '/organization/organizations');
            if (is_array($orgs)) {
                foreach ($orgs as $org) {
                    $this->lastDiagnostic['organizations'][] = ($org['name'] ?? '?') . ' (' . ($org['id'] ?? '?') . ')';
                }
            }
        } catch (\Exception $e) {
            $this->lastDiagnostic['org_error'] = $e->getMessage();
        }

        return is_array($response);
    }

    /**
     * Retourne un message lisible listant le compte personnel + les organisations
     * détectées, à afficher lors du test de connexion pour vérification.
     */
    public function getDiagnosticMessage(): string
    {
        if (empty($this->lastDiagnostic)) return '';

        $lines = ['Compte personnel : détecté'];

        if (!empty($this->lastDiagnostic['org_error'])) {
            $lines[] = 'Organisations : erreur lors de la détection — ' . $this->lastDiagnostic['org_error'];
        } elseif (empty($this->lastDiagnostic['organizations'])) {
            $lines[] = 'Organisations : aucune détectée';
        } else {
            $lines[] = 'Organisations détectées (' . count($this->lastDiagnostic['organizations']) . ') :';
            foreach ($this->lastDiagnostic['organizations'] as $org) {
                $lines[] = '  - ' . $org;
            }
        }

        return implode("\n", $lines);
    }

    /** Références des domaines du compte (utilisé pour les statistiques). */
    public function listDomainRefs(): array
    {
        return array_map(static fn(array $d): string => (string)$d['ref'], $this->listDomains());
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
                $fqdn       = $domain['fqdn'] ?? '';
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

        $info = [
            'creation_date'   => $dates['registry_created_at'] ?? $dates['created_at'] ?? '',
            'expiration_date' => $dates['registry_ends_at'] ?? '',
        ];

        // Contacts : Gandi n'a pas de nichandle comme OVH, mais de vraies fiches
        // contact identifiées par email. Convention retenue :
        //   name (clé de recherche/dédoublonnage) = "Gandi-" + email
        //   login (stocké en plus)                = email brut
        try {
            $contacts = $this->request('GET', "/domain/domains/{$domainRef}/contacts");
            if (is_array($contacts)) {
                $map = [
                    'admin' => 'admin_contact',
                    'tech'  => 'tech_contact',
                    'bill'  => 'billing_contact',
                ];
                foreach ($map as $gandiKey => $infoKey) {
                    $email = trim((string)($contacts[$gandiKey]['email'] ?? ''));
                    if ($email === '') continue;
                    $info[$infoKey]            = 'Gandi-' . $email;
                    $info[$infoKey . '_login'] = $email;
                }
            }
        } catch (\Exception) {}

        return $info;
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

    /**
     * Gandi n'a pas de notion de "TTL par défaut de zone" configurable
     * comme le SOA TTL d'OVH : chaque enregistrement LiveDNS a toujours
     * un rrset_ttl explicite (10800 par défaut à la création). Il n'y a
     * donc pas de valeur "0 = hérite du défaut" à résoudre côté Gandi.
     */
    public function getZoneDefaultTtl(string $zoneRef): ?int
    {
        return null;
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

        $__t0 = microtime(true);
        $body     = curl_exec($ch);
        ApiMetrics::record(microtime(true) - $__t0);
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
