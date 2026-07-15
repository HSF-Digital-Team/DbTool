# Tool_DbTool

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
```

- Each table becomes its own CSV (`customer_entity.csv`, `quote.csv`, `sales_order_item.csv`, …),
  human-readable and editable before import.
- Export **overwrites** the customer's directory on each run and also writes a zip bundle of every
  CSV at `var/export/specialcustomer/<id>.zip` for easy transfer.
- Import **remaps ids on collision**: if a primary key is already taken on the target it inserts a
  new row with a fresh id and rewrites all foreign keys so the parent/child graph stays linked.
  Move exported files into `var/import/specialcustomer/<id>/` to import.

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
| `references`         | no                  | FK columns to remap on import: `[{column, table}, …]` where `table` is another mapped table. The child→parent link is auto-derived; declare only cross-links. |
| `unique_key`         | no                  | Natural key columns (e.g. `[email, website_id]`, `[sku]`). A matching target row is reused instead of inserted. |
| `skip_if_parent_reused` | no               | Child rows are skipped on import when their parent matched an existing target row (product sub-data, customer grid). |
| `filter`             | no                  | Extra WHERE conditions, `['column op ?' => value]`, bound safely. |
| `processor`          | no                  | FQCN implementing `Tool\DbTool\Api\TableProcessorInterface` for special handling. |

### How relationships are resolved (export)

- **direct** → `WHERE <customer_column> = :customer_id`
- **child**  → `WHERE <foreign_key> IN (<parent_key values collected while exporting the parent>)`
- **lookup** → `WHERE <primary_key> IN (<values collected from the lookup_sources columns>)`

Because tables are processed in priority order, a parent's key values are always collected before
its children are queried. A child (or lookup) that resolves to no ids yields a header-only CSV.

### Import: id remapping & foreign-key preservation

Tables import in `import_priority` order (parents first). The engine keeps an id map (old id → id on
the target) per table and rewrites the `references` of later tables from it, so links survive even
when ids change. Per row:

1. If `unique_key` is set and a target row matches → **reuse** its id (no insert).
2. Else if the primary key is free on the target → insert keeping the original id.
3. Else (collision) → insert with the id omitted and capture the new auto-increment id.

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
> DB duplicates entities that have no `unique_key` (orders, quotes). The intended target is a
> *different* DB. Customers (`email`) and products (`sku`) are matched and never duplicated.

> **Secondary unique keys:** the engine only remaps the primary key. A row is inserted with its
> other columns intact, so a *secondary* unique index can still collide — e.g. `sales_order`
> preserves `increment_id`, which is unique per store, so importing an order whose `increment_id`
> already exists on the target aborts that table. This is expected only when the target already
> holds a row with that business key (e.g. re-importing into the source DB, or overlapping order
> sequences); a genuine restore target does not. Edit the CSV or add a `processor` to rewrite such a
> column if you must import into a colliding target.

## Extending the map from another module

Add tables without editing this module by injecting into `MapProvider` from your own `etc/di.xml`.
An entry with the same array key as a default **replaces** that default.

```xml
<type name="Tool\DbTool\Model\MapProvider">
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
columns, remapping values), implement `Tool\DbTool\Api\TableProcessorInterface` and reference it by
FQCN in the entry's `processor` field. The engine calls `processExportRows()` / `processImportRows()`
around that table's rows.

## Notes & assumptions

- No schema changes: the module only reads/writes existing tables.
- **Customer & address EAV attribute data is captured.** `customer_entity` and
  `customer_address_entity` are EAV entities, so their custom attributes live in the value tables
  `customer_entity_{datetime,decimal,int,text,varchar}` and `customer_address_entity_{…}`. These are
  seeded as `child` entries (FK `entity_id`). Customer value tables are `skip_if_parent_reused` — when
  a customer is matched by email on the target, its attribute rows already exist there and are left
  untouched; only a newly-inserted customer receives them.
- **Customer group is synced.** `customer_group` is a `lookup` gathered from the group ids referenced
  by the customer/quotes/orders. On import it is **matched by `customer_group_code`** to reuse the
  target's group id (standard groups always match; a custom group inserts with a fresh id), and
  `customer_entity.group_id` / `quote`.`customer_group_id` / `sales_order`.`customer_group_id` are
  remapped to it. It exports late (after orders) but imports first — the split-priority pattern.
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
- Import expects referenced rows in tables **outside** the map (store, website, tax class, …) to
  already exist in the target DB.

### Not synced by default (opt-in — not EAV value data)

The following product tables are intentionally excluded; add them if a use case needs them:

- **Custom options + per-store option pricing** (`catalog_product_option` → `_price`/`_title`/
  `_type_value` → `_type_price`/`_type_title`). Multi-level children; needs a "cascade skip" so
  grandchild rows skip when the product was matched on the target.
- **`ecomeu_familyset_tier_price`** — this store's custom per-store quantity-break pricing. Keyed by
  the `familyset_code` product attribute value (not `product_id`), so it needs a dedicated
  lookup-by-attribute-value step.
- **Bundle selection pricing**, **media gallery** (references image files on disk), and product
  **composition/placement** (`catalog_product_link*`, `catalog_product_super_*`,
  `catalog_product_relation`, `catalog_category_product`) — the latter reference other products and
  categories, expanding a per-customer export toward a full-catalog export.

> **EAV `attribute_id` cross-DB caveat:** EAV value rows (customer, address, product) carry
> `attribute_id`. Stock attributes share ids across standard installs, but a **custom** attribute can
> have a different `attribute_id` in another database — importing its value rows there would misfile
> them under the wrong attribute. When migrating custom attributes across environments, verify the
> attribute ids line up (or use `scripts/db-sync-gui/`, which syncs the EAV attribute definitions).
