# EcomEU_DbTool

Generic, **configuration-driven** export/import of a single customer's complete database footprint.

Rather than scattering customer-specific logic across the code, the module reads a **Customer Data
Map** that declares which tables hold customer data, how each relates to the customer, and in what
order to process them. The export/import engine simply interprets the map — adding new tables never
requires touching the engine.

## Commands

```bash
# Export -> var/export/specialcustomer/<customer_id>/*.csv  (one CSV per table)
bin/magento Tool:export:specialcustomer --customer_id=564321

# Import <- var/import/specialcustomer/<customer_id>/*.csv
bin/magento Tool:import:specialcustomer --customer_id=564321

# Import as a brand-new customer with a FRESH entity_id (e.g. prod data into local dev)
bin/magento Tool:import:specialcustomer --customer_id=564321 --mode=clone
```

Every import prints the resulting customer(s) at the end (`Customer: id=… email=…` in restore mode;
in clone mode the label is `New customer` and the per-run clone token follows), e.g.:

```
New customer: id=812  email=jane@example.com
Clone token: k3f9zq
```

- Each table becomes its own CSV (`customer_entity.csv`, `quote.csv`, `sales_order_item.csv`, …),
  human-readable and editable before import.
- Export **overwrites** the customer's directory on each run and also writes a zip bundle of every
  CSV at `var/export/specialcustomer/<id>.zip` for easy transfer. The zip needs PHP's `zip`
  extension; without it the CSVs are still written and a warning is printed instead.
- Import **remaps ids on collision**: if a primary key is already taken on the target it inserts a
  new row with a fresh id and rewrites all foreign keys so the parent/child graph stays linked.
  Move exported files into `var/import/specialcustomer/<id>/` to import.
- Import is **one transaction**: a failure on any table rolls the whole run back, so a target DB is
  never left with half a footprint.
- A mapped table whose CSV is **missing** from the import directory is skipped and listed at the end
  (`Skipped (no CSV present): …`); a header-only CSV imports zero rows. Both are harmless, so a
  hand-trimmed import directory works.
- A mapped table that **does not exist on the database** (a store-specific table such as
  `contrat_aladin_clients` on another install) is skipped and listed, on export and on import.
- Exporting a customer that **does not exist** (no table yields a row) fails with an error and
  leaves no export directory behind.
- SQL `NULL` is written to CSV as `\N` (MySQL's convention) so that `NULL` and the empty string
  survive the round trip as distinct values. A plain empty cell stays an empty string for a text
  column; for a typed column (int, decimal, datetime…) it is imported as `NULL`, so files written
  before this convention remain importable.
- The CSV dialect is plain RFC 4180 (comma, double quotes doubled, no escape character), written
  and read by the module itself. Magento's own CSV helper is deliberately not used: it prefixes any
  value starting with `=`, `+` or `-` with a space, which would corrupt phone numbers such as
  `+33 6…` on the round trip.
- Rows are imported **verbatim**: `customer_entity` keeps its `password_hash` / `rp_token`, orders
  keep their `protect_code`, and so on. Attach a [processor](#custom-processors) to scrub anything
  that must not land on the target.

### Import modes (`--mode`)

- **`restore`** (default) — match & reuse existing rows: a customer already present (by
  `email`+`website_id`), a product (by `sku`), an order (by `increment_id`+`store_id`) is reused, not
  duplicated. Importing a footprint back into a DB that already has it therefore creates nothing.
- **`clone`** — **force-create a brand-new customer** and their whole footprint (addresses, quotes,
  orders + children) as fresh rows, leaving the existing customer untouched. This is the mode for
  pulling prod data into a local dev env to reproduce a case. Shared catalog data is still reused:
  products (`sku`) and customer group (`code`) are matched, not cloned.
  - **The customer always gets a fresh `entity_id`** (and each order a fresh `entity_id`): the
    exported primary key is dropped and the DB allocates a new auto-increment id, even on an empty
    dev DB where the source id was free. All foreign keys (`customer_id`, `parent_id`, `order_id`,
    quote/EAV/grid links) are remapped onto the new ids, so the graph stays linked.
  - **Business keys are kept when free, regenerated only on collision.** The customer's real prod
    `email` and each order's `increment_id` are imported as-is when the target doesn't already have
    them; only if a value would violate the unique index is it rewritten (`email` →
    `local+clone-<token>@domain`, `increment_id` → `<value>-<token>`), where `<token>` is a short
    per-run id. So the first clone into a fresh dev DB keeps the real email; a second clone (the
    email now exists) gets a suffixed one.
  - **The resulting `customer_id` + `email` are printed at the end** so you can find the copy. Clone
    mode is **non-idempotent** — each run produces another distinct customer.

  Which tables are cloned vs reused is config-driven: `clone_new` (skip the reuse match, always
  insert) is set on `customer_entity` and `sales_order`; `clone_regenerate` (column → strategy) makes
  their unique keys unique; `clone_mirror` (column → strategy) makes every denormalised copy of the
  value (`customer_grid_flat.email`, `quote.customer_email`, `sales_order.customer_email`,
  `sales_order_grid.increment_id`/`customer_email`, `quote_address.email`, `sales_order_address.email`)
  follow whatever the deciding table kept or regenerated. See those fields in the map table below.

## The Customer Data Map

The default map lives in `Model/Config/CustomerMap.php`. Each entry describes one table:

| Field                | Required            | Meaning |
|----------------------|---------------------|---------|
| `table`              | yes                 | Table name (no DB prefix). |
| `primary_key`        | no (`entity_id`)    | Primary key column. |
| `relationship_type`  | yes                 | `direct`, `child` or `lookup`. |
| `customer_column`    | for `direct`        | Column equal to the customer id (`entity_id` on `customer_entity`, `customer_id` on `quote`/`sales_order`). |
| `parent`             | for `child`         | Parent table name. |
| `parent_key`         | for `child`         | Column in the parent whose values this table references (usually the parent PK). |
| `foreign_key`        | for `child`         | Column in this table pointing at `parent.parent_key`. |
| `lookup_sources`     | for `lookup`        | `[{table, column}, …]` — gather this table's ids from those columns of already-exported tables. |
| `export_priority`    | no (`0`)            | Ascending order; parents must precede children. |
| `import_priority`    | no (= export)       | Ascending order; parents must precede children (FK safety). |
| `references`         | no                  | FK columns to remap on import: `[{column, table}, …]` where `table` is another mapped table. The child→parent link is auto-derived; declare only cross-links. A reference to a table that imports **later** (e.g. `customer_entity.default_billing` → `customer_address_entity`) is rewritten by `UPDATE` at the end of the run. |
| `unique_key`         | no                  | Natural key columns (e.g. `[email, website_id]`, `[sku]`). A matching target row is reused instead of inserted. |
| `skip_if_parent_reused` | no               | Child rows are skipped on import when their parent matched an existing target row (product sub-data, customer grid). |
| `clone_new`          | no                  | `--mode=clone` only: skip the `unique_key` reuse match and always insert a fresh row (force-create). Set on `customer_entity`, `sales_order`. |
| `clone_regenerate`   | no                  | `--mode=clone` only, on a `clone_new` table: `column => strategy` map rewriting unique business keys before insert (only on collision). Strategies: `email_suffix`, `suffix`. |
| `clone_mirror`       | no                  | `--mode=clone` only: `column => strategy` map for columns holding a *copy* of a value regenerated elsewhere under that strategy (e.g. `quote.customer_email` mirrors `customer_entity.email`). |
| `filter`             | no                  | Extra WHERE conditions, `['column op ?' => value]`, bound safely. |
| `processor`          | no                  | FQCN implementing `EcomEU\DbTool\Api\TableProcessorInterface` for special handling. |

### How relationships are resolved (export)

- **direct** → `WHERE <customer_column> = :customer_id`
- **child**  → `WHERE <foreign_key> IN (<parent_key values collected while exporting the parent>)`
- **lookup** → `WHERE <primary_key> IN (<values collected from the lookup_sources columns>)`

Because tables are processed in priority order, a parent's key values are always collected before
its children are queried. A child (or lookup) that resolves to no ids yields a header-only CSV.

The merged map is validated at load time: no table may be mapped twice, every `parent`,
`references[].table` and `lookup_sources[].table` must name a mapped table, a child must have a higher
`export_priority` **and** `import_priority` than its parent, and a lookup table must export after all
of its sources. A bad entry (including one injected from `di.xml`) fails fast with a descriptive
error.

### Import: id remapping & foreign-key preservation

Tables import in `import_priority` order (parents first). The engine keeps an id map (old id → id on
the target) per table and rewrites the `references` of later tables from it, so links survive even
when ids change. Per row:

1. If `unique_key` is set and a target row matches → **reuse** its id (no insert).
2. Else if the primary key is free on the target → insert keeping the original id.
3. Else (collision) → insert with the id omitted and capture the new auto-increment id.

A row skipped because its parent was reused (`skip_if_parent_reused`) is dropped together with **its
own** children (cascade), so a skipped address never leaves orphan `customer_address_entity_*` rows.

References that point *back* at a table imported later (`customer_entity.default_billing` /
`default_shipping` → addresses, `sales_order.billing_address_id` / `shipping_address_id` → order
addresses) are remembered per inserted row and applied as `UPDATE`s after the last table, once the
target ids are known. That `UPDATE` re-asserts the exported `updated_at`, so MySQL's
`ON UPDATE CURRENT_TIMESTAMP` does not silently bump it. Rows reused from the target keep their own
links.

**Dangling links are nulled.** A cross-link whose target row is unknown to the run (not in the
export, e.g. the quote an order came from was purged; or skipped because its parent was reused, e.g.
the address book entry behind `quote_address.customer_address_id` when the customer already existed)
is looked up on the target: if that id exists there it is kept (on a same-DB restore it is the right
row), otherwise the column is set to `NULL` when nullable, instead of pointing at whatever row owns
that id on the other database. The run reports how many links were nulled. The structural links are
never touched: a child's link to its parent and a direct table's `customer_column` must resolve, or
the run fails on the foreign key.

**Products** (`catalog_product_entity`, a `lookup` from the item `product_id`s) export *after* the
items but import *before* them (priorities 70/71 vs 5/6), so `sales_order_item.product_id` /
`quote_item.product_id` are remapped to the target product matched by `sku`. Product sub-tables
(EAV/website/stock) are `skip_if_parent_reused`, so existing products aren't touched; only a
stub-inserted (missing-sku) product receives them.

> **Cross-DB product caveat:** matching an existing product by `sku` and remapping is reliable. For a
> *missing* sku the exported product rows are inserted best-effort, but product EAV `attribute_id`s
> can differ between databases, so a stub product may be imperfect. For full product parity across
> environments use `scripts/db-sync-gui/`.

> **Idempotency note:** because a taken id triggers a new-id insert, re-importing into the **same**
> DB duplicates entities that have no `unique_key` (quotes). The intended target is a
> *different* DB. Customers (`email`), their addresses (skipped when the customer is reused),
> orders (`increment_id`+`store_id`) and products (`sku`) are matched and never duplicated.

> **Secondary unique keys:** the engine remaps the primary key but inserts other columns intact, so a
> *secondary* unique index could otherwise collide. The known case, `sales_order`, is handled: it has
> `unique_key => ['increment_id', 'store_id']`, so an order already present on the target (same order
> number) is **matched and reused** instead of re-inserted, and its children
> (`sales_order_address`/`_item`/`_payment`/`_grid`) are `skip_if_parent_reused`. This makes order
> import idempotent and avoids the `SALES_ORDER_INCREMENT_ID_STORE_ID` (1062) error.
>
> Matching is by `(increment_id, store_id)`: if two *different* databases reuse the same order-number
> sequence for different orders, an incoming order could match an unrelated target order and be
> skipped — inherent to using the order number as identity; the intended target is a restore/copy or a
> DB that doesn't already hold this customer's orders. If a *new* table hits the same class of problem,
> give it a `unique_key` (to match/reuse) the same way. Note `quote` has no natural key, so re-importing
> into the source DB still duplicates carts (a limitation, not a crash).

> **Tables with no surrogate primary key:** some tables identify a row by a business column rather than
> an auto-increment id (e.g. `contrat_aladin_clients`, whose identity is the unique `int`
> `contrat_client`, with no PK/auto-increment at all). For these, set **both** `primary_key` and
> `unique_key` to that business key — `primary_key => 'contrat_client'`, `unique_key => ['contrat_client']`.
> `primary_key` must name a real column (the engine's match query selects it; the `entity_id` default
> would error), and `unique_key` makes an existing row matched & reused rather than re-inserted (which
> would hit its 1062). In `--mode=clone` such a row is matched (not cloned) — its unique index is on
> `contrat_client` alone, so it must **not** be `clone_new`; minting a new business id (and any
> dependent rows) is out of scope. Its `contract_code` / `contrat_client_type_id` reference
> `contrat_aladin`, which is outside the map and must already exist on the target.

## Tests

`Test/Integration/` holds PHPUnit tests that run the real engine against the database of the Magento
install the module is deployed in. Each test builds a complete customer footprint (customer, address,
product, cart with parent/child lines and an option, order with addresses, lines and payment) inside
a transaction that is rolled back afterwards, so nothing is left behind. Covered: the export writes
one CSV per mapped table with the right rows and the delicate values intact; import onto the same
account (reused, nothing duplicated), onto taken ids (fresh ids, every link rewritten), onto a free
account (ids kept), and clone mode. From the Magento root:

```bash
vendor/bin/phpunit -c app/code/EcomEU/DbTool/Test/Integration/phpunit.xml
```

## Extending the map from another module

Add tables without editing this module by injecting into `MapProvider` from your own `etc/di.xml`.
An entry with the same array key as a default **replaces** that default.

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

## Custom processors

For handling that can't be expressed declaratively (scrubbing password hashes, dropping generated
columns, remapping values), implement `EcomEU\DbTool\Api\TableProcessorInterface` and reference it by
FQCN in the entry's `processor` field. The engine calls `processExportRows()` / `processImportRows()`
around that table's rows.

## Notes & assumptions

- No schema changes: the module only reads/writes existing tables.
- **Customer & address EAV attribute data is captured.** `customer_entity` and
  `customer_address_entity` are EAV entities, so their custom attributes live in the value tables
  `customer_entity_{datetime,decimal,int,text,varchar}` and `customer_address_entity_{…}`. These are
  seeded as `child` entries (FK `entity_id`). Customer value tables and addresses are
  `skip_if_parent_reused` — when a customer is matched by email on the target, its attribute rows and
  addresses already exist there and are left untouched (address value rows follow by cascade); only a
  newly-inserted customer receives them.
- **Requires PHP 8.3+** (typed class constants). CI runs PHPCS and PHPStan on 8.4.
- **Customer group is synced.** `customer_group` is a `lookup` gathered from the group ids referenced
  by the customer/quotes/orders. On import it is **matched by `customer_group_code`** to reuse the
  target's group id (standard groups always match; a custom group inserts with a fresh id), and
  `customer_entity.group_id` / `quote`.`customer_group_id` / `sales_order`.`customer_group_id` /
  `catalog_product_entity_tier_price`.`customer_group_id` are remapped to it. It exports last (after
  the tier prices) but imports first — the split-priority pattern.
- **Product tier prices are synced.** `catalog_product_entity_tier_price` is a product `child`
  (`skip_if_parent_reused`), so existing products keep their tier prices and only a stub-inserted
  (missing-sku) product receives them; `customer_group_id` on each row is remapped to the matched
  group. `website_id` is **not** remapped — like `store_id`, `store_website` is a referenced global
  table that must already align on the target.
- **The product EAV value set is complete and store-scoped.** All six backends are captured —
  `catalog_product_entity_{datetime,decimal,int,text,varchar,gallery}`. These tables are keyed by
  `store_id`, and the export applies **no store filter** (it pulls every row for the product id), so
  **per-store attribute and price overrides** (e.g. a store-specific `special_price` in
  `catalog_product_entity_decimal`) all come across as separate rows automatically.
- **Configurable/bundle cart lines keep their selection.** A configurable/bundle cart line is a
  parent + child `quote_item` pair (linked by `parent_item_id`, a self-reference the engine remaps),
  and the chosen options live in `quote_item_option` (info_buyRequest / attributes / simple_product /
  bundle & custom-option values) — both are synced. (Order lines likewise keep their `parent_item_id`
  self-link and their `quote_item_id` link to the remapped cart line; their options stay inline on
  `sales_order_item.product_options`.) **Caveat:** those option payloads are *serialized* and embed
  product/attribute ids that are **not** rewritten. This is fine for same-DB restore/clone (products
  are matched by `sku` to the same rows, so the embedded ids stay valid); on a *cross-DB* import where
  a sku has a different `product_id`, an embedded selection can be stale.
- Import expects referenced rows in tables **outside** the map (store, website, tax class, …) to
  already exist in the target DB.

### Known limitations

- **Restore never updates an existing row.** A customer already on the target is matched and left
  as-is: new addresses, changed attributes or a new default address on the source are **not**
  applied, and their orders placed since are imported but linked to no address book entry. To refresh
  a customer that already exists locally, use `--mode=clone` (a complete fresh copy) or delete the
  local customer first.
- **Real order numbers are imported.** The target's `sequence_order_<store>` counter is not advanced,
  so an order placed later on the target can eventually reach an imported `increment_id` and fail on
  the unique index. Irrelevant for a throw-away dev DB; bump the sequence if the target keeps taking
  orders.
- **Memory is proportional to the largest table.** Each table is read and written as one array
  (roughly 1 KB per row). The product EAV tables dominate: a customer with 2 000 distinct products
  pulls ~700 000 rows, which exported in ~11 s under a 10 GB CLI memory limit; keep that in mind on a
  tight `memory_limit`.
- **Stub products have no MSI stock.** `inventory_source_item` (keyed by `sku`) is not mapped, so a
  product inserted because its `sku` was missing has no source item; existing products are unaffected.

### Not synced by default (opt-in — not EAV value data)

The following product tables are intentionally excluded; add them if a use case needs them:

- **Custom options + per-store option pricing** (`catalog_product_option` → `_price`/`_title`/
  `_type_value` → `_type_price`/`_type_title`). Multi-level children: declare each level as a `child`
  of the one above with `skip_if_parent_reused` on `catalog_product_option`; the engine's cascade then
  drops the grandchild rows too when the product was matched on the target.
- **`ecomeu_familyset_tier_price`** — this store's custom per-store quantity-break pricing. Keyed by
  the `familyset_code` product attribute value (not `product_id`), so it needs a dedicated
  lookup-by-attribute-value step.
- **Bundle selection pricing**, **media gallery** (references image files on disk), and product
  **composition/placement** (`catalog_product_link*`, `catalog_product_super_*`,
  `catalog_product_relation`, `catalog_category_product`) — the latter reference other products and
  categories, expanding a per-customer export toward a full-catalog export.
- **Order documents & extras** — `sales_invoice`/`sales_creditmemo`/`sales_shipment` (+ their
  `_item`/`_comment`/`_track`/`_grid`), `sales_order_status_history`, `sales_order_tax` (+ `_tax_item`),
  `sales_payment_transaction`, and `gift_message`. Each fits the existing patterns (`child` +
  `references`; documents get `unique_key => ['increment_id','store_id']`; grids
  `skip_if_parent_reused`). Also guest/multishipping/ephemeral: `quote_id_mask`, `quote_address_item`,
  `quote_shipping_rate`.

> **EAV `attribute_id` cross-DB caveat:** EAV value rows (customer, address, product) carry
> `attribute_id`. Stock attributes share ids across standard installs, but a **custom** attribute can
> have a different `attribute_id` in another database — importing its value rows there would misfile
> them under the wrong attribute. When migrating custom attributes across environments, verify the
> attribute ids line up (or use `scripts/db-sync-gui/`, which syncs the EAV attribute definitions).
