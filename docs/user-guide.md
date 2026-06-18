# DNS Hébergeurs — Guide utilisateur

## Présentation

Le plugin **DNS Hébergeurs** permet de synchroniser automatiquement les enregistrements DNS de vos domaines hébergés chez OVH ou Gandi directement dans GLPI.

---

## Prérequis

- GLPI 11.0.0 ou supérieur
- PHP 8.1+ avec extensions : `curl`, `json`, `openssl`
- Plugin **Additional Fields** installé et actif
- Plugin **Accounts** installé et actif (pour les contacts)
- Clés API valides chez vos hébergeurs DNS

---

## Installation

1. Déposez le dossier `dnsmanager/` dans `/var/glpi/plugins/`
2. Dans GLPI : **Configuration → Plugins → DNS Hébergeurs → Installer**
3. Activez le plugin

---

## Configuration initiale

### 1. Paramétrage du plugin

Allez dans **Configuration → Plugins → DNS Hébergeurs → ⚙️**

- **Intitulés entités import** : noms des entités GLPI servant de référence pour le rattachement des domaines lors de l'import (ex: `Sites;Financier`). Les entités parentes de ces entités seront proposées à l'import.

### 2. Droits par profil

Allez dans **Administration → Profils → [votre profil] → onglet DNS Sync**

| Droit | Description |
|-------|-------------|
| Paramétrage | Modifier la configuration du plugin |
| Comptes | Gérer les comptes DNS hébergeurs |
| Synchronisation | Exécuter les synchronisations |
| Vider le cache | Réinitialiser les mappings |

### 3. Créer un compte DNS hébergeur

Allez dans **Outils → DNS Hébergeurs → + Ajouter**

**Informations générales :**
- **Provider** : OVH/OVHcloud ou Gandi
- **Endpoint / Région** : zone géographique (OVH Europe, OVH Canada, etc.)
- **Fournisseur** : lien vers le fournisseur GLPI correspondant
- **Liste blanche** : domaines à synchroniser (vide = tous)
- **Préavis de facturation (mois)** : délai avant expiration pour la date de renouvellement

**Contacts par défaut** (copiés lors de l'import d'un domaine) :
- Administrateur, Technicien, Financier (via plugin Accounts)
- Propriétaire (utilisateur GLPI)
- Groupe et Groupe responsable

**Authentification OVH** : Application Key (AK), Application Secret (AS), Consumer Key (CK)
→ Créer sur https://eu.api.ovh.com/createApp

**Authentification Gandi** : Personal Access Token (PAT)
→ Créer sur https://admin.gandi.net → Sécurité → Personal Access Tokens
→ Permissions requises : `domain:view`

---

## Synchronisation

### Depuis la fiche d'un compte DNS

- **Analyser et synchroniser** : analyse les domaines, propose l'import des nouveaux
- **Sync rapide** : synchronise directement sans analyse

### Depuis la liste des comptes (Actions massives)

Sélectionnez un ou plusieurs comptes → **Actions → Synchroniser maintenant**

### Depuis la fiche d'un domaine

Dans l'onglet principal → bloc **"Informations de synchronisation DNS"** → bouton **"Synchroniser ce domaine"**

### Automatique (CRON)

**Configuration → Actions automatiques → SyncAllAccounts**

---

## Import de domaines

| Situation | Action |
|-----------|--------|
| Présent provider, absent GLPI | Proposé à l'import avec choix entité |
| En corbeille GLPI, présent provider | Restauré automatiquement + synchronisé |
| Dans GLPI, absent de tous les providers | Proposé pour mise en corbeille |
| Dans GLPI (type Internet), présent provider | Synchronisé automatiquement |

---

## Verrouillage des contacts

Dans le bloc **"Administratif Domaine"**, chaque contact peut être verrouillé individuellement. Un contact verrouillé n'est pas écrasé lors des synchronisations.

---

## Résolution de problèmes

**Erreur 403** : vérifiez la validité de votre clé API (expiration, permissions).

**Domaines non détectés (Gandi)** : seuls les domaines utilisant Gandi LiveDNS sont synchronisés.

**Records en doublon** : videz le cache dans **Configuration → Plugins → DNS Hébergeurs → Vider le cache**.
