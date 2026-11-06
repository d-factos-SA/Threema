# Changelog

## 1.0.0 - 2026-11-05

- Première version stable, pour **GLPI 11.x et 12.x** (PHP >= 8.2).
- Mode de notification « Threema » (E2E et basique) via Threema Gateway, file d'attente GLPI + retries.
- Threema ID par utilisateur (fiche utilisateur et Préférences), recherche automatique optionnelle par e-mail / mobile.
- Page de configuration avec test de connexion (crédits, cohérence de la paire de clés) et message de test.
- Compatibilité GLPI 11/12 : plus de `Sanitizer`, `stripslashes_deep`, `inc/includes.php`, `Plugin::getWebDir`,
  `doQueryOrDie` ni redéclaration de `$rightname` (typé en GLPI 12, non typé en 11).
- Sécurité (revue du catalogue) : modification du Threema ID d'un autre utilisateur soumise au droit global « Utilisateurs : mise à jour »
  **et** au droit sur cet utilisateur (`User::canUpdate()` + `can($id, UPDATE)`) ; réponse de recherche Gateway validée (un ID invalide = introuvable) ;
  secrets (`api_secret`, `private_key`) masqués via le hook `undiscloseConfigValue`.
- Traduction française.
- Métadonnées du catalogue GLPI (`threema.xml`), liens GitHub, README anglais et français avec captures.
