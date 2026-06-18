<?php

namespace GlpiPlugin\Dnsmanager;

class OvhProvider implements ProviderInterface
{
    private const OVH_ENDPOINTS = [
        'ovh-eu'        => 'https://eu.api.ovh.com/1.0',
        'ovh-ca'        => 'https://ca.api.ovh.com/1.0',
        'ovh-us'        => 'https://api.us.ovhcloud.com/1.0',
        'soyoustart-eu' => 'https://eu.api.soyoustart.com/1.0',
        'soyoustart-ca' => 'https://ca.api.soyoustart.com/1.0',
        'kimsufi-eu'    => 'https://eu.api.kimsufi.com/1.0',
        'kimsufi-ca'    => 'https://ca.api.kimsufi.com/1.0',
    ];

    private string $endpoint;
    private string $appKey;
    private string $appSecret;
    private string $consumerKey;
    private ?int   $timeDelta = null;

    public function __construct(array $credentials, string $endpoint = 'ovh-eu')
    {
        $this->appKey      = $credentials['app_key']      ?? '';
        $this->appSecret   = $credentials['app_secret']   ?? '';
        $this->consumerKey = $credentials['consumer_key'] ?? '';
        $this->endpoint    = self::OVH_ENDPOINTS[$endpoint] ?? self::OVH_ENDPOINTS['ovh-eu'];
    }

    public static function getLabel(): string { return 'OVH / OVHcloud'; }

    public static function getEndpoints(): array
    {
        return [
            'ovh-eu'        => 'OVH Europe (ovh-eu)',
            'ovh-ca'        => 'OVH Canada (ovh-ca)',
            'ovh-us'        => 'OVHcloud US (ovh-us)',
            'soyoustart-eu' => 'So you Start Europe',
            'soyoustart-ca' => 'So you Start Canada',
            'kimsufi-eu'    => 'Kimsufi Europe',
            'kimsufi-ca'    => 'Kimsufi Canada',
        ];
    }

    public static function getCredentialFields(): array
    {
        return [
            ['key' => 'app_key',      'label' => 'Application Key (AK)',     'type' => 'text',     'required' => true, 'help' => 'Créer sur https://eu.api.ovh.com/createApp'],
            ['key' => 'app_secret',   'label' => 'Application Secret (AS)',  'type' => 'password', 'required' => true, 'help' => 'Fourni lors de la création'],
            ['key' => 'consumer_key', 'label' => 'Consumer Key (CK)',        'type' => 'password', 'required' => true, 'help' => 'Généré après validation des droits API'],
        ];
    }

    public function testConnection(): bool
    {
        $result = $this->get('/me');
        if (!isset($result['nichandle'])) {
            throw new \RuntimeException('Réponse inattendue de l\'API OVH.');
        }
        return true;
    }

    public function listDomains(): array
    {
        $zones = $this->get('/domain/zone');
        if (!is_array($zones)) return [];
        $domains = [];
        foreach ($zones as $zone) {
            try {
                $info = $this->get('/domain/zone/' . urlencode((string)$zone));
                $domains[] = [
                    'ref'     => (string)$zone,
                    'name'    => (string)$zone,
                    'comment' => isset($info['lastUpdate']) ? 'Dernière MAJ OVH : ' . $info['lastUpdate'] : '',
                ];
            } catch (\Exception) {
                $domains[] = ['ref' => (string)$zone, 'name' => (string)$zone, 'comment' => ''];
            }
        }
        return $domains;
    }

    public function listRecords(string $zoneRef): array
    {
        $recordIds = $this->get('/domain/zone/' . urlencode($zoneRef) . '/record');
        if (!is_array($recordIds)) return [];
        $records = [];
        foreach ($recordIds as $recordId) {
            try {
                $rec = $this->get('/domain/zone/' . urlencode($zoneRef) . '/record/' . (int)$recordId);
                $records[] = [
                    'ref'      => (string)$recordId,
                    'name'     => $this->normalizeSubdomain((string)($rec['subDomain'] ?? ''), $zoneRef),
                    'type'     => strtoupper((string)($rec['fieldType'] ?? '')),
                    'target'   => (string)($rec['target'] ?? ''),
                    'ttl'      => (int)($rec['ttl'] ?? 0),
                    'priority' => 0,
                ];
            } catch (\Exception) {}
        }
        return $records;
    }

    public function getDomainInfo(string $zoneRef): array
    {
        $info = ['creation_date' => '', 'expiration_date' => '', 'admin_contact' => '', 'tech_contact' => '', 'billing_contact' => ''];

        // Dates via RDAP (bootstrap IANA)
        $rdapDates = $this->getRdapDates($zoneRef);
        $info['creation_date']   = $rdapDates['creation_date'];
        $info['expiration_date'] = $rdapDates['expiration_date'];

        // Contacts via API OVH
        try {
            $contacts = $this->get('/domain/' . urlencode($zoneRef) . '/contacts');
            if (is_array($contacts)) {
                foreach ($contacts as $contact) {
                    $type = strtolower($contact['type'] ?? '');
                    $nic  = $contact['contactId'] ?? $contact['nic'] ?? '';
                    match ($type) {
                        'admin', 'administrator' => $info['admin_contact']   = $nic,
                        'tech',  'technical'     => $info['tech_contact']    = $nic,
                        'billing'                => $info['billing_contact'] = $nic,
                        default                  => null,
                    };
                }
            }
        } catch (\Exception) {}

        return $info;
    }

    // Phase 3
    public function createRecord(string $zoneRef, array $data): string
    {
        $result = $this->rawCall('POST', '/domain/zone/' . urlencode($zoneRef) . '/record', [
            'fieldType' => $data['type'],
            'subDomain' => $data['name'] === $zoneRef ? '' : $data['name'],
            'target'    => $data['target'],
            'ttl'       => $data['ttl'] ?? 0,
        ]);
        $this->rawCall('POST', '/domain/zone/' . urlencode($zoneRef) . '/refresh', null);
        return (string)($result['id'] ?? '');
    }

    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool
    {
        $this->rawCall('PUT', '/domain/zone/' . urlencode($zoneRef) . '/record/' . (int)$recordRef, [
            'subDomain' => $data['name']   ?? '',
            'target'    => $data['target'] ?? '',
            'ttl'       => $data['ttl']    ?? 0,
        ]);
        $this->rawCall('POST', '/domain/zone/' . urlencode($zoneRef) . '/refresh', null);
        return true;
    }

    public function deleteRecord(string $zoneRef, string $recordRef): bool
    {
        $this->rawCall('DELETE', '/domain/zone/' . urlencode($zoneRef) . '/record/' . (int)$recordRef, null);
        $this->rawCall('POST', '/domain/zone/' . urlencode($zoneRef) . '/refresh', null);
        return true;
    }

    // ------------------------------------------------------------------
    // HTTP interne
    // ------------------------------------------------------------------

    private function get(string $path): mixed   { return $this->rawCall('GET',    $path, null); }
    private function post(string $path, ?array $body): mixed { return $this->rawCall('POST', $path, $body); }

    private function rawCall(string $method, string $path, ?array $content): mixed
    {
        $url  = $this->endpoint . $path;
        $body = ($content !== null && $method !== 'GET') ? json_encode($content, JSON_UNESCAPED_SLASHES) : '';
        $now  = time() + $this->getTimeDelta();

        $toSign    = $this->appSecret . '+' . $this->consumerKey . '+' . strtoupper($method) . '+' . $url . '+' . $body . '+' . $now;
        $signature = '$1$' . sha1($toSign);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-Ovh-Application: ' . $this->appKey,
            'X-Ovh-Consumer: '    . $this->consumerKey,
            'X-Ovh-Signature: '   . $signature,
            'X-Ovh-Timestamp: '   . $now,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) throw new \RuntimeException('Erreur cURL : ' . $curlError);
        if ($response === '' || $response === false) return null;

        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $data['message'] ?? ($data['errorCode'] ?? ('Erreur HTTP ' . $httpCode));
            throw new \RuntimeException('[OVH ' . $httpCode . '] ' . $msg);
        }
        return $data;
    }

    private function getTimeDelta(): int
    {
        if ($this->timeDelta === null) {
            try {
                $raw = $this->curlGet($this->endpoint . '/auth/time');
                $ts  = (int)json_decode($raw ?? '0', true);
                $this->timeDelta = $ts > 0 ? $ts - time() : 0;
            } catch (\Exception) {
                $this->timeDelta = 0;
            }
        }
        return $this->timeDelta;
    }

    private function getRdapDates(string $domain): array
    {
        $result = ['creation_date' => '', 'expiration_date' => ''];
        try {
            static $bootstrapCache = null;
            if ($bootstrapCache === null) {
                $raw = $this->curlGet('https://data.iana.org/rdap/dns.json');
                if ($raw === null) return $result;
                $bootstrapCache = json_decode($raw, true);
            }
            $tld      = strtolower(ltrim(strrchr($domain, '.'), '.'));
            $rdapBase = null;
            foreach ($bootstrapCache['services'] ?? [] as $service) {
                if (in_array($tld, $service[0] ?? [], true)) {
                    $rdapBase = rtrim($service[1][0] ?? '', '/');
                    break;
                }
            }
            if ($rdapBase === null) return $result;

            $raw  = $this->curlGet($rdapBase . '/domain/' . strtolower($domain));
            $data = json_decode($raw ?? '{}', true);
            foreach ($data['events'] ?? [] as $event) {
                match (strtolower($event['eventAction'] ?? '')) {
                    'registration' => $result['creation_date']   = $event['eventDate'] ?? '',
                    'expiration'   => $result['expiration_date'] = $event['eventDate'] ?? '',
                    default        => null,
                };
            }
        } catch (\Exception) {}
        return $result;
    }

    private function curlGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'DNSManage/2.0 GLPI-Plugin',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($response && $httpCode >= 200 && $httpCode < 300) ? $response : null;
    }

    private function normalizeSubdomain(string $sub, string $zone): string
    {
        if ($sub === '' || $sub === '@') return $zone;
        return $sub . '.' . $zone;
    }
}
