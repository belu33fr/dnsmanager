# Conception — Modification bidirectionnelle des enregistrements DNS

**Statut : document de conception, pas encore implémenté.**
**Périmètre de cette première phase : OVH uniquement. Gandi reste en lecture seule pour cette fonctionnalité.**

---

## 1. Objectif

Permettre qu'un ajout/modification/suppression d'un enregistrement DNS (`DomainRecord`) effectué **dans GLPI** soit répercuté chez le provider OVH, et qu'un changement fait **chez OVH** (hors GLPI) soit correctement reflété dans GLPI lors de la prochaine synchronisation — sans boucle, sans doublon, sans perte silencieuse en cas de conflit ou de panne.

---

## 2. Pourquoi OVH d'abord, pas Gandi

- **OVH** : chaque valeur DNS est un enregistrement distinct, avec son propre identifiant (`subDomain` + `fieldType` + `target` + `id` interne OVH). `name + type` peut correspondre à **plusieurs** enregistrements (round-robin, plusieurs TXT) — mais chaque valeur reste individuellement adressable via son ID OVH. La correspondance avec `DomainRecord` de GLPI (qui est aussi un objet individuel par valeur) est donc naturelle.
- **Gandi** : les valeurs de même `name + type` sont regroupées dans un seul objet `rrset` (tableau `rrset_values`). "Modifier un enregistrement" y est ambigu — s'agit-il de modifier une valeur dans le groupe, ou le groupe entier ? Ce sujet nécessite sa propre réflexion de modélisation, à traiter dans une phase 2 séparée une fois la mécanique validée sur OVH.

---

## 3. Interrupteur de sécurité par compte

Nouveau champ sur le compte DNS Hébergeur : **"Autoriser la modification bidirectionnelle"** (case à cocher, **désactivée par défaut**).

- Tant que non activé sur un compte : comportement actuel inchangé (import/synchronisation en lecture seule des enregistrements, comme aujourd'hui).
- Une fois activé : les hooks GLPI décrits ci-dessous deviennent actifs **pour les domaines rattachés à ce compte uniquement**.
- Permet un déploiement progressif compte par compte (test isolé, puis généralisation une fois la confiance établie).

---

## 4. Anti-boucle : flag de réentrance

Avant toute écriture provenant de la synchronisation (`Importer`/`DomainImporter`) dans `DomainRecord`, on active un flag statique **par domaine en cours de traitement** :

```php
Importer::$syncInProgress[$glpiDomainId] = true;
try {
    // ... écritures de synchro sur les DomainRecord de ce domaine ...
} finally {
    unset(Importer::$syncInProgress[$glpiDomainId]);
}
```

Les hooks `ITEM_ADD`/`ITEM_UPDATE`/`ITEM_DELETE`/`ITEM_PURGE` sur `DomainRecord` vérifient ce flag **en tout premier** :

- Flag actif pour ce domaine → l'écriture vient de la synchro elle-même → **ne rien repousser** vers le provider, sortir immédiatement.
- Flag inactif → écriture réellement initiée par un utilisateur → appliquer le mini-cahier des charges (section 6).

Le `finally` garantit que le flag ne reste jamais bloqué à `true` si une exception survient en cours de synchro.

---

## 5. Principe directeur : classes GLPI natives partout

Conformément à notre règle déjà établie, toute écriture sur `DomainRecord` (synchro **et** modification utilisateur) doit passer par les méthodes objet (`->add()`, `->update()`, `->delete()`, `->restore()`, `->purge()`) — jamais de SQL direct. Ceci garantit :
- Le déclenchement correct des hooks (SQL direct ne les déclenche pas)
- L'héritage des correctifs/évolutions GLPI
- La possibilité d'ouvrir un ticket légitime chez Teclib en cas de bug du core

**Action préalable à l'implémentation** : audit du code existant pour identifier et corriger tout SQL direct restant sur `glpi_domainrecords` (un cas identifié précédemment : mise à jour du TTL après un `add()` qui semblait l'ignorer — à réexaminer après la montée de version GLPI récente de Teclib, qui a peut-être corrigé ce point).

---

## 6. Tableau des actions

### 6.1 GLPI → OVH (déclenché par un utilisateur)

| Action utilisateur dans GLPI | Effet côté OVH |
|---|---|
| Suppression d'un enregistrement (corbeille) | Suppression réelle de l'enregistrement chez OVH |
| Suppression définitive (purge) depuis la corbeille | RAS si déjà supprimé côté OVH lors de l'étape précédente ; sinon suppression |
| Modification (nom, type, TTL, valeur) | Modification répercutée chez OVH |
| Ajout d'un nouvel enregistrement | Création répercutée chez OVH |

### 6.2 OVH → GLPI (détecté lors d'une synchronisation)

| Constat côté OVH | Effet dans GLPI |
|---|---|
| Enregistrement supprimé chez OVH | Le `DomainRecord` GLPI correspondant est mis à la corbeille |
| Enregistrement modifié chez OVH | L'ancien `DomainRecord` (identité fonctionnelle name+type+zone) va en corbeille, un nouveau est créé avec les valeurs à jour |
| Enregistrement ajouté chez OVH | Un nouveau `DomainRecord` est créé — **avant création**, vérifier qu'il n'existe pas déjà un `DomainRecord` orphelin (même identité fonctionnelle, sans mapping) ; si oui, le mettre en corbeille d'abord (robustesse anti-doublon, cas déjà rencontré en pratique) |
| Restauration manuelle en corbeille GLPI par l'utilisateur | Si l'enregistrement n'existe plus chez OVH, il est **recréé** côté OVH |

**Principe de correspondance** : toute comparaison "cet enregistrement a-t-il changé / existe-t-il déjà" se fait par **identité fonctionnelle** (`name + type + zone`), jamais par ID technique interne (GLPI ou OVH), pour éviter les doublons en cas de cycle corbeille/recréation.

---

## 7. Concurrence : verrou par domaine

### 7.1 Principe

Un verrou logique (ex: table `glpi_plugin_dnsmanager_domainlocks` ou entrée en cache) est posé **par domaine** (pas par enregistrement) pendant :
- Une synchronisation (manuelle ou CRON) sur ce domaine
- Une modification utilisateur en cours de traitement sur un enregistrement de ce domaine

### 7.2 Scénarios

| Situation | Comportement |
|---|---|
| Sync démarre sur un domaine non verrouillé | Verrou posé, sync effectuée, verrou libéré |
| Utilisateur modifie un enregistrement, domaine non verrouillé | Verrou posé, action appliquée, verrou libéré |
| Sync démarre alors qu'un utilisateur a le verrou sur ce domaine | La sync **attend** (polling) la libération, avec timeout |
| Utilisateur agit alors qu'une sync a le verrou sur ce domaine | L'action utilisateur **attend** (polling côté navigateur) la libération, avec timeout |
| Timeout atteint côté sync | La synchronisation de ce domaine est **annulée** (les autres domaines du même compte continuent normalement) |
| Timeout atteint côté utilisateur | L'action utilisateur est **annulée**, message clair affiché : *"Une synchronisation est en cours sur ce domaine, veuillez réessayer dans quelques instants."* |
| Verrou libéré, mais le record précis que l'utilisateur traitait a été touché entre-temps par la sync (comparaison par identité fonctionnelle) | L'action utilisateur est **annulée** (pas appliquée), l'utilisateur est ramené à la liste des enregistrements avec le message : *"Une synchronisation a modifié cet enregistrement entre-temps. Veuillez vérifier son état actuel avant de réessayer."* |
| Verrou libéré, record non touché | L'action utilisateur s'exécute normalement |

### 7.3 Mécanisme technique proposé

Une requête HTTP PHP classique ne peut pas attendre longtemps côté serveur (déjà rencontré avec les limites `max_execution_time` sur OVH). Proposition :

- **Attente côté serveur (sync déclenchée manuellement ou CRON)** : boucle courte avec petites pauses (`usleep`), timeout serveur raisonnable (proposition : **20 secondes**), car le CRON tourne en CLI sans limite web stricte, et une sync manuelle a déjà `set_time_limit(120)` en place.
- **Attente côté utilisateur (formulaire d'édition d'un enregistrement)** : le formulaire de modification vérifie d'abord (AJAX) si le domaine est verrouillé ; si oui, affiche un message d'attente et **relance la vérification en polling JS** (ex: toutes les 2 secondes) jusqu'à libération ou timeout (proposition : **20 secondes** également, cohérence avec le côté serveur) — évite de bloquer une requête PHP ouverte inutilement.

*(Point à trancher ensemble : ces durées de 20s sont une proposition de départ, ajustable après tests réels.)*

---

## 8. Hooks GLPI utilisés

Sur `DomainRecord`, dans `setup.php` :

```php
$PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['dnsmanager']    = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_pre_add'];
$PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['dnsmanager'] = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_pre_update'];
$PLUGIN_HOOKS[Hooks::PRE_ITEM_DELETE]['dnsmanager'] = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_pre_delete'];
$PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['dnsmanager']  = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_pre_purge'];

$PLUGIN_HOOKS[Hooks::ITEM_ADD]['dnsmanager']    = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_add'];
$PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['dnsmanager'] = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_update'];
$PLUGIN_HOOKS[Hooks::ITEM_DELETE]['dnsmanager'] = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_delete'];
$PLUGIN_HOOKS[Hooks::ITEM_PURGE]['dnsmanager']  = ['DomainRecord' => 'plugin_dnsmanager_domainrecord_purge'];
```

- **`PRE_ITEM_*`** : s'exécutent **avant** l'écriture en base. Rôle : vérifier le verrou de domaine (attendre/annuler selon section 7), vérifier que le compte du domaine a la modification bidirectionnelle activée, lever une exception pour bloquer l'écriture si nécessaire.
- **`ITEM_*`** (post) : s'exécutent **après** l'écriture confirmée en base GLPI. Rôle : si le flag anti-boucle (section 4) est inactif, déclencher le push vers l'API OVH.

---

## 9. Filtrage des champs déclencheurs

Seuls les champs suivants, s'ils changent, déclenchent un push vers OVH : **`name`, `domainrecordtypes_id` (type), `ttl`, `data` (valeur)**.

Les autres champs (`comment`, `entities_id`, `is_recursive`, `users_id_tech`, etc.) sont des métadonnées internes GLPI sans équivalent DNS — leur modification ne déclenche **aucun appel API**.

Sur `ITEM_UPDATE`, comparer `$item->oldvalues` (ou `$item->fields` avant/après) pour ne réagir que si l'un des 4 champs surveillés a effectivement changé.

---

## 10. Gestion des échecs

Si l'appel API vers OVH échoue **après** que GLPI a déjà validé l'écriture (cas du hook post `ITEM_*`) :

- Le `DomainRecord` est marqué **"échec de synchronisation"** (nouveau champ de statut, cohérent avec le statut déjà existant `sync_status` de la table de mapping `glpi_plugin_dnsmanager_records`)
- Aucune tentative de rollback automatique (la donnée GLPI reste ce que l'utilisateur a saisi)
- Le prochain passage de synchronisation (manuelle ou CRON) sur ce domaine **retente** l'opération pour les enregistrements en échec, avant de traiter le reste normalement
- Un indicateur visuel (badge, icône) sur la liste des enregistrements du domaine permet à l'utilisateur de voir immédiatement qu'un enregistrement n'est pas synchronisé avec le provider

---

## 11. Décisions prises (mise à jour du 2026-07-18)

1. **Durées de timeout** — configurables dans le paramétrage du plugin (nouveau réglage global, ex: "Timeout verrou domaine (secondes)"), **valeur par défaut 20s**, ajustable sans redéploiement selon l'environnement/provider. S'applique aux deux côtés (attente sync et attente utilisateur) via le même réglage dans un premier temps.

2. **Table de verrouillage** — validée. **Mécanisme de purge automatique des verrous obsolètes** à prévoir, pour éviter qu'un plantage serveur (crash PHP, timeout Apache, coupure réseau en plein milieu d'une synchro) ne laisse un verrou orphelin bloquant indéfiniment ce domaine. Approche retenue :
   - Chaque verrou porte un horodatage (`locked_at`) et une **durée de vie maximale** (`expires_at` = `locked_at` + marge de sécurité, ex: timeout configuré × 2)
   - `isDomainLocked()` vérifie systématiquement `expires_at` : si dépassé, le verrou est considéré comme orphelin, **purgé à la volée**, et le domaine traité comme non verrouillé
   - En complément, une tâche CRON GLPI dédiée (`SyncCleanupLocks` ou intégrée à `SyncAllAccounts`) purge périodiquement les verrous expirés en base, pour garder la table propre même sans consultation

3. **Journalisation des annulations de sync par timeout** — confirmé, simple log dans `Synclog`. Point complémentaire utile : GLPI envoie déjà une notification par email en cas d'échecs répétés d'une tâche automatique (mécanisme natif des CronTask) — nos annulations de synchro par timeout de verrou devront donc être correctement remontées comme "erreurs" dans le retour de la tâche CRON pour bénéficier de cette alerte native, sans duplication de mécanisme d'alerte de notre côté.

4. **Audit SQL direct** — mise à jour GLPI prévue ce week-end par [Luc]. Réexamen du point (TTL, `Importer.php`) après cette mise à jour, avant de démarrer l'étape 1 du plan d'implémentation.

---

## 12. Étapes d'implémentation proposées (ordre)

1. ✅ **Fait** — Audit du SQL direct sur `DomainRecord`. Résultat : un seul cas légitime (TTL=0 en création, bug GLPI confirmé et ticketé chez Teclib — `empty(0)` traité comme "vide" dans `DomainRecord::prepareInput()`). La modification (`update()`) préserve nativement `0`, aucun contournement nécessaire là. Notre correctif SQL direct existant reste donc justifié et limité au strict nécessaire.
2. ✅ **Fait** — Table de verrouillage (`glpi_plugin_dnsmanager_domainlocks`) + classe `DomainLock` (`acquire`, `release`, `isLocked`, `waitAndAcquire`, `getLockInfo`, `purgeAllExpired`). **8 tests unitaires validés** via script dédié.
3. ✅ **Fait** — Champ "Autoriser la modification bidirectionnelle" par compte (désactivé par défaut) + helper `Account::hasBidirectionalSync()` (vérifie l'activation **et** que le provider est OVH).
4. ✅ **Fait** — Flag anti-boucle en mémoire (`DomainLock::markSyncInProgress()`/`unmarkSyncInProgress()`/`isSyncInProgress()`), branché en `try/finally` autour de tous les points d'écriture `DomainRecord` de la synchro (`Importer::importRecords()`, `DomainImporter::deleteDomain()`).
5. ✅ **Fait** — Hooks `PRE_ITEM_ADD`/`PRE_ITEM_UPDATE`/`PRE_ITEM_DELETE`/`PRE_ITEM_PURGE` sur `DomainRecord`, via la nouvelle classe `RecordSync`. Mécanisme de blocage confirmé par lecture du code source GLPI : `$item->input = false` (pas d'exception) est le mécanisme **officiellement prévu** par GLPI pour annuler une action depuis un hook `PRE_ITEM_*`.
6. ✅ **Fait** — Hooks `ITEM_ADD`/`ITEM_UPDATE`/`ITEM_DELETE`/`ITEM_PURGE` (post), toujours **sans appel API réel** — journalisation uniquement (`error_log`) de ce qui serait envoyé, pour validation. Le filtrage des champs déclencheurs (section 9) est actif : seul un changement réel de `name`/`domainrecordtypes_id`/`ttl`/`data` déclenche la mécanique de verrouillage en modification.
7. ✅ **Fait** — Vrais appels API branchés (`createRecord`/`updateRecord`/`deleteRecord` d'`OvhProvider`). **Bug découvert et corrigé au passage** : ces méthodes existaient déjà (travail antérieur) mais ne faisaient pas correctement l'inverse de `normalizeSubdomain()` — pour un enregistrement non-racine (ex: `www.example.com`), elles auraient envoyé `subDomain: "www.example.com"` (FQDN complet) à OVH au lieu de `subDomain: "www"` (forme courte attendue), ce qui aurait créé des enregistrements mal formés sur un vrai domaine live. Nouvelle fonction `denormalizeSubdomain()` ajoutée pour corriger ça. L'appel `POST /domain/zone/{zoneName}/refresh` (indispensable pour publier réellement un changement, sinon il reste "en attente" côté OVH) était déjà présent et correct. Marquage `sync_status = 'push_failed'` en cas d'échec (préparation de l'étape 8).

   **✅ Validé en conditions réelles le 2026-07-29** : ADD et DELETE testés sur un vrai domaine OVH (`blformations.com`). Vérification directe via l'API OVH (`GET /domain/zone/{zone}/record/{id}` + `GET /domain/zone/{zone}/status`) : enregistrement bien créé, `isDeployed: true`, `lastRefresh` correspondant exactement à l'heure du test. Le délai d'apparition initial dans l'interface web OVH était un simple cache d'affichage côté OVH, pas un problème côté plugin.
8. ⏳ **À faire** — Marquage "échec de synchronisation" + retry à la prochaine sync (section 10).
9. ⏳ **À faire** — Tests supervisés sur un compte de test uniquement (interrupteur section 3 activé sur ce seul compte).
10. ⏳ **À faire** — Généralisation progressive, compte par compte.

---

## 13. Protocole de test de l'étape 6 (avant de passer à l'étape 7)

**✅ Entièrement validé le 2026-07-29, en conditions réelles sur un vrai compte OVH :**

| Test | Résultat |
|---|---|
| ADD (ajout d'un enregistrement) | ✅ Log conforme (`name`/`type`/`ttl`/`data` corrects) |
| UPDATE (modification) | ✅ Log conforme, nouvelle valeur bien capturée |
| DELETE (corbeille) | ✅ Log conforme |
| PURGE (suppression définitive) — unitaire | ✅ Log conforme |
| PURGE en masse (plusieurs enregistrements, types variés : A, AAAA, CNAME, MX, TXT, SRV) sur un même domaine | ✅ Tous traités individuellement sans blocage, verrou acquis/libéré correctement à chaque itération |
| Conflit synchro/utilisateur — verrou tenu plus longtemps que le timeout | ✅ Action utilisateur annulée avec message clair, comme conçu |
| Conflit synchro/utilisateur — verrou tenu moins longtemps que le timeout | ✅ Action utilisateur mise en attente puis exécutée normalement, sans erreur |
| Compte **sans** la case "modification bidirectionnelle" cochée | ✅ Aucun log, aucun changement de comportement — code totalement transparent pour les comptes non activés |

Test réalisé via deux scripts dédiés (`test_domainlock.php` pour la mécanique unitaire, `test_conflict.php` pour simuler un verrou concurrent en conditions réelles) — les deux supprimés du serveur après usage.

**Conclusion : l'étape 6 est validée sans réserve. Prêt à passer à l'étape 7 (vrais appels API OVH).**

---

*Document rédigé le 2026-07-17/18, à discuter et amender avant tout début de codage.*
