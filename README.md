# Wajub pour PrestaShop

Module de paiement officiel Wajub pour PrestaShop 8.1+ et 9 (PHP 8.1+, extension cURL) : Mobile Money et carte avec le formulaire de paiement de Wajub dans le tunnel de commande, les autres moyens de paiement du compte sur la page de paiement Wajub, « Connexion avec Wajub », le webhook qui règle les commandes et les remboursements dans les deux sens. Licence MIT, comme les SDK Wajub.

Ce fichier n’est pas dans le zip du module. Il sert aux marchands (première partie) et aux développeurs (seconde partie). Le comportement est celui de la partie WooCommerce du plugin WordPress (`packages/wordpress/wajub`), qui sert de référence ; les écarts sont dits là où ils sont.

## Pour les marchands

### Installation

1. Téléchargez `wajub.zip` (PrestaShop Addons ou téléchargement direct), puis dans le back-office : **Modules > Gestionnaire de modules > Installer un module**. Le zip contient un seul dossier, `wajub/`.
2. **Configurer** ouvre la page du module, en quatre onglets : Connexion, Moyens de paiement, Apparence, Webhooks.
3. **Connexion** : « Connexion avec Wajub » (Test ou Production) ouvre le tableau de bord Wajub dans un nouvel onglet ; le propriétaire, un administrateur ou un développeur du compte y approuve le code affiché, et Wajub crée la clé de la boutique. Rien à copier. On peut aussi coller ses clés secrètes (« Coller plutôt vos clés ») : `sk_test.` / `rk_test.` en Test, `sk.` / `rk.` en Production. Chaque clé est vérifiée auprès de Wajub avant d’être enregistrée, chiffrée, et n’est plus jamais affichée.
4. **Webhooks** : « Configurer automatiquement » enregistre l’adresse du webhook de la boutique chez Wajub et garde son secret de signature (fait aussi après « Connexion avec Wajub »). Une boutique en local, ou sans HTTPS en Production, le configure à la main (étapes affichées dans l’onglet).
5. Passez une commande de test, puis choisissez **Production** quand la clé de production est enregistrée.

Le module crée deux états de commande : « En attente de paiement Wajub » et « Partiellement payée avec Wajub ». Une commande payée en entier passe à « Paiement accepté ».

### Ce que voit le client

- **Mobile Money** et **Carte bancaire** : le formulaire de paiement de Wajub dans le bloc du moyen de paiement, aux couleurs et à la police du thème (Classic sur PrestaShop 8, Hummingbird sur 9). En Mobile Money, le client choisit son opérateur dans une liste avec les logos (un réglage le déduit du numéro à la place), puis approuve le paiement sur son téléphone.
- **Autres moyens de paiement** : la page de paiement Wajub, limitée aux moyens que le compte propose en plus de Mobile Money et de la carte (PayPal, portefeuilles…). Affiché seulement s’il en reste.
- Chaque option ne s’affiche que si le compte Wajub la propose pour ce panier (devise, montant, pays). Wajub injoignable : Mobile Money et Carte s’affichent, « Autres moyens de paiement » non.
- Au clic sur **Commander**, la commande est créée dans l’état « En attente de paiement Wajub », puis le paiement part. L’e-mail de confirmation de commande est retenu jusqu’au paiement. Paiement refusé, annulé, ou « Choisir un autre moyen de paiement » : la commande est annulée et le panier revient, sauf si un paiement peut encore aboutir.
- Sans JavaScript, ou si le formulaire ne se charge pas en 15 secondes, le client paie sur la page Wajub, limitée au type de l’option choisie.
- Une commande payée en partie (paiement échelonné clos plus tôt, total modifié) affiche ce qui a été reçu et **Payer le reste**, sur la page de confirmation et dans **Détails de la commande** du compte client.

### Réglages

| Onglet | Réglages |
| --- | --- |
| Connexion | Environnement (Test ou Production : chaque environnement paie avec sa seule clé), « Connexion avec Wajub », clés collées, journal détaillé |
| Moyens de paiement | Mobile Money, Carte, Autres moyens de paiement : activés ou non, titre et description par langue, logos ; opérateur Mobile Money (liste ou numéro) ; délai d’annulation des commandes impayées (60 minutes par défaut, 0 = jamais) et adresse de la tâche planifiée |
| Apparence | Couleurs du formulaire (comme la boutique, clair, sombre), ligne « Sécurisé par Wajub » |
| Webhooks | Adresse du webhook, dernière notification reçue ou refusée et quoi faire, « Configurer automatiquement », secrets de signature collés à la main |

**Tâche planifiée.** Le module relit les paiements qui n’ont pas encore pu compter et annule les commandes restées « En attente de paiement Wajub » au-delà du délai choisi (jamais une commande dont un paiement peut encore aboutir, ou pour laquelle Wajub détient de l’argent). Elle tourne toutes les heures avec le module « Tâches cron » de PrestaShop, ou à l’adresse affichée dans l’onglet Moyens de paiement, à faire appeler par le cron de l’hébergeur (toutes les 15 minutes, par exemple). La même tâche envoie les rappels des clés qui expirent.

**Clé qui expire.** Une clé créée par « Connexion avec Wajub » dure 90 jours, sauf si l’approbateur choisit « Sans expiration ». Quatorze jours avant sa date, une alerte s’affiche sur toutes les pages du back-office (pages legacy de PrestaShop 9 comprises) pour les employés qui ont accès aux réglages du module, et un e-mail part, une fois par clé, à l’adresse e-mail de la boutique (Paramètres de la boutique > Contact), dans la langue par défaut de la boutique. En multiboutique, l’alerte vaut pour une clé avec laquelle une boutique paie (une boutique qui utilise la clé de « Toutes les boutiques » dans son propre environnement compte), seulement pour les employés de ces boutiques, et l’e-mail nomme la portée à choisir dans le sélecteur de boutique. Une copie de la boutique (à une autre adresse) n’envoie pas cet e-mail.

**Remboursements.** Un remboursement partiel, un remboursement standard ou un retour de produits fait depuis la page de la commande est remboursé par Wajub avant que PrestaShop ne crée l’avoir ; refusé (commande payée dans l’autre environnement, plus que ce que Wajub détient…), il ne crée pas d’avoir et la page dit pourquoi. Un remboursement fait dans le tableau de bord Wajub est enregistré comme avoir sur la commande. Le bloc « Paiement Wajub » de la commande liste les paiements, les remboursements et les notes, avec **Vérifier le statut**.

### Multiboutique

Chaque boutique, chaque groupe de boutiques et « Toutes les boutiques » peut avoir sa propre connexion : environnement, clés, webhook. Le sélecteur de boutique du back-office choisit pour qui on règle ; l’en-tête de la page le dit, et l’onglet Connexion dit d’où vient chaque clé (« Enregistré pour : cette boutique », « Toutes les boutiques », « Groupe … »).

Une boutique qui enregistre une clé à elle (collée ou par « Connexion avec Wajub ») a désormais sa propre connexion entière : elle garde l’environnement qu’elle avait (elle ne suit plus « Toutes les boutiques » quand celle-ci passe en production), et ne paie jamais avec la clé de l’autre environnement enregistrée au-dessus d’elle, qui peut être celle d’une autre entreprise Wajub. Connectée en test alors qu’elle était en production, elle reste en production sans clé : elle n’accepte aucun paiement tant que sa clé de production n’est pas ajoutée, ou que le mode Test n’est pas choisi, et la page le dit. Une boutique qui n’a choisi que son environnement garde les clés partagées.

Une boutique qui s’est connectée seule revient à la connexion partagée avec **Utiliser la connexion enregistrée pour …** : sa clé et son webhook restent dans le tableau de bord Wajub, où on les supprime, et d’où se remboursent les commandes déjà payées avec. C’est refusé tant qu’une de ses commandes a un paiement en cours fait avec sa clé (la page nomme les commandes) : lu avec la clé partagée, Wajub ne le connaîtrait pas, et la tâche planifiée annulerait la commande.

L’adresse du webhook nomme la portée qui l’a enregistrée (`…/module/wajub/webhook?scope=s2`, `scope=all`) : chaque portée a son endpoint chez Wajub, avec son secret. Chaque commande est réglée avec les réglages de sa propre boutique, quelle que soit l’adresse par laquelle la notification arrive, et une notification est jugée selon l’environnement de la boutique de la commande (une boutique en test qui utilise le webhook de « Toutes les boutiques », en production, reçoit bien ses notifications de test). Une boutique qui utilise le webhook d’une portée au-dessus n’a pas à enregistrer le sien. La tâche planifiée a une seule adresse pour toutes les boutiques.

### Services externes

Le module ne fonctionne qu’avec Wajub, un service de paiement. Il appelle :

- **L’API Wajub** (`https://api.wajub.com`), avec la clé secrète de la boutique dans l’en-tête `Authorization` et l’agent `Wajub-PrestaShop/<version> PrestaShop/<version> PHP/<version>` :
  - au tunnel de commande, pour savoir quels moyens de paiement le compte propose (`GET /payments/options` : devise, montant et pays de l’adresse de facturation ; réponse gardée 10 minutes, 1 minute en cas d’échec ; jamais depuis le back-office) ;
  - dès que Mobile Money ou Carte est choisi, avant que la commande n’existe : un paiement préparé (montant, devise, une référence `psf_…`, la description « Commande chez <boutique> », le type de moyen, les identifiants du panier et de la boutique), sans rien sur le client ; mis à jour si le panier change, et jamais débité s’il n’est pas activé ;
  - au clic sur **Commander** : l’activation de ce paiement, ou un paiement sur la page Wajub, avec le nom, l’adresse e-mail et le téléphone du client (au format international, avec l’indicatif du pays de son adresse), l’adresse de retour, la référence de la commande (« Order #<référence> »), sa description et ses identifiants (commande, référence, panier, boutique) ;
  - pour lire un paiement (son statut, ses remboursements), annuler un paiement laissé ouvert, clore un paiement échelonné, rembourser, et vérifier une clé (nom de l’entreprise Wajub) ;
  - pour enregistrer le webhook : l’adresse du webhook de la boutique, les événements écoutés et la description « PrestaShop: <boutique> ».
- **Wajub.js** (`https://js.wajub.com`), chargé par le navigateur du client sur la page de commande seulement, quand Mobile Money ou Carte est activé : Wajub voit l’adresse IP du visiteur, son navigateur et l’adresse de la page. Ce que le client saisit dans le formulaire (numéro Mobile Money, carte) va directement chez Wajub, jamais par la boutique.
- **La page de paiement Wajub** (`https://pay.wajub.com`), pour « Autres moyens de paiement », « Payer le reste », et quand le formulaire ne peut pas servir.
- **Le tableau de bord Wajub** (`https://dashboard.wajub.com`), seulement pour « Connexion avec Wajub », quand un employé clique sur le bouton : le nom et le domaine de la boutique, l’environnement demandé, le type de client (`prestashop`), une empreinte de l’adresse de la boutique et les permissions demandées ; en retour, la clé, son type, ses permissions, sa date d’expiration et le nom de l’entreprise.

Les [conditions marchands](https://wajub.com/legal/merchant-terms), les [conditions utilisateurs](https://wajub.com/legal/end-user-terms) et la [politique de confidentialité](https://wajub.com/legal/privacy-policy) de Wajub s’appliquent.

### Confidentialité (RGPD)

Le module ne garde ni nom, ni adresse e-mail, ni téléphone, ni carte. Il garde :

- les paiements Wajub des commandes (`ps_wajub_payment` : identifiants, montants, statut, moyen de paiement comme « MTN Mobile Money », environnement, l’identifiant du client), leurs remboursements (`ps_wajub_refund`) et les notes du bloc « Paiement Wajub » (`ps_wajub_order_note`), qui nomment des paiements, jamais une personne ;
- l’e-mail de confirmation d’une commande retenu jusqu’à son paiement (`ps_wajub_state`, 30 jours au plus), qui contient le nom, l’adresse e-mail et les adresses du client.

Avec le module **RGPD officiel** de PrestaShop (psgdpr), l’export des données d’un client contient ses paiements Wajub (moyen de paiement et statut en clair), et l’effacement supprime les e-mails retenus et retire l’identifiant du client de ses paiements, qui restent comme trace de l’argent des commandes. Un client désigné l’est seul : jamais les invités qui ont la même adresse e-mail (PrestaShop laisse ouvrir un compte avec l’adresse d’un invité, sans la vérifier). Une demande par adresse e-mail seule (un non-client, depuis le back-office) couvre les clients et invités de cette adresse. Ce que Wajub garde d’un payeur s’efface depuis le tableau de bord Wajub ou auprès de Wajub.

Cookies : le module n’a pas de cookie à lui. Quand il garde un message pour la page suivante (une commande rendue en panier, un paiement qui n’a pas pu démarrer), il passe, comme PrestaShop, par la session PHP, qui pose son cookie de session (`PHPSESSID`, strictement nécessaire, effacé à la fermeture du navigateur). Le formulaire de paiement est servi par Wajub.js (`js.wajub.com`) dans un cadre : ce qui s’y passe relève de la politique de confidentialité de Wajub.

### Désinstallation

La désinstallation (et « Réinitialiser ») supprime les réglages, les clés et les secrets, les tables de travail (`ps_wajub_state`, `ps_wajub_event`) et l’onglet du back-office. Elle garde les commandes, leurs paiements PrestaShop, la trace de leurs paiements et remboursements Wajub et leurs notes (`ps_wajub_payment`, `ps_wajub_refund`, `ps_wajub_order_note`) : l’historique des ventes, qu’une réinstallation retrouve. Un état de commande qu’une commande a utilisé reste, marqué supprimé, et revient à la réinstallation. Les clés créées par « Connexion avec Wajub » et les endpoints de webhook restent dans le tableau de bord Wajub (Développeurs), où on les supprime.

### Modules de commande en une page

Le module a été vérifié avec le tunnel de commande de PrestaShop, thèmes Classic (PrestaShop 8) et Hummingbird (PrestaShop 9). Les modules de commande en une page payants (One Page Checkout PS, The Checkout, SuperCheckout…) n’ont pas pu être installés pour les essais ; voici ce dont le module a besoin, et ce qu’il fait quand ils redessinent la page :

- les options de paiement gardent leurs boutons radio `name="payment-option"`, et le bloc de chaque option Wajub (`[data-wajub-fields]`, `form[data-wajub-form]`) reste dans le bloc de son option, comme le dessine le gabarit de paiement de PrestaShop ;
- la page est surveillée : quand les options sont redessinées (après un changement d’adresse ou de transporteur), le formulaire est remonté dans le nouveau bloc, sur le même paiement préparé ; un module peut aussi appeler `window.WajubCheckout.sync()` après avoir redessiné les options. Le jeton du panier, son identifiant et le pays de facturation sont lus dans les options telles qu’elles viennent d’être dessinées (un compte invité créé, une adresse changée) ;
- tout ce qui soumet le formulaire d’une option Wajub passe par la même porte : le bouton **Commander** de PrestaShop, le bouton d’un module qui le clique ou soumet le formulaire, ou un `submit()` en JavaScript ;
- si rien de cela ne marche (des boutons radio nommés autrement, un bloc Wajub loin de son bouton), le formulaire de l’option n’est jamais monté, et sa soumission part sans paiement préparé : le client paie sur la page Wajub, la commande n’est jamais perdue. De même si les options sont redessinées pendant que la commande est créée : le paiement continue sur la page Wajub.

## Pour les développeurs

### Architecture

| Chemin | Contenu |
| --- | --- |
| `wajub.php` | La classe du module : installation, désinstallation, hooks (`Wajub::HOOKS`), version (`Wajub::VERSION`) |
| `config.xml` | Ce que PrestaShop lit sans charger le module ; même version que `Wajub::VERSION` (vérifié par `bin/lint-module.php`) |
| `controllers/admin/AdminWajubConfigurationController.php` | La page de réglages (Smarty, contrôleur legacy : le même code sur PrestaShop 8.1 et 9) et ses actions AJAX |
| `controllers/front/webhook.php` | Le webhook (`?scope=` en multiboutique) |
| `controllers/front/{prepare,validation,status,return,release,pay}.php` | Le tunnel : paiement préparé, « Commander », statut suivi par la page de confirmation, retour du payeur, commande rendue en panier, « Payer le reste » |
| `controllers/front/cron.php` | La tâche planifiée (nettoyage, relecture, rappels de clés) |
| `src/` | Tout le reste, `Wajub\PrestaShop\` en PSR-4, construit par `Services` pour une portée (boutique, groupe, toutes) |
| `src/Settings/` | Réglages par portée (`Scope`, `Settings`, `ConfigStore`), environnements, chiffrement des secrets |
| `src/Api/`, `src/Http/` | Client de l’API sur ext-curl, sans dépendance |
| `src/Pairing/`, `src/Connection/` | « Connexion avec Wajub », état de la connexion, clés, rappels des clés qui expirent (`KeyReminders`) |
| `src/Checkout/`, `src/Order/`, `src/Payment/`, `src/Refund/` | Offre du compte, paiements préparés, commande, grand livre, règlement, échéances, remboursements, nettoyage |
| `src/Webhook/` | Réception, signature, idempotence, gestionnaires d’événements, santé, enregistrement |
| `src/Privacy/` | Export et effacement des données d’un client (hooks RGPD) |
| `src/Admin/` | Ce que la page de réglages et le bloc de commande affichent |
| `views/` | Gabarits Smarty, scripts, styles, logos des moyens de paiement |
| `mails/en`, `mails/fr` | E-mail d’une clé qui expire (HTML et texte) |
| `translations/fr-FR/` | Le catalogue français que PrestaShop charge, écrit par `bin/i18n.php build` |
| `upgrade/` | Scripts de mise à jour |
| `tests/` | Tests unitaires (PHP brut, doublures de PrestaShop), tests des scripts (node), intégration sur une boutique locale |

Points d’entrée : `$module->services()` (portée du contexte), `$module->servicesFor($idShop)` (la boutique d’une commande), `$module->servicesOf($scope)`. Une commande s’écrit toujours sous le verrou `order:<id>`.

### Hooks

`paymentOptions`, `displayPaymentReturn`, `displayOrderDetail`, `actionFrontControllerSetMedia`, `displayAdminOrderMain`, `actionAdminControllerSetMedia`, `actionBeforeUpdateCancelProductFormHandler`, `actionOrderSlipAdd`, `actionOrderStatusPostUpdate`, `actionEmailSendBefore`, `actionCronJob`, `displayAdminAfterHeader`, `actionAdminControllerInitAfter`, `actionExportGDPRData`, `actionDeleteGDPRCustomer`. Une seule liste, `Wajub::HOOKS`, enregistrée à l’installation. PrestaShop 9 dessine ses pages legacy (tableau de bord, transporteurs, statistiques…) sans `displayAdminAfterHeader` : les alertes de clé (`Wajub::keyNotices()`) y passent par les avertissements du contrôleur (`actionAdminControllerInitAfter`), et la page de réglages du module les affiche elle-même. Le module n’enregistre pas `registerGDPRConsent` : ce hook ajoute une case de consentement aux formulaires d’un module, et le module n’en a pas.

Le hook `actionWajubPrepareLimits` (`['limits' => &$limits]`) permet à un autre module de changer les limites du paiement préparé (10 par minute et par adresse, 30 pour la boutique).

### Contrats PHP ↔ JS

- `window.wajubFields` (page `order`) : `methods` (par type : composant, langue, apparence, options, textes), `prepareUrl`, `validationUrl`, `cart`, `token`, `country` (valeurs du chargement de la page, en repli). Boîte `[data-wajub-fields=<type>][data-wajub-country]` avec `[data-wajub-fields-mount]`, `-error` (alerte toujours présente, vide quand rien n’est à dire), `-status`, `-actions` ; formulaire `form[data-wajub-form=<type>]` avec `input[name=token]`, `input[name=cart]` et `[data-wajub-prepared]` : le script lit jeton, panier et pays dans les options dessinées en dernier.
- `views/js/checkout-fields.js` : montage à la sélection, `MutationObserver` et événements de PrestaShop (`updatedDeliveryForm`, `updatedAddressForm`, `changedCheckoutStep`, `updatedCart`) pour remonter le formulaire dans des options redessinées, `submit()` des formulaires gardé, `window.WajubCheckout.sync()`.
- `views/js/checkout-options.js` : `alt` du logo de chaque option Wajub (`data-wajub-logo-alt`), que les thèmes laissent vide.
- `window.wajubAdmin`, `window.wajubPairing` (page de réglages), `window.wajubOrderStatus` (page de confirmation).

### Tests

```sh
composer install                                   # outils de dev dans vendor/ (jamais livrés)
for f in tests/run-*-tests.php; do php "$f"; done  # 11 lanceurs PHP
node tests/run-fields-js-tests.js                  # scripts du tunnel et de la page de confirmation
_PS_ROOT_DIR_=/chemin/vers/prestashop bin/check.sh # tout : syntaxe, règles Addons, tests, traductions, php-cs-fixer, PHPStan
```

Intégration, sur les boutiques locales de `~/.cache/wajub-prestashop/env` (voir `ENV.md`), PrestaShop 8 et 9 en parallèle possible :

```sh
E=~/.cache/wajub-prestashop/env
$E/reset.sh 8                                   # une boutique sans le module
tests/integration/run.sh 8                      # installation, webhook, back-office, formulaires, désinstallation, zip
tests/integration/run-payment.sh 8              # le tunnel avec chaque option, contre un faux Wajub
tests/integration/run-money.sh 8                # payé en partie, reste à payer, remboursements, tâche planifiée
tests/integration/run-finish.sh 8               # deux boutiques à deux adresses, clé qui expire (e-mail), RGPD, contenu du zip
```

`api-stub.py` remplace l’API (activé par `_WAJUB_API_URL_`, et Wajub.js par `_WAJUB_JS_URL_`, lus seulement en mode debug) ; `smtp-sink.py` garde les e-mails envoyés.

### Qualité et règles Addons

`bin/lint-module.php` (lancé par `check.sh`, et par `build-zip.sh` sur le contenu du zip) vérifie : un `index.php` dans chaque dossier, l’en-tête de licence de chaque fichier PHP, JS, CSS et gabarit, l’arrêt de chaque fichier PHP hors de PrestaShop (`_PS_VERSION_`), aucune fonction refusée (`eval`, `exec`, `serialize`…), aucune variable de requête ou de serveur lue directement (`Tools::getValue()`, la `Request` de Symfony), aucune variable de gabarit affichée sans `|escape:'htmlall':'UTF-8'` (ou `|intval`), chaque texte de gabarit dans un domaine du module, la version de `config.xml`, les e-mails en anglais et en français. PHPStan (niveau 6) tourne contre les sources de PrestaShop 8.2 et 9.0 ; php-cs-fixer suit les règles de PrestaShop ; `env/lint.sh 8` vérifie la syntaxe sous PHP 8.1.

### Traductions

Textes sources en anglais, toujours littéraux : `$t->trans('Texte', ['%nom%' => $x], 'Modules.Wajub.Admin')` en PHP, `{l s='Texte' d='Modules.Wajub.Shop'}` en Smarty. Puis :

```sh
php bin/i18n.php missing   # les textes sans français, en JSON à compléter dans i18n/fr-FR.json
php bin/i18n.php build     # écrit translations/fr-FR/*.xlf
php bin/i18n.php check     # couverture, placeholders, typographie (espace insécable avant : ; ! ? et dans « »)
```

Glossaire : Sandbox → « Test », Live → « Production », vouvoiement, « tableau de bord Wajub », « Connexion avec Wajub ».

### Construire et publier

```sh
bin/build-zip.sh           # wajub.zip : un dossier wajub/, l’autoloader du module seul, rien pour les développeurs
```

Pour une version :

1. Changer `Wajub::VERSION` et `<version>` dans `config.xml` (vérifiés ensemble), ajouter la version en tête de `CHANGELOG.md`.
2. Si la version change une table ou ajoute un hook : `upgrade/upgrade-X.Y.Z.php` (voir `upgrade/README.md`) ; une table gardée à la désinstallation (`Schema::KEPT`) change aussi dans `Schema::create()`.
3. `bin/check.sh` (avec `_PS_ROOT_DIR_`), les quatre runners d’intégration sur PrestaShop 8 et 9, puis `bin/build-zip.sh`.
4. Déposer le zip sur PrestaShop Addons (son validateur en ligne) et sur la page de téléchargement de Wajub.
