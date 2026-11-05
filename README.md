# Plugin GLPI – Notifications Threema (1.0.0)
https://raw.githubusercontent.com/d-factos-SA/Threema/main/misc/threema.png

Ajoute **Threema** comme canal de notification dans GLPI (à côté de l'e-mail et du navigateur), via
**Threema Gateway**. Les notifications existantes de GLPI (tickets, changements, alertes…) peuvent être
envoyées sur Threema en ajoutant simplement le mode « Threema » à une notification.

- GLPI **11.0 et 12.0** (API vérifiées contre les sources 11.0.12 et 12.0.1), PHP ≥ 8.2 (8.3 pour GLPI 12), extensions `curl`, `mbstring` et `sodium`
- Deux modes : **de bout en bout** (E2E, recommandé : GLPI garde la clé privée, Threema ne voit jamais le contenu)
  et **basique** (chiffrement côté serveur Threema)
- Pas de dépendance Composer : le plugin parle directement à l'API HTTP de Threema Gateway
  (`send_e2e`, `send_simple`, `pubkeys`, `lookup`, `credits`) ; le chiffrement E2E utilise libsodium.

## Installation

1. Copier le dossier `threema/` dans `plugins/` (ou `marketplace/`) de GLPI — le dossier **doit** s'appeler `threema`.
2. *Configuration > Plugins* : installer puis activer **Threema**.
3. *Configuration > Notifications > Configuration des notifications* : activer les notifications, cocher
   **Activer les notifications via Threema**, puis ouvrir la configuration Threema (icône de la ligne).

## Côté Threema Gateway (une seule fois)

Mode **de bout en bout** (comme dans la documentation Threema, étapes 4 à 7) :

1. Générer la paire de clés avec l'outil officiel :
   `./threema-msgapi-tool -g privateKey.txt publicKey.txt`
   **Sauvegardez `privateKey.txt` en plusieurs exemplaires** : si elle est perdue, l'ID Gateway est inutilisable à jamais.
2. Sur le portail Gateway : *Manage IDs > Request Threema ID* → mode **End-to-End**, ID souhaité (8 caractères, commence par `*`),
   coller la clé publique. Attendre la validation manuelle (plusieurs jours possibles) ; vous recevez le **secret API**.
3. Acheter des crédits (*Balance*).

Mode **basique** : un ID Gateway « basic » et son secret suffisent (la clé privée est connue de Threema).

## Configuration du plugin

*Configuration > Notifications > Threema* :

| Champ | Rôle |
|---|---|
| ID Gateway / Secret API | Identifiants du compte (le secret est chiffré avec la clé GLPI) |
| Mode | Doit correspondre au type de l'ID Gateway |
| Clé privée | Contenu de `privateKey.txt` (E2E). Stockée chiffrée. La clé publique correspondante est affichée pour contrôle |
| Longueur maximale | Les notifications plus longues sont tronquées (3500 o. basique / ~7000 o. E2E) |
| Nouvelles tentatives / délai | En cas d'erreur temporaire (réseau, 429, 5xx, plus de crédits) |
| Threema ID de l'administrateur | Destinataire de la cible « Administrateur » et du message de test |
| Trouver automatiquement les IDs | Active l'action automatique `SyncIds` (voir ci-dessous) |

Le bouton **Enregistrer et tester la connexion** vérifie l'ID + secret (affiche les crédits restants) et, en E2E, que la clé
privée correspond bien à la clé publique enregistrée chez Threema pour cet ID Gateway.
**Enregistrer et envoyer un message de test** envoie un message à l'ID administrateur.

## Utilisation

1. **Chaque utilisateur** (ou un administrateur) renseigne son **Threema ID** : onglet *Threema* de la fiche utilisateur,
   ou de *Préférences* pour soi-même. Un bouton permet aussi de le retrouver par e-mail / numéro mobile
   (seuls des hachés HMAC-SHA256 sont envoyés à Threema ; un mobile doit être au format international `+41…`).
2. Dans *Configuration > Notifications > Notifications*, ouvrir une notification (ex. « Nouveau ticket ») puis, dans
   l'onglet *Modèles*, ajouter le **mode Threema** avec un modèle. Les destinataires sont ceux définis par la notification ;
   ceux sans Threema ID sont ignorés silencieusement.
3. Les messages passent par la **file d'attente** de GLPI et partent via l'action automatique `queuednotification`
   (comme les e-mails) — vérifiez que le cron GLPI tourne.

Mise en forme : sujet du modèle en **gras**, puis la version *texte* du modèle (pas le HTML). Pensez à des modèles courts
pour Threema : l'URL de l'élément (`##ticket.url##`) suffit à y retourner.

### Action automatique `SyncIds` (optionnelle)

Désactivée par défaut. Si « Trouver automatiquement les IDs » est activé et l'action activée, elle recherche chaque jour
(50 utilisateurs par exécution) le Threema ID des utilisateurs actifs qui n'en ont pas, par e-mail puis mobile. Les « introuvables »
sont ré-essayés après 30 jours.

### Journaux

`files/_log/threema.log` (envois réussis, mise en file) et `files/_log/threema-error.log` (échecs, abandons).
Les erreurs permanentes (ID invalide/inconnu, message trop long) ne sont pas ré-essayées ; les autres le sont `N` fois.

## Désinstallation

Supprime les tables du plugin (`glpi_plugin_threema_userids`, `glpi_plugin_threema_pubkeys`), la configuration, les liens
« mode Threema » des notifications et les messages Threema en attente.

## Sécurité – points d'attention

- Le secret API et la clé privée sont chiffrés avec la clé GLPI (`glpicrypt.key`) : **sauvegardez ce fichier** aussi.
- En E2E, GLPI a besoin de la clé privée pour envoyer : quiconque contrôle le serveur GLPI peut envoyer au nom de l'ID Gateway.
- Les clés publiques des destinataires sont mises en cache 30 jours (recommandation Threema).
- Une notification contient ce que contient le modèle : évitez d'y mettre des données sensibles inutiles.

## Tests (développement)

Sans base de données ni GLPI complet :

```
php tests/gateway_client_test.php                       # client Threema contre un faux gateway local (curl réel, E2E déchiffré côté faux gateway)
GLPI_SRC=/chemin/glpi-11-ou-12 php tests/glpi_harness_test.php   # classes du plugin contre les vraies interfaces GLPI + livraison de la file
```

## Limites connues

- Texte uniquement (pas d'images, fichiers, boutons, groupes).
- GLPI 10 et antérieur : non supporté (le plugin s'appuie sur les API de GLPI 11/12, par exemple l'absence de `Sanitizer`).
- Le plugin n'utilise pas le SDK PHP officiel (2.4.0) : les appels HTTP et le format E2E suivent la documentation Threema
  et ont été recoupés avec le code du SDK 1.x, mais **n'ont pas été exécutés contre le vrai gateway** — faire le test de connexion
  et le message de test avant mise en production.
