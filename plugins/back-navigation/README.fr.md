# back-navigation

Plugin Tachyon (fork de SnappyMail) : le bouton **« page précédente » du navigateur ferme le message ouvert** et
ramène à la liste, comme Gmail. L'ouverture d'un message pousse `#/mailbox/<dossier>/m<uid>` dans l'historique ;
« précédente », ou le bouton ✕, le dépile. Effet de bord : ce hash est un lien qui marche tant que le message reste
dans son dossier (F5, ou collé à un collègue qui ouvre le même compte). Côté client seulement, aucune action serveur,
aucun template du core remplacé. Détail technique (en anglais) : [README.md](README.md).

- **Ce que le core fait déjà** : la route `#/mailbox/<dossier>/m<uid>(/<recherche>)` est lue (`routes()` de
  `MailBoxUserScreen`, `app.js`) et ouvre le message — mais rien ne l'écrit jamais : ouvrir un message depuis la liste
  ne change pas le hash, donc « précédente » quitte le webmail.
- **Client** (`back-navigation.js`) : abonné à `message` de la vue `MailMessageView` (événement `rl-view-model`).
  Ouverture depuis la liste → hash poussé, routage du core coupé le temps de l'écriture (sinon il recharge message et
  liste une seconde fois) ; le `<dossier>` est celui du message (règle `fullNameHash` du core), pas celui affiché.
  « Précédente » (le hash ne nomme plus le message) → `closeMessage()` ; le core route lui-même le nouveau hash et
  recharge la liste, comme pour tout changement de hash. Bouton ✕ → `history.back()` avec routage coupé jusqu'au
  `hashchange` : fermeture identique à celle du core, sans rechargement, historique propre. Message ouvert par la route
  elle-même (lien direct, F5, « suivante ») : rien n'a été poussé, le hash est remplacé sans entrée d'historique.
- **Prérequis** : Tachyon 4.x. S'appuie sur des internes du core (`MailMessageView` : `message`, `closeMessage` ;
  `hasher` ; `rl.route` ; règle `fullNameHash`) — à revérifier à chaque montée de version.
- **Limites** : le hash nomme un uid IMAP, propre à un dossier : le lien meurt dès que le message est déplacé ou
  archivé. Un lien durable demanderait une route par Message-ID résolue côté serveur : hors périmètre. Le retour par
  « précédente » recharge la liste (comportement du core), pas le bouton ✕.
- **Installation** : copier le dossier dans les plugins, activer `back-navigation` dans l'administration (ou
  `plugins.enabled_list`). Aucun réglage.
- **Test** : vérifié à la main et par un script navigateur de bout en bout gardé dans notre propre dépôt (ouverture,
  « précédente », ✕ puis « suivante », lien direct, aucune erreur JS) ; pas de test PHP, le plugin n'a pas de serveur.
- **Licence** : AGPL-3.0 (voir [LICENSE](LICENSE)).
