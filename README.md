# DNSManage — Plugin GLPI de gestion DNS multi-provider

> Version 1.0.0 | Compatible GLPI 11.x | PHP 8.1+

Plugin GLPI permettant d'importer et de synchroniser automatiquement les domaines et enregistrements DNS depuis plusieurs providers (OVH, Gandi, Cloudflare, etc.) directement dans le module DNS natif de GLPI.

---

## Fonctionnalités

- **Import multi-provider** : architecture extensible via `ProviderInterface`
- **Provider OVH inclus** : support de tous les endpoints OVH (EU, CA, US, SoYouStart, Kimsufi)
- **Synchronisation** manuelle (bouton) et automatique (tâche CRON GLPI)
- **Credentials chiffrés** en AES-256-CBC, jamais stockés en clair
- **Mapping** entre les domaines/enregistrements provider et les entités GLPI natives (`glpi_domains`, `glpi_domainrecords`)
- **Journal de synchronisation** complet (ajouts, mises à jour, erreurs)
- **Multi-entités GLPI** : chaque compte est rattaché à une entité
- **Phase 3 prête** : architecture prévue pour la modification des enregistrements (non activée en UI)

---

## Prérequis

| Composant | Version minimum |
|-----------|----------------|
| GLPI      | 11.0.0         |
| PHP       | 8.1            |
| Extension PHP | curl, json, openssl |
| MySQL/MariaDB | 5.7 / 10.3  |

---

## Installation

1. Copier le dossier `dnsmanager/` dans `{GLPI}/plugins/`
2. Se connecter à GLPI en administrateur
3. Aller dans **Configuration → Plugins**
4. Cliquer **Installer** puis **Activer** sur DNSManage

---

## Configuration OVH

### 1. Créer une application OVH

Rendez-vous sur https://eu.api.ovh.com/createApp et créez une application.
Vous obtenez :
- `Application Key (AK)`
- `Application Secret (AS)`

### 2. Générer un Consumer Key

```bash
curl -X POST https://eu.api.ovh.com/1.0/auth/credential \
  -H "X-Ovh-Application: VOTRE_APP_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "accessRules": [
      {"method": "GET", "path": "/domain"},
      {"method": "GET", "path": "/domain/*"},
      {"method": "GET", "path": "/me"}
    ],
    "redirection": "https://votre-glpi.exemple.fr"
  }'
```

Ouvrez l'URL `validationUrl` retournée dans votre navigateur et validez.
Récupérez le `consumerKey` retourné.

> **Pour activer la Phase 3** (modification), ajouter les droits `PUT`, `POST`, `DELETE` sur `/domain/*`.

### 3. Configurer dans GLPI

- Menu **Outils → DNSManage → Ajouter un compte**
- Saisir : Nom, Provider = OVH, Endpoint, AK, AS, CK
- Tester la connexion
- Lancer la première synchronisation

---

## Ajouter un nouveau provider

### 1. Créer la classe

```php
// plugins/dnsmanager/inc/providers/GandiProvider.php

class PluginDnsmanageGandiProvider implements PluginDnsmanageProviderInterface
{
    public function __construct(array $credentials, string $endpoint = '') { ... }
    public static function getLabel(): string { return 'Gandi'; }
    public static function getEndpoints(): array { return []; }  // URL libre
    public static function getCredentialFields(): array {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'type' => 'password', 'required' => true],
        ];
    }
    public function testConnection(): bool { ... }
    public function listDomains(): array { ... }
    public function listRecords(string $zoneRef): array { ... }
    // + méthodes Phase 3
}
```

### 2. Déclarer dans la Factory

```php
// inc/providers/ProviderFactory.php
private const PROVIDERS = [
    'ovh'   => 'PluginDnsmanageOvhProvider',
    'gandi' => 'PluginDnsmanageGandiProvider',  // ← ajouter
];
```

**C'est tout.** Le formulaire UI se génère automatiquement.

---

## Structure des tables

| Table | Rôle |
|-------|------|
| `glpi_plugin_dnsmanager_accounts` | Comptes provider (nom, type, endpoint, entité) |
| `glpi_plugin_dnsmanager_credentials` | Credentials chiffrés AES-256-CBC |
| `glpi_plugin_dnsmanager_domains` | Mapping domaine provider ↔ `glpi_domains` |
| `glpi_plugin_dnsmanager_records` | Mapping enregistrement provider ↔ `glpi_domainrecords` |
| `glpi_plugin_dnsmanager_synclogs` | Journal de synchronisation |
| `glpi_plugin_dnsmanager_configs` | Configuration du plugin (clé chiffrement, CRON) |

---

## Tâche CRON

La tâche `SyncAllAccounts` est enregistrée automatiquement à l'activation.
Configurer la fréquence dans **Configuration → Actions automatiques → SyncAllAccounts**.

---

## Roadmap

- [x] **Phase 1** — Configuration des comptes + chiffrement credentials
- [x] **Phase 2** — Import domaines + enregistrements DNS (lecture seule)
- [ ] **Phase 3** — Modification des enregistrements via GLPI (architecture prête)
- [ ] Provider Gandi
- [ ] Provider Cloudflare
- [ ] Provider générique REST (configurable via UI)
- [ ] Détection des conflits (enregistrement modifié côté provider depuis dernière sync)
- [ ] Notifications GLPI en cas d'erreur de sync

---

## Licence

GPL v2+
