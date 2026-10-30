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
→ Créer sur https://eu.api.ovh.com/createApp (ou l'URL régionale correspondant à votre endpoint : `ca` pour Canada, `us` pour USA, etc.)

Droits (Access Rules) à valider lors de la création de la Consumer Key — **lecture seule**, suffisant pour la synchronisation actuelle :

| Méthode | Chemin |
|---------|--------|
| GET | `/me` |
| GET | `/domain` |
| GET | `/domain/*` |
| GET | `/domain/zone/*` |

*Note : `/domain/zone/*` couvre l'ensemble des sous-chemins nécessaires (enregistrements, SOA/TTL par défaut, etc.) — pas besoin de règles séparées par sous-ressource.*

⚠️ Le droit `GET /me` est indispensable — il est utilisé pour valider la connexion (test de connexion) et son omission provoque une erreur `403 This call has not been granted` même si les droits sur `/domain/*` sont corrects.

Pour la fonctionnalité de modification bidirectionnelle des enregistrements DNS depuis GLPI (création/modification/suppression, réglable par compte via "Autoriser la modification bidirectionnelle" — désactivée par défaut, OVH uniquement pour l'instant), ajoutez également :

| Méthode | Chemin |
|---------|--------|
| POST | `/domain/zone/*` |
| PUT | `/domain/zone/*` |
| DELETE | `/domain/zone/*` |

⚠️ Depuis la version 3.9.3, le bouton **"Tester la connexion"** vérifie automatiquement la présence de ces droits d'écriture et l'affiche clairement (OK ou droits manquants avec le détail). **Testez systématiquement la connexion avant d'activer "Autoriser la modification bidirectionnelle"** sur un compte — sans ces droits, chaque ajout/modification/suppression d'enregistrement DNS échouera côté OVH (erreur 403) bien que l'action réussisse normalement dans GLPI ; un message d'avertissement s'affiche alors à l'utilisateur au moment de l'action concernée.

**Authentification Gandi** : Personal Access Token (PAT)
→ Créer sur https://admin.gandi.net → Sécurité → Personal Access Tokens

⚠️ **Un token Gandi est scopé à une seule organisation** (ou au compte personnel) au moment de sa création — il n'existe pas de token "multi-organisations" avec le PAT. Si vous gérez plusieurs organisations Gandi (revendeur, clients multiples...), créez **un compte DNS Hébergeurs par organisation**, chacun avec son propre token dédié à cette organisation précise.

Permissions à cocher lors de la création du token — **lecture seule**, suffisant pour la synchronisation actuelle :
- `Voir les domaines` (`domain:view`)

Pour anticiper la future fonctionnalité de modification bidirectionnelle, vous pouvez aussi cocher :
- `Gérer les domaines` / gestion des enregistrements LiveDNS

⚠️ Comme pour OVH, ces droits d'écriture ne sont pas encore exploités par le plugin.

*Astuce* : le bouton **"Tester la connexion"** du formulaire de compte Gandi affiche, en cas de succès, l'organisation détectée pour ce token — pratique pour vérifier que le bon token est associé au bon compte.

---

## Synchronisation

### Depuis la fiche d'un compte DNS

- **Rechercher nouveaux domaines** : analyse les domaines, propose l'import des nouveaux
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
