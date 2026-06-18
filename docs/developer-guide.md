# DNS Hébergeurs — Guide développeur : Ajouter un provider

## Architecture

Chaque provider DNS implémente l'interface `GlpiPlugin\Dnsmanager\ProviderInterface` et est enregistré dans `ProviderFactory`.

```
src/
├── ProviderInterface.php   ← Interface à implémenter
├── ProviderFactory.php     ← Registre des providers
├── OvhProvider.php         ← Exemple : provider OVH
└── GandiProvider.php       ← Exemple : provider Gandi
```

---

## Interface à implémenter

```php
namespace GlpiPlugin\Dnsmanager;

interface ProviderInterface
{
    // Constructeur obligatoire
    public function __construct(array $credentials, string $endpoint = '');

    // Métadonnées du provider (affichage dans le formulaire)
    public static function getLabel(): string;
    public static function getEndpoints(): array;       // ['key' => 'Label affiché']
    public static function getCredentialFields(): array; // Champs de saisie credentials

    // API
    public function testConnection(): bool;
    public function listDomains(): array;               // Retourne les domaines gérés
    public function getDomainInfo(string $domainRef): array; // Dates création/expiration
    public function listRecords(string $zoneRef): array; // Enregistrements DNS

    // Modifications (optionnel pour v1, retourner false/'' si non supporté)
    public function createRecord(string $zoneRef, array $data): string;
    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool;
    public function deleteRecord(string $zoneRef, string $recordRef): bool;
}
```

---

## Format des données

### `getEndpoints()` — Endpoints disponibles

```php
public static function getEndpoints(): array
{
    return [
        'monprovider-eu' => 'MonProvider Europe',
        'monprovider-us' => 'MonProvider USA',
    ];
}
```

### `getCredentialFields()` — Champs de saisie

```php
public static function getCredentialFields(): array
{
    return [
        [
            'key'      => 'api_key',      // Nom du champ (clé en base)
            'label'    => 'Clé API',      // Label affiché
            'type'     => 'password',     // 'text' ou 'password'
            'required' => true,
            'help'     => 'Créer sur https://...', // Texte d'aide
        ],
        [
            'key'      => 'api_secret',
            'label'    => 'Secret API',
            'type'     => 'password',
            'required' => true,
            'help'     => '',
        ],
    ];
}
```

### `listDomains()` — Liste des domaines

```php
public function listDomains(): array
{
    return [
        [
            'name' => 'exemple.fr',   // Nom complet du domaine
            'ref'  => 'exemple.fr',   // Référence unique chez le provider
        ],
        // ...
    ];
}
```

### `getDomainInfo()` — Informations du domaine

```php
public function getDomainInfo(string $domainRef): array
{
    return [
        'creation_date'   => '2020-01-15T10:00:00Z', // ISO 8601 ou vide
        'expiration_date' => '2026-01-15T10:00:00Z', // ISO 8601 ou vide
    ];
}
```

### `listRecords()` — Enregistrements DNS

```php
public function listRecords(string $zoneRef): array
{
    return [
        [
            'name'   => '@',            // Nom du record (@ = racine)
            'type'   => 'A',            // Type DNS (A, AAAA, MX, TXT, CNAME...)
            'ttl'    => 3600,           // TTL en secondes
            'target' => '1.2.3.4',     // Valeur/destination
            'ref'    => 'unique-id',   // Référence unique chez le provider
        ],
        // ...
    ];
}
```

---

## Créer un nouveau provider

### Étape 1 — Créer le fichier

Créez `src/MonProvider.php` :

```php
<?php

namespace GlpiPlugin\Dnsmanager;

class MonProvider implements ProviderInterface
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(array $credentials, string $endpoint = 'monprovider-eu')
    {
        $this->apiKey  = $credentials['api_key'] ?? '';
        $this->baseUrl = match($endpoint) {
            'monprovider-eu' => 'https://api.monprovider.eu/v1',
            'monprovider-us' => 'https://api.monprovider.us/v1',
            default          => 'https://api.monprovider.eu/v1',
        };
    }

    public static function getLabel(): string { return 'MonProvider'; }

    public static function getEndpoints(): array
    {
        return [
            'monprovider-eu' => 'MonProvider Europe',
            'monprovider-us' => 'MonProvider USA',
        ];
    }

    public static function getCredentialFields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'Clé API', 'type' => 'password', 'required' => true, 'help' => ''],
        ];
    }

    public function testConnection(): bool
    {
        $response = $this->request('GET', '/domains?limit=1');
        return is_array($response);
    }

    public function listDomains(): array
    {
        $data = $this->request('GET', '/domains');
        $domains = [];
        foreach ($data as $domain) {
            $domains[] = ['name' => $domain['name'], 'ref' => $domain['id']];
        }
        return $domains;
    }

    public function getDomainInfo(string $domainRef): array
    {
        $data = $this->request('GET', "/domains/{$domainRef}");
        return [
            'creation_date'   => $data['created_at'] ?? '',
            'expiration_date' => $data['expires_at'] ?? '',
        ];
    }

    public function listRecords(string $zoneRef): array
    {
        $data = $this->request('GET', "/zones/{$zoneRef}/records");
        $records = [];
        foreach ($data as $record) {
            $records[] = [
                'name'   => $record['name'],
                'type'   => $record['type'],
                'ttl'    => (int)$record['ttl'],
                'target' => $record['value'],
                'ref'    => $record['id'],
            ];
        }
        return $records;
    }

    public function createRecord(string $zoneRef, array $data): string { return ''; }
    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool { return false; }
    public function deleteRecord(string $zoneRef, string $recordRef): bool { return false; }

    private function request(string $method, string $path): mixed
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: GLPI-DNSManage/1.0',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) throw new \RuntimeException("cURL error: $error");
        if ($httpCode >= 400) throw new \RuntimeException("API error $httpCode: $body");
        return json_decode($body, true);
    }
}
```

### Étape 2 — Enregistrer dans ProviderFactory

Dans `src/ProviderFactory.php`, ajoutez votre provider dans la constante `PROVIDERS` :

```php
private const PROVIDERS = [
    'ovh'         => OvhProvider::class,
    'gandi'       => GandiProvider::class,
    'monprovider' => MonProvider::class,  // ← Ajouter cette ligne
];
```

### Étape 3 — Déployer et tester

```bash
# Copier le fichier
sudo cp src/MonProvider.php /var/glpi/plugins/dnsmanager/src/
sudo cp src/ProviderFactory.php /var/glpi/plugins/dnsmanager/src/

# Vider le cache
sudo rm -rf /var/glpi/files/_cache/11.0.4-*/

# Tester via l'interface GLPI
# Outils → DNS Hébergeurs → + Ajouter → Provider : MonProvider
```

---

## Conseils

- **Pagination** : gérez la pagination de l'API pour `listDomains()` et `listRecords()`
- **Cache** : utilisez `$this->domainCache` pour éviter les doubles appels API comme dans `GandiProvider`
- **RDAP** : si l'API ne retourne pas les dates, utilisez RDAP (voir `OvhProvider::fetchRdap()`)
- **Erreurs** : lancez des exceptions `\RuntimeException` — elles sont capturées par l'`Importer`
- **TTL** : retournez `0` si inconnu — GLPI affichera la valeur brute

---

## Structure de la base de données

| Table | Description |
|-------|-------------|
| `glpi_plugin_dnsmanager_accounts` | Comptes providers |
| `glpi_plugin_dnsmanager_credentials` | Credentials chiffrés (AES-256) |
| `glpi_plugin_dnsmanager_domains` | Mapping domaines provider ↔ GLPI |
| `glpi_plugin_dnsmanager_records` | Mapping records DNS provider ↔ GLPI |
| `glpi_plugin_dnsmanager_synclogs` | Journal des synchronisations |
| `glpi_plugin_dnsmanager_configs` | Configuration du plugin |
