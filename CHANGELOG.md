# Changelog

## 1.0.0 - 2026-11-05

- Première version stable, pour **GLPI 11 et 12** (PHP >= 8.2).
- Mode de notification « Threema » (E2E et basique) via Threema Gateway, file d'attente GLPI + retries.
- Threema ID par utilisateur (fiche utilisateur et Préférences), recherche automatique optionnelle par e-mail / mobile.
- Page de configuration avec test de connexion (crédits, cohérence de la paire de clés) et message de test.
- Compatibilité GLPI 11/12 : plus de `Sanitizer`, `stripslashes_deep`, `inc/includes.php`, `Plugin::getWebDir`,
  `doQueryOrDie` ni redéclaration de `$rightname` (typé en GLPI 12, non typé en 11).
- Traduction française.
- Métadonnées du catalogue GLPI (`threema.xml`), liens GitHub, README anglais et français avec captures.
