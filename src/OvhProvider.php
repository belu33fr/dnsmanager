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
    private array  $lastDiagnostic = [];

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

        // Vérification des droits d'écriture (modification bidirectionnelle) — introspection
        // pure via /auth/currentCredential, sans aucune action réelle sur un domaine.
        $this->lastDiagnostic = [];
        try {
            $credential = $this->get('/auth/currentCredential');
            $rules = $credential['rules'] ?? [];

            $hasWrite = ['POST' => false, 'PUT' => false, 'DELETE' => false];
            foreach ($rules as $rule) {
                $method = strtoupper($rule['method'] ?? '');
                $path   = $rule['path'] ?? '';
                if (!isset($hasWrite[$method])) continue;
                // Le chemin doit couvrir /domain/zone/* (accepte aussi un chemin plus large comme /domain/*)
                if (str_starts_with('/domain/zone/', rtrim($path, '*')) || $path === '/domain/zone/*' || $path === '/*') {
                    $hasWrite[$method] = true;
                }
            }

            $this->lastDiagnostic['write_rights'] = $hasWrite;
        } catch (\Exception $e) {
            $this->lastDiagnostic['write_rights_error'] = $e->getMessage();
        }

        return true;
    }

    public function getDiagnosticMessage(): string
    {
        if (empty($this->lastDiagnostic)) return '';

        $lines = [];

        if (!empty($this->lastDiagnostic['write_rights_error'])) {
            $lines[] = 'Droits d\'écriture (modification bidirectionnelle) : impossible à vérifier — ' . $this->lastDiagnostic['write_rights_error'];
        } elseif (isset($this->lastDiagnostic['write_rights'])) {
            $w = $this->lastDiagnostic['write_rights'];
            $allGranted = $w['POST'] && $w['PUT'] && $w['DELETE'];
            if ($allGranted) {
                $lines[] = 'Droits d\'écriture (modification bidirectionnelle) : OK — POST/PUT/DELETE sur /domain/zone/* accordés.';
            } else {
                $missing = [];
                foreach (['POST', 'PUT', 'DELETE'] as $m) {
                    if (!$w[$m]) $missing[] = $m;
                }
                $lines[] = 'Droits d\'écriture (modification bidirectionnelle) : INCOMPLETS — méthode(s) manquante(s) sur /domain/zone/* : ' . implode(', ', $missing) . '. La lecture/synchronisation classique fonctionne normalement, mais la modification bidirectionnelle échouera (403) tant que ces droits ne sont pas ajoutés à la Consumer Key.';
            }
        }

        return implode("\n", $lines);
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
        $failures = [];
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
            } catch (\Exception $e) {
                // NE PLUS avaler silencieusement : un échec ponctuel (rate limiting,
                // timeout...) sur UN enregistrement ne doit jamais laisser croire que
                // la liste complète est fiable — sinon la logique de nettoyage de la
                // synchro (records absents d'OVH -> corbeille) supprimerait à tort des
                // enregistrements bien réels, juste temporairement inaccessibles.
                $failures[] = (string)$recordId . ' (' . $e->getMessage() . ')';
            }
        }

        if (!empty($failures)) {
            throw new \RuntimeException(
                'Liste des enregistrements incomplète pour la zone "' . $zoneRef . '" : '
                . count($failures) . ' enregistrement(s) sur ' . count($recordIds) . ' n\'ont pas pu être récupérés ('
                . implode(', ', array_slice($failures, 0, 5)) . (count($failures) > 5 ? ', ...' : '')
                . '). Synchronisation de ce domaine annulée par sécurité pour éviter une suppression erronée.'
            );
        }

        return $records;
    }

    public function getDomainInfo(string $zoneRef): array
    {
        $info = ['creation_date' => '', 'expiration_date' => '', 'admin_contact' => '', 'tech_contact' => '', 'billing_contact' => ''];

        // Dates + contacts en un seul appel via l'objet Service générique OVH.
        // NB: /domain/{zoneRef}/contacts n'existe pas — le bon endpoint est serviceInfos.
        try {
            $serviceInfos = $this->get('/domain/' . urlencode($zoneRef) . '/serviceInfos');
            if (is_array($serviceInfos)) {
                $info['admin_contact']   = trim((string)($serviceInfos['contactAdmin']   ?? ''));
                $info['tech_contact']    = trim((string)($serviceInfos['contactTech']    ?? ''));
                $info['billing_contact'] = trim((string)($serviceInfos['contactBilling'] ?? ''));
                $info['creation_date']   = trim((string)($serviceInfos['creation']       ?? ''));
                $info['expiration_date'] = trim((string)($serviceInfos['expiration']     ?? ''));
            }
        } catch (\Exception) {}

        // Repli sur RDAP si serviceInfos n'a pas donné de dates — cas d'un domaine
        // dont la zone DNS est hébergée chez OVH mais enregistré chez un autre registrar
        // (serviceInfos ne concerne que les services facturés par OVH).
        if ($info['creation_date'] === '' || $info['expiration_date'] === '') {
            $rdapDates = $this->getRdapDates($zoneRef);
            if ($info['creation_date'] === '')   $info['creation_date']   = $rdapDates['creation_date'];
            if ($info['expiration_date'] === '') $info['expiration_date'] = $rdapDates['expiration_date'];
        }

        return $info;
    }

    /**
     * TTL par défaut de la zone (SOA TTL) — utilisé par les enregistrements
     * dont le TTL est à 0 (= "hérite du défaut de zone" chez OVH).
     * @see https://eu.api.ovh.com/console/#/domain/zone/{zoneName}/soa#GET
     */
    public function getZoneDefaultTtl(string $zoneRef): ?int
    {
        try {
            $soa = $this->get('/domain/zone/' . urlencode($zoneRef) . '/soa');
            if (is_array($soa) && isset($soa['ttl'])) {
                return (int)$soa['ttl'];
            }
        } catch (\Exception) {}

        return null;
    }

    // Phase 3
    public function createRecord(string $zoneRef, array $data): string
    {
        $result = $this->rawCall('POST', '/domain/zone/' . urlencode($zoneRef) . '/record', [
            'fieldType' => $data['type'],
            'subDomain' => $this->denormalizeSubdomain($data['name'], $zoneRef),
            'target'    => $data['target'],
            'ttl'       => $data['ttl'] ?? 0,
        ]);
        $this->rawCall('POST', '/domain/zone/' . urlencode($zoneRef) . '/refresh', null);
        return (string)($result['id'] ?? '');
    }

    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool
    {
        $payload = [
            'target' => $data['target'] ?? '',
            'ttl'    => $data['ttl']    ?? 0,
        ];
        // subDomain (nom) uniquement si fourni — OVH accepte un renommage via PUT
        if (isset($data['name'])) {
            $payload['subDomain'] = $this->denormalizeSubdomain($data['name'], $zoneRef);
        }
        $this->rawCall('PUT', '/domain/zone/' . urlencode($zoneRef) . '/record/' . (int)$recordRef, $payload);
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

    /**
     * Inverse de normalizeSubdomain() : reconstruit le "subDomain" attendu par
     * l'API OVH (forme courte, ex: "www") à partir du name complet (FQDN)
     * stocké côté GLPI (ex: "www.example.com").
     */
    private function denormalizeSubdomain(string $fqdn, string $zone): string
    {
        if ($fqdn === $zone) return '';
        $suffix = '.' . $zone;
        if (str_ends_with($fqdn, $suffix)) {
            return substr($fqdn, 0, -strlen($suffix));
        }
        // Cas inattendu (name ne se termine pas par la zone) : renvoyer tel quel,
        // OVH renverra une erreur explicite plutôt qu'un mauvais enregistrement silencieux.
        return $fqdn;
    }
}
