# EcomEU_DbTool

Export / import **piloté par configuration** de l'empreinte complète d'un client en base de données.

Plutôt que de disperser de la logique « spéciale client » dans le code, le module lit une **carte des
données client** (*Customer Data Map*) qui déclare quelles tables contiennent des données du client,
comment chacune est reliée au client et dans quel ordre les traiter. Le moteur d'export / import ne
fait qu'interpréter la carte : ajouter une table ne demande jamais de toucher au moteur.

## Commandes

```bash
# Export -> var/export/specialcustomer/<customer_id>/*.csv  (un CSV par table) + <customer_id>.zip
bin/magento Tool:export:specialcustomer --customer_id=564321

# Import <- var/import/specialcustomer/<customer_id>/*.csv
bin/magento Tool:import:specialcustomer --customer_id=564321

# Import en tant que NOUVEAU client, avec un entity_id neuf (ex. : données de prod dans un env local)
bin/magento Tool:import:specialcustomer --customer_id=564321 --mode=clone

# Répétition générale : tout l'import est exécuté puis annulé (rollback), rien n'est écrit
bin/magento Tool:import:specialcustomer --customer_id=564321 --dry-run
```

`--customer_id` doit être un entier strictement positif (`0`, `-1`, `12abc` sont refusés avant tout
accès à la base).

Chaque import affiche à la fin le ou les clients obtenus (`Customer: id=… email=…` en mode
*restore* ; en mode *clone* le libellé est `New customer` suivi du jeton de clonage), par exemple :

```
New customer: id=812  email=jane@example.com
Clone token: k3f9zq
```

- Chaque table devient son propre CSV (`customer_entity.csv`, `quote.csv`, `sales_order_item.csv`, …),
  lisible et modifiable à la main avant import.
- L'export **écrase** le répertoire du client à chaque exécution, supprime le zip de l'exécution
  précédente, puis écrit une archive `var/export/specialcustomer/<id>.zip` regroupant tous les CSV
  pour faciliter le transfert. Le zip nécessite l'extension PHP `zip` ; sans elle les CSV sont tout
  de même écrits et un avertissement est affiché.
- Un export qui **échoue** (erreur SQL, client inexistant…) ne laisse rien derrière lui : le
  répertoire partiel et l'ancien zip sont supprimés, pour qu'un export périmé ne passe jamais pour
  un export à jour.
- L'import **réattribue les identifiants en cas de collision** : si une clé primaire est déjà prise
  sur la cible, la ligne est insérée avec un nouvel id et toutes les clés étrangères sont réécrites
  pour que le graphe parent / enfant reste cohérent. Déposer les fichiers exportés dans
  `var/import/specialcustomer/<id>/` pour importer.
- L'import est **une seule transaction** : un échec sur n'importe quelle table annule toute
  l'exécution, la base cible ne se retrouve jamais avec une demi-empreinte. Un échec d'insertion
  est remonté avec la table et la clé de la ligne fautive (`cannot insert into sales_order
  (entity_id = 123): …`), pour trouver la cause (index unique secondaire, clé étrangère vers une
  table hors carte, colonne trop courte…) sans fouiller le SQL.
- `--dry-run` déroule **tout** l'import (correspondances, insertions, réécritures de références)
  dans la transaction puis l'annule au lieu de la valider. Le rapport est identique à un vrai
  import (lignes écrites, réutilisées, abandonnées, colonnes ignorées, client résultant) ; la base
  est inchangée. À utiliser avant tout import sur une base qui compte.
- Une table de la carte dont le CSV **manque** dans le répertoire d'import est ignorée et listée à
  la fin (`Skipped (no CSV present): …`) ; un CSV réduit à son en-tête importe zéro ligne. Les deux
  cas sont inoffensifs : un répertoire d'import élagué à la main fonctionne.
- Une **colonne d'un CSV absente de la table cible** (export depuis un schéma plus récent, colonne
  ajoutée à la main, faute de frappe dans l'en-tête) est ignorée et **signalée** à la fin
  (`customer_entity: column(s) not on this database ignored: …`), jamais passée sous silence.
- Une table de la carte qui **n'existe pas en base** (table propre à une boutique, comme
  `contrat_aladin_clients` sur une autre installation) est ignorée et listée, à l'export comme à
  l'import.
- Les **colonnes structurelles** de la carte (clé primaire, clé étrangère, colonne client, clé
  naturelle) doivent exister sur la table cible ; sinon l'import échoue avant d'écrire quoi que ce
  soit, en nommant la table, la colonne et le champ de la carte concerné.
- Exporter un client **inexistant** (aucune table ne renvoie de ligne) échoue avec une erreur et ne
  laisse aucun répertoire d'export.
- Le `NULL` SQL est écrit dans le CSV comme `\N` (convention MySQL) afin que `NULL` et la chaîne
  vide survivent à l'aller-retour comme deux valeurs distinctes. Une cellule vide reste une chaîne
  vide pour une colonne texte ; pour une colonne typée (int, decimal, datetime…) elle est importée
  en `NULL`, ce qui garde importables les fichiers écrits avant cette convention.
- Le dialecte CSV est du RFC 4180 pur (virgule, guillemets doublés, pas de caractère d'échappement),
  écrit et lu par le module lui-même. L'assistant CSV de Magento est volontairement écarté : il
  préfixe d'un espace toute valeur commençant par `=`, `+` ou `-`, ce qui corromprait les numéros
  de téléphone du type `+33 6…` à l'aller-retour.
- Un CSV dont l'en-tête contient un **nom de colonne vide ou en double** est refusé : un tel
  fichier rangerait des valeurs dans la mauvaise colonne.
- Les lignes sont importées **telles quelles** : `customer_entity` garde son `password_hash` /
  `rp_token`, les commandes gardent leur `protect_code`, etc. Attacher un
  [processeur](#processeurs-personnalisés) pour nettoyer ce qui ne doit pas arriver sur la cible.

### Modes d'import (`--mode`)

- **`restore`** (défaut) — faire correspondre et réutiliser les lignes existantes : un client déjà
  présent (par `email` + `website_id`), un produit (par `sku`), une commande (par `increment_id` +
  `store_id`) est réutilisé, pas dupliqué. Réimporter une empreinte dans une base qui l'a déjà ne
  crée donc rien.
- **`clone`** — **forcer la création d'un client tout neuf** et de toute son empreinte (adresses,
  paniers, commandes + enfants) sous forme de nouvelles lignes, sans toucher au client existant.
  C'est le mode pour rapatrier des données de prod dans un environnement de dev afin de reproduire
  un cas. Les données de catalogue partagées restent réutilisées : produits (`sku`) et groupe client
  (`code`) sont mis en correspondance, pas clonés.
  - **Le client reçoit toujours un `entity_id` neuf** (et chaque commande un `entity_id` neuf) : la
    clé primaire exportée est abandonnée et la base alloue un nouvel auto-incrément, même sur une
    base de dev vide où l'id source était libre. Toutes les clés étrangères (`customer_id`,
    `parent_id`, `order_id`, liens panier / EAV / grille) sont réécrites vers les nouveaux ids, le
    graphe reste cohérent.
  - **Les clés métier sont conservées si libres, régénérées seulement en cas de collision.**
    L'`email` réel de prod du client et l'`increment_id` de chaque commande sont importés tels quels
    quand la cible ne les a pas déjà ; seule une valeur qui violerait l'index unique est réécrite
    (`email` → `local+clone-<token>@domaine`, `increment_id` → `<valeur>-<token>`), `<token>` étant
    un court identifiant propre à l'exécution. Le premier clone dans une base de dev vierge garde
    donc l'email réel ; un second clone (l'email existe désormais) reçoit un email suffixé.
  - **Le `customer_id` et l'`email` obtenus sont affichés à la fin** pour retrouver la copie. Le
    mode clone est **non idempotent** : chaque exécution produit un client distinct de plus.

  Quelles tables sont clonées ou réutilisées est piloté par la carte : `clone_new` (ignorer la
  correspondance, toujours insérer) est posé sur `customer_entity` et `sales_order` ;
  `clone_regenerate` (colonne → stratégie) rend leurs clés uniques effectivement uniques ;
  `clone_mirror` (colonne → stratégie) fait suivre à chaque copie dénormalisée de la valeur
  (`customer_grid_flat.email`, `quote.customer_email`, `sales_order.customer_email`,
  `sales_order_grid.increment_id` / `customer_email`, `quote_address.email`,
  `sales_order_address.email`) ce que la table décisionnaire a gardé ou régénéré. Voir ces champs
  dans le tableau de la carte ci-dessous.

## La carte des données client

La carte par défaut vit dans `Model/Config/CustomerMap.php`. Chaque entrée décrit une table :

| Champ                   | Obligatoire          | Signification |
|-------------------------|----------------------|---------------|
| `table`                 | oui                  | Nom de la table (sans préfixe de base). |
| `primary_key`           | non (`entity_id`)    | Colonne de clé primaire. |
| `relationship_type`     | oui                  | `direct`, `child` ou `lookup`. |
| `customer_column`       | pour `direct`        | Colonne égale à l'id client (`entity_id` sur `customer_entity`, `customer_id` sur `quote` / `sales_order`). |
| `parent`                | pour `child`         | Nom de la table parente (jamais la table elle-même : un auto-lien se déclare dans `references`). |
| `parent_key`            | pour `child`         | Colonne du parent dont cette table référence les valeurs (en général la PK du parent). |
| `foreign_key`           | pour `child`         | Colonne de cette table pointant sur `parent.parent_key`. |
| `lookup_sources`        | pour `lookup`        | `[{table, column}, …]` — rassembler les ids de cette table depuis ces colonnes de tables déjà exportées. Une source peut ajouter `eav_attribute: '<entity_type_code>/<attribute_code>'` pour ne prendre la colonne que sur les lignes de cet attribut EAV (ex. : `catalog_product_entity_varchar.value` de `family_set`). |
| `lookup_column`         | non (`primary_key`)  | Pour `lookup` : la colonne comparée aux valeurs rassemblées, quand la table est indexée par une valeur métier plutôt qu'un id (ex. : `familyset_code`). |
| `requires`              | non                  | Lignes d'une table **hors carte** dont dépendent les lignes de cette table : `[{column, table, key}, …]` (ex. : `catalog_category_product.category_id` → `catalog_category_entity.entity_id`). À l'import, une ligne dont la ligne requise n'existe pas sur la cible est abandonnée (ou la colonne mise à `NULL` si elle l'accepte) au lieu d'échouer sur la clé étrangère. Une table de la carte ne peut pas être « requise » : elle se déclare dans `references`. |
| `export_priority`       | non (`0`)            | Ordre croissant ; les parents doivent précéder les enfants. |
| `import_priority`       | non (= export)       | Ordre croissant ; les parents doivent précéder les enfants (sécurité des FK). |
| `references`            | non                  | Colonnes FK à réécrire à l'import : `[{column, table}, …]` où `table` est une autre table de la carte. Le lien enfant → parent est dérivé automatiquement ; ne déclarer que les liens croisés. Une référence vers une table importée **plus tard** (ex. : `customer_entity.default_billing` → `customer_address_entity`) est réécrite par `UPDATE` à la fin de l'exécution. |
| `unique_key`            | non                  | Colonnes de clé naturelle (ex. : `[email, website_id]`, `[sku]`). Une ligne cible correspondante est réutilisée au lieu d'être insérée. |
| `skip_if_parent_reused` | non                  | Les lignes enfants sont ignorées à l'import quand leur parent a été mis en correspondance avec une ligne cible existante (sous-données produit, grille client). |
| `clone_new`             | non                  | `--mode=clone` seulement : ignorer la correspondance `unique_key` et toujours insérer une nouvelle ligne (création forcée). Posé sur `customer_entity`, `sales_order`. |
| `clone_regenerate`      | non                  | `--mode=clone` seulement, sur une table `clone_new` : carte `colonne => stratégie` réécrivant les clés métier uniques avant insertion (seulement en cas de collision). Stratégies : `email_suffix`, `suffix`. |
| `clone_mirror`          | non                  | `--mode=clone` seulement : carte `colonne => stratégie` pour les colonnes portant une *copie* d'une valeur régénérée ailleurs sous cette stratégie (ex. : `quote.customer_email` reflète `customer_entity.email`). |
| `filter`                | non                  | Conditions WHERE supplémentaires, `['colonne op ?' => valeur]`, liées de façon sûre. |
| `processor`             | non                  | FQCN implémentant `EcomEU\DbTool\Api\TableProcessorInterface` pour un traitement spécial. La classe doit exister, sinon l'exécution échoue avant tout accès à la base. |

Chaque champ est vérifié au chargement : un type inattendu (une chaîne là où un tableau est
attendu, une clé primaire vide, une entrée de `references` / `lookup_sources` / `requires`
incomplète, une stratégie de clonage inconnue…) fait échouer la commande immédiatement avec le nom
de la table et du champ, jamais au milieu d'un export ou d'un import.

### Résolution des relations (export)

- **direct** → `WHERE <customer_column> = :customer_id`
- **child**  → `WHERE <foreign_key> IN (<valeurs de parent_key collectées en exportant le parent>)`
- **lookup** → `WHERE <lookup_column> IN (<valeurs collectées depuis les colonnes lookup_sources>)`

Les tables étant traitées par ordre de priorité, les valeurs de clé d'un parent sont toujours
collectées avant que ses enfants soient interrogés. Un enfant (ou lookup) qui ne résout aucun id
produit un CSV réduit à son en-tête.

Une liste `IN (...)` est **découpée par tranches de 5 000 valeurs** (une requête par tranche, les
résultats sont concaténés) ; une grosse empreinte (des milliers de produits, des centaines de
milliers de lignes EAV) ne produit donc jamais une requête démesurée qui dépasserait
`max_allowed_packet`. Les vérifications d'existence de l'import (ids déjà pris, liens pendants)
sont découpées de la même manière.

La carte fusionnée est validée au chargement : aucune table ne peut être cartographiée deux fois,
chaque `parent`, `references[].table` et `lookup_sources[].table` doit nommer une table de la
carte, une table ne peut pas être son propre parent, un `requires` ne peut pas viser une table de
la carte, un enfant doit avoir un `export_priority` **et** un `import_priority` supérieurs à ceux de
son parent, et une table lookup doit s'exporter après toutes ses sources. Une entrée invalide (y
compris injectée depuis `di.xml`) échoue immédiatement avec une erreur explicite.

### Import : réattribution des ids et préservation des clés étrangères

Les tables s'importent dans l'ordre d'`import_priority` (parents d'abord). Le moteur tient une
carte d'ids (ancien id → id sur la cible) par table et réécrit les `references` des tables
suivantes à partir d'elle, pour que les liens survivent même quand les ids changent. Par ligne :

1. Si `unique_key` est défini et qu'une ligne cible correspond → **réutiliser** son id (pas
   d'insertion).
2. Sinon, si la clé primaire est libre sur la cible → insérer en gardant l'id d'origine.
3. Sinon (collision) → insérer sans l'id et capturer le nouvel auto-incrément.

Seule une clé auto-incrémentée peut être allouée par la base : pour une table dont la clé primaire
ne l'est pas (grilles, clés composées), la valeur exportée *est* l'id, et une ligne qui n'en
porte pas ne crée aucune entrée dans la carte d'ids (elle n'est jamais confondue avec le dernier id
alloué sur la connexion).

Une ligne ignorée parce que son parent a été réutilisé (`skip_if_parent_reused`) est abandonnée
avec **ses propres** enfants (cascade), une adresse ignorée ne laisse donc jamais de lignes
`customer_address_entity_*` orphelines.

Les références qui pointent *en arrière* vers une table importée plus tard
(`customer_entity.default_billing` / `default_shipping` → adresses, `sales_order.billing_address_id`
/ `shipping_address_id` → adresses de commande) sont mémorisées par ligne insérée et appliquées en
`UPDATE` après la dernière table, une fois les ids cibles connus. Cet `UPDATE` réaffirme l'`updated_at`
exporté, pour que `ON UPDATE CURRENT_TIMESTAMP` de MySQL ne le modifie pas en douce. Les lignes
réutilisées depuis la cible gardent leurs propres liens.

**Les liens pendants sont mis à `NULL`.** Un lien croisé dont la ligne cible est inconnue de
l'exécution (absente de l'export, ex. : le panier d'origine d'une commande a été purgé ; ou ignorée
parce que son parent a été réutilisé, ex. : l'entrée du carnet d'adresses derrière
`quote_address.customer_address_id` quand le client existait déjà) est recherché sur la cible : si
cet id y existe il est conservé (sur une restauration dans la même base c'est la bonne ligne) ;
sinon la colonne est mise à `NULL` si elle l'accepte, et si elle ne l'accepte pas la ligne est
**abandonnée** avec ses propres enfants (un lien produit vers un produit qui n'existe nulle part sur
la cible, un placement de catégorie dont la catégorie manque). L'exécution indique combien de liens
ont été annulés et combien de lignes abandonnées. Les liens structurels ne sont jamais touchés : le
lien d'un enfant vers son parent et la `customer_column` d'une table directe doivent se résoudre,
sinon l'exécution échoue sur la clé étrangère.

**Les produits** (`catalog_product_entity`, un `lookup` depuis les `product_id` des lignes) sont
exportés *après* les lignes mais importés *avant* elles (priorités 70/71 contre 5/6), de sorte que
`sales_order_item.product_id` / `quote_item.product_id` sont réécrits vers le produit cible
correspondant par `sku`. Les sous-tables produit (EAV / site / stock) sont `skip_if_parent_reused`,
les produits existants ne sont donc pas touchés ; seul un produit inséré comme ébauche (sku
manquant) les reçoit.

> **Réserve sur les produits entre bases :** faire correspondre un produit existant par `sku` et
> réécrire est fiable. Pour un sku *manquant*, les lignes produit exportées sont insérées au mieux,
> mais les `attribute_id` EAV produit peuvent différer d'une base à l'autre, une ébauche de produit
> peut donc être imparfaite. Pour une parité produit complète entre environnements, utiliser
> `scripts/db-sync-gui/`.

> **Note d'idempotence :** clients (`email`), commandes et leurs documents (`increment_id` +
> `store_id`), produits (`sku`), groupes (`code`) et prix family-set sont mis en correspondance et
> jamais dupliqués. Les lignes sans clé naturelle (adresses, paniers, messages cadeaux, valeurs EAV,
> grilles) pendent à un parent mis en correspondance et sont ignorées quand il a été réutilisé ;
> réimporter une empreinte déjà présente ne crée donc rien.

> **Clés uniques secondaires :** le moteur réattribue la clé primaire mais insère les autres
> colonnes telles quelles, un index unique *secondaire* pourrait donc entrer en collision. Le cas
> connu, `sales_order`, est traité : il a `unique_key => ['increment_id', 'store_id']`, une commande
> déjà présente sur la cible (même numéro) est donc **mise en correspondance et réutilisée** au lieu
> d'être réinsérée, et ses enfants (`sales_order_address` / `_item` / `_payment` / `_grid`) sont
> `skip_if_parent_reused`. L'import de commandes est ainsi idempotent et évite l'erreur
> `SALES_ORDER_INCREMENT_ID_STORE_ID` (1062).
>
> La correspondance se fait sur `(increment_id, store_id)` : si deux bases *différentes* réutilisent
> la même séquence de numéros pour des commandes différentes, une commande entrante peut
> correspondre à une commande cible sans rapport et être ignorée — inhérent à l'usage du numéro de
> commande comme identité ; la cible visée est une restauration / copie ou une base qui ne contient
> pas déjà les commandes de ce client. Si une *nouvelle* table rencontre la même classe de problème,
> lui donner une `unique_key` (pour correspondre / réutiliser) de la même façon, ou en faire un
> `child` d'un parent mis en correspondance avec `skip_if_parent_reused` (comme les paniers pendent
> au client).

> **Tables sans clé primaire de substitution :** certaines tables identifient une ligne par une
> colonne métier plutôt qu'un auto-incrément (ex. : `contrat_aladin_clients`, dont l'identité est
> l'`int` unique `contrat_client`, sans PK ni auto-incrément). Pour celles-ci, poser **à la fois**
> `primary_key` et `unique_key` sur cette clé métier — `primary_key => 'contrat_client'`,
> `unique_key => ['contrat_client']`. `primary_key` doit nommer une vraie colonne (la requête de
> correspondance la sélectionne ; le défaut `entity_id` est désormais refusé avant tout écriture) et
> `unique_key` fait qu'une ligne existante est réutilisée plutôt que réinsérée (ce qui provoquerait
> son 1062). En `--mode=clone` une telle ligne est mise en correspondance (pas clonée) — son index
> unique porte sur `contrat_client` seul, elle ne doit donc **pas** être `clone_new` ; forger un
> nouvel id métier (et les lignes dépendantes) est hors périmètre. Ses `contract_code` /
> `contrat_client_type_id` référencent `contrat_aladin`, hors carte, qui doit déjà exister sur la
> cible.

## Tests

Deux suites :

- **Unitaires** (`Test/Unit/`, exécutés en CI) : PHP pur, sans Magento ni base. Ils couvrent la
  validation d'une entrée de carte, la cohérence de la carte fusionnée (y compris la carte par
  défaut livrée et les surcharges `di.xml`) et le dialecte CSV (aller-retour des valeurs délicates,
  fichiers édités à la main, en-têtes refusés). Depuis la racine du module :

  ```bash
  composer install
  vendor/bin/phpunit
  ```

- **Intégration** (`Test/Integration/`) : le vrai moteur contre la base de l'installation Magento où
  le module est déployé. Chaque test construit une empreinte client complète (client, adresse,
  produit, panier avec lignes parent / enfant et option, commande avec adresses, lignes, paiement,
  facture, expédition, …) dans une transaction annulée ensuite, rien n'est laissé derrière.
  Couvert : l'export écrit un CSV par table avec les bonnes lignes et les valeurs délicates intactes,
  et supprime le zip périmé ; l'import sur le même compte (réutilisé, rien dupliqué), sur des ids
  pris (ids neufs, chaque lien réécrit), sur un compte libre (ids conservés), le mode clone, le
  `--dry-run` (rapport complet, base inchangée) et la colonne inconnue signalée. Depuis la racine
  Magento, le module devant être activé :

  ```bash
  vendor/bin/phpunit -c app/code/EcomEU/DbTool/Test/Integration/phpunit.xml
  ```

## Étendre la carte depuis un autre module

Ajouter des tables sans modifier ce module en injectant dans `MapProvider` depuis votre propre
`etc/di.xml`. Une entrée portant la même clé de tableau qu'une entrée par défaut **la remplace**.

```xml
<type name="EcomEU\DbTool\Model\MapProvider">
    <arguments>
        <argument name="tables" xsi:type="array">
            <item name="my_custom_table" xsi:type="array">
                <item name="table" xsi:type="string">my_custom_table</item>
                <item name="primary_key" xsi:type="string">entity_id</item>
                <item name="relationship_type" xsi:type="string">child</item>
                <item name="parent" xsi:type="string">customer_entity</item>
                <item name="parent_key" xsi:type="string">entity_id</item>
                <item name="foreign_key" xsi:type="string">customer_id</item>
                <item name="export_priority" xsi:type="number">100</item>
                <item name="import_priority" xsi:type="number">100</item>
            </item>
        </argument>
    </arguments>
</type>
```

## Processeurs personnalisés

Pour un traitement qui ne s'exprime pas de façon déclarative (nettoyer des hachages de mot de passe,
retirer des colonnes générées, remapper des valeurs), implémenter
`EcomEU\DbTool\Api\TableProcessorInterface` et la référencer par FQCN dans le champ `processor` de
l'entrée. Le moteur appelle `processExportRows()` / `processImportRows()` autour des lignes de cette
table. Un processeur doit renvoyer des valeurs scalaires (ou `null`) : une valeur imbriquée
(tableau, objet) est refusée à l'écriture du CSV avec la table, la ligne et la colonne nommées.

## Notes et hypothèses

- Aucun changement de schéma : le module ne fait que lire / écrire des tables existantes.
- **Les attributs EAV client et adresse sont capturés.** `customer_entity` et
  `customer_address_entity` sont des entités EAV, leurs attributs personnalisés vivent dans les
  tables de valeurs `customer_entity_{datetime,decimal,int,text,varchar}` et
  `customer_address_entity_{…}`. Elles sont déclarées comme entrées `child` (FK `entity_id`). Les
  tables de valeurs client et les adresses sont `skip_if_parent_reused` — quand un client est mis
  en correspondance par email sur la cible, ses lignes d'attributs et ses adresses y existent déjà et
  sont laissées intactes (les lignes de valeurs d'adresse suivent par cascade) ; seul un client
  nouvellement inséré les reçoit.
- **PHP 8.3+ requis** (constantes de classe typées). La CI exécute PHPCS, PHPStan et les tests
  unitaires sur 8.4.
- **Le groupe client est synchronisé.** `customer_group` est un `lookup` rassemblé depuis les ids de
  groupe référencés par le client / paniers / commandes. À l'import il est **mis en correspondance
  par `customer_group_code`** pour réutiliser l'id de groupe de la cible (les groupes standard
  correspondent toujours ; un groupe personnalisé s'insère avec un id neuf), et
  `customer_entity.group_id` / `quote.customer_group_id` / `sales_order.customer_group_id` /
  `catalog_product_entity_tier_price.customer_group_id` sont réécrits vers lui. Il s'exporte en
  dernier (après les prix dégressifs) mais s'importe en premier — le motif « priorités séparées ».
- **Les prix dégressifs produit sont synchronisés.** `catalog_product_entity_tier_price` est un
  `child` du produit (`skip_if_parent_reused`), les produits existants gardent donc leurs prix et
  seul un produit inséré comme ébauche (sku manquant) les reçoit ; `customer_group_id` sur chaque
  ligne est réécrit vers le groupe correspondant. `website_id` n'est **pas** réécrit — comme
  `store_id`, `store_website` est une table globale référencée qui doit déjà coïncider sur la cible.
- **Le jeu de valeurs EAV produit est complet et par magasin.** Les six backends sont capturés —
  `catalog_product_entity_{datetime,decimal,int,text,varchar,gallery}`. Ces tables sont indexées par
  `store_id` et l'export n'applique **aucun filtre de magasin** (il prend toutes les lignes de l'id
  produit), donc **les surcharges d'attributs et de prix par magasin** (ex. : un `special_price`
  propre à un magasin dans `catalog_product_entity_decimal`) arrivent toutes comme des lignes
  séparées, automatiquement.
- **Les lignes de panier configurable / bundle gardent leur sélection.** Une ligne configurable /
  bundle est une paire `quote_item` parent + enfant (liée par `parent_item_id`, une auto-référence
  que le moteur réécrit), et les options choisies vivent dans `quote_item_option`
  (info_buyRequest / attributes / simple_product / valeurs bundle et options personnalisées) — les
  deux sont synchronisés. (Les lignes de commande gardent de même leur auto-lien `parent_item_id` et
  leur lien `quote_item_id` vers la ligne de panier réécrite ; leurs options restent en ligne dans
  `sales_order_item.product_options`.) **Réserve :** ces charges utiles d'options sont *sérialisées*
  et embarquent des ids de produit / attribut qui ne sont **pas** réécrits. C'est sans conséquence
  pour une restauration / un clone dans la même base (les produits sont mis en correspondance par
  `sku` vers les mêmes lignes, les ids embarqués restent valides) ; sur un import *entre bases* où
  un sku a un `product_id` différent, une sélection embarquée peut être périmée.
- L'import suppose que les lignes référencées dans les tables **hors carte** (magasin, site, classe
  de taxe, …) existent déjà dans la base cible.

### Limites connues

- **Une restauration ne met jamais à jour une ligne existante.** Un client déjà sur la cible est mis
  en correspondance et laissé tel quel : de nouvelles adresses, un panier plus récent, des attributs
  modifiés ou une nouvelle adresse par défaut côté source ne sont **pas** appliqués, et ses commandes
  passées depuis sont importées mais liées à aucune entrée du carnet d'adresses. Pour rafraîchir un
  client qui existe déjà localement, utiliser `--mode=clone` (copie neuve complète) ou supprimer
  d'abord le client local.
- **Les vrais numéros de commande sont importés.** Le compteur `sequence_order_<store>` de la cible
  n'est pas avancé, une commande passée plus tard sur la cible peut donc finir par atteindre un
  `increment_id` importé et échouer sur l'index unique. Sans importance pour une base de dev
  jetable ; avancer la séquence si la cible continue de prendre des commandes.
- **La mémoire est proportionnelle à la plus grosse table.** Chaque table est lue et écrite comme
  un seul tableau (environ 1 Ko par ligne). Les tables EAV produit dominent : un client avec 2 000
  produits distincts tire ~700 000 lignes, exportées en ~11 s sous une limite mémoire CLI de 10 Go ;
  à garder en tête avec un `memory_limit` serré. Les requêtes, elles, sont découpées (voir plus
  haut) et ne sont pas le facteur limitant.
- **Les insertions se font ligne par ligne** (nécessaire pour capturer chaque id auto-incrémenté et
  réécrire les liens) ; un import de plusieurs centaines de milliers de lignes prend des minutes,
  pas des secondes.
- **Les produits ébauches n'ont pas de stock MSI.** `inventory_source_item` (indexée par `sku`) n'est
  pas cartographiée, un produit inséré parce que son `sku` manquait n'a donc pas de source item ;
  les produits existants ne sont pas concernés.

### Également synchronisé

- **Documents de commande et extras** : `sales_invoice`, `sales_shipment`, `sales_creditmemo` avec
  leurs `_item`, `_comment`, `_grid` (et `sales_shipment_track`), `sales_order_status_history`,
  `sales_order_tax` + `_item`, `sales_payment_transaction`. Tous enfants de la commande, ignorés
  quand la commande a été mise en correspondance. Un document a son propre `increment_id` unique par
  magasin, il est donc mis en correspondance comme une commande et, en `--mode=clone`, créé de force
  avec un numéro suffixé que sa grille reflète.
- **Messages cadeaux** (`gift_message`), enfant du client ; `gift_message_id` sur les paniers,
  commandes et leurs lignes est réécrit.
- **Extras de panier** : `quote_id_mask`, `quote_address_item` (lignes multi-expédition),
  `quote_shipping_rate`.
- **Composition produit** (enfants du produit, ignorés quand il a été mis en correspondance, seul un
  produit ébauche les reçoit) : options personnalisées avec prix / titres par magasin
  (`catalog_product_option` → `_price` / `_title` / `_type_value` → `_type_price` / `_type_title`),
  options / sélections / prix de sélection bundle, liens produit avec leurs attributs de position,
  super-attributs / libellés / liens configurable, `catalog_product_relation`,
  `catalog_category_product`. Un lien vers un produit ou une catégorie qui n'existe nulle part sur la
  cible n'abandonne que cette ligne.
- **Lignes de galerie média** (`catalog_product_entity_media_gallery` mise en correspondance par
  chemin d'image, les lignes `_value`, `_value_to_entity` et `_value_video` pendues au produit). Les
  fichiers image sur disque ne sont **pas** copiés.
- **Prix dégressifs family-set** (`ecomeu_familyset_tier_price`) : un lookup sur `familyset_code`
  rassemblé depuis les valeurs de l'attribut `family_set` des produits, mis en correspondance sur la
  cible par son tuple unique (code, magasin, quantité, date). Ignoré sur une installation sans la
  table.

> **Réserve `attribute_id` EAV entre bases :** les lignes de valeurs EAV (client, adresse, produit)
> portent `attribute_id`. Les attributs standard partagent leurs ids entre installations standard,
> mais un attribut **personnalisé** peut avoir un `attribute_id` différent dans une autre base — y
> importer ses lignes de valeurs les rangerait sous le mauvais attribut. Lors d'une migration
> d'attributs personnalisés entre environnements, vérifier que les ids d'attributs coïncident (ou
> utiliser `scripts/db-sync-gui/`, qui synchronise les définitions d'attributs EAV).
