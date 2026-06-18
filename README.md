# DNS Hébergeurs — Plugin GLPI 11

Plugin de gestion et synchronisation des enregistrements DNS pour GLPI 11.

[![GLPI](https://img.shields.io/badge/GLPI-11.0%2B-blue)](https://glpi-project.org)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)](LICENSE)
[![Version](https://img.shields.io/badge/Version-3.0.6-orange)](https://github.com/belu33fr/dnsmanager/releases)

## Fonctionnalités

- 🌐 Support multi-providers : **OVH/OVHcloud**, **Gandi**
- 🔄 Synchronisation automatique (CRON) et manuelle
- 📥 Import interactif des domaines avec choix d'entité
- 🔒 Verrouillage des contacts par domaine
- 👥 Gestion des droits par profil GLPI
- 🌍 Interface en Français et Anglais

## Providers supportés

| Provider | Enregistrements | Dates | Multi-endpoint |
|----------|----------------|-------|----------------|
| OVH / OVHcloud | ✅ | ✅ (RDAP) | ✅ (EU, CA, US, SYS...) |
| Gandi (LiveDNS) | ✅ | ✅ (API) | ✅ (Production, Sandbox) |

## Installation

```bash
cd /var/glpi/plugins/
unzip dnsmanager-3.0.6.zip
chown -R www-data:www-data dnsmanager/
```

Puis dans GLPI : **Configuration → Plugins → DNS Hébergeurs → Installer → Activer**

## Documentation

- 📖 [Guide utilisateur](docs/user-guide.md)
- 🔧 [Guide développeur — Ajouter un provider](docs/developer-guide.md)
- 🐛 [Signaler un bug](https://github.com/belu33fr/dnsmanager/issues)

## Prérequis

- GLPI 11.0.0+
- PHP 8.1+ (`curl`, `json`, `openssl`)
- Plugin [Additional Fields](https://github.com/pluginsGLPI/fields)
- Plugin [Accounts](https://github.com/InfotelGLPI/accounts)

## Auteurs

- **L. Berthaud** — conception métier et spécifications
- **Claude (Anthropic)** — développement

## Licence

GPL v2+
