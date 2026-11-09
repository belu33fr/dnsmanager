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

**Aucun droit d'écriture n'est nécessaire — et il ne faut pas en accorder.** Depuis la version 3.9.7, le plugin ne modifie jamais rien chez le provider : les ajouts, modifications et suppressions faits dans GLPI restent locaux, et aucun réglage de l'interface ne permet de changer cela. La clé doit donc rester en **lecture seule** (règles ci-dessus). Le bouton **"Tester la connexion"** signale si la clé possède des droits d'écriture et recommande de les retirer.

**Authentification Gandi** : Personal Access Token (PAT)
→ Créer sur https://admin.gandi.net → Sécurité → Personal Access Tokens

⚠️ **Un token Gandi est scopé à une seule organisation** (ou au compte personnel) au moment de sa création — il n'existe pas de token "multi-organisations" avec le PAT. Si vous gérez plusieurs organisations Gandi (revendeur, clients multiples...), créez **un compte DNS Hébergeurs par organisation**, chacun avec son propre token dédié à cette organisation précise.

Permissions à cocher lors de la création du token — **lecture seule**, suffisant pour la synchronisation actuelle :
- `Voir les domaines` (`domain:view`)

Aucun droit d'écriture n'est nécessaire ni exploité par le plugin.

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

## Serveur DNS Windows (Active Directory)

Type de fournisseur **Windows DNS (Active Directory)** : remonte, en **lecture seule**, les zones DNS
intégrées à l'AD et leurs enregistrements, par LDAP. L'extension PHP LDAP est requise (GLPI l'exige déjà).

**Compte** : créez un compte du domaine **en lecture seule** (aucun droit d'écriture n'est nécessaire) et
renseignez dans la fiche : le serveur (`ldaps://contrôleur.domaine.local:636` recommandé), le compte
(`utilisateur@domaine.local`), son mot de passe. La base LDAP est détectée automatiquement.
Le bouton **Tester la connexion** affiche les zones trouvées, celles qui seront importées et celles qui sont
ignorées (avec la raison) : c'est le moyen de vérifier la configuration avant toute importation.

**Zones importées** : les zones dont l'extension est une extension Internet (`.com`, `.fr`…) ou une extension
interne déclarée dans la configuration du plugin (*Extensions internes*, par défaut
`local;internal;interne;lan;corp;home;intranet;private`). Sont toujours ignorées : zones inverses (`.arpa`),
zones techniques (`_msdcs…`, `RootDNSServers`, `TrustAnchors`) et zones sans extension.
Seules les zones intégrées à l'AD sont visibles par LDAP.

**Enregistrements importés** : A, AAAA, CNAME, MX, TXT, SRV, NS, PTR. Ne remontent pas : le SOA, les
entrées « glue », les enregistrements **dynamiques** de type A/AAAA/PTR (postes qui s'inscrivent seuls), et, dans
la zone du domaine AD lui-même, les enregistrements techniques (`_msdcs`, `_sites`, `_tcp`, `_udp`).

**Type de domaine** : les domaines GLPI créés ont le type **DNS AD** (nom modifiable dans la configuration du
plugin), distinct du type **Internet** des fournisseurs Internet. Un domaine Internet et un domaine DNS AD de même
nom (DNS externe et DNS interne) sont donc deux domaines GLPI distincts, chacun avec son propre compte et ses
propres enregistrements. Cela suppose qu'aucune règle d'unicité sur le nom des domaines ne soit configurée dans
GLPI (Configuration → Unicité des champs).

**Droits du compte de lecture (serveur DNS Windows)** : un compte du domaine peut s'authentifier et lire la
base de l'annuaire sans pouvoir **lister** le contenu des conteneurs DNS ; Active Directory masque alors les
objets sans renvoyer d'erreur (le test de connexion affiche « 0 zone » et « entrées visibles : 0 »). Il faut
alors déléguer la **lecture seule** sur les conteneurs concernés, depuis un contrôleur de domaine :

```
dsacls "CN=MicrosoftDNS,CN=System,DC=domaine,DC=local" /G "DOMAINE\compte:GR" /I:T
dsacls "CN=MicrosoftDNS,DC=DomainDnsZones,DC=domaine,DC=local" /G "DOMAINE\compte:GR" /I:T
dsacls "CN=MicrosoftDNS,DC=ForestDnsZones,DC=domaine,DC=local" /G "DOMAINE\compte:GR" /I:T
```

(`GR` = lecture générique ; vérifiez la syntaxe avec `dsacls /?` et adaptez les noms.) **N'utilisez pas le groupe
DnsAdmins** : il donne bien plus que la lecture et constitue un chemin connu d'élévation de privilèges.
