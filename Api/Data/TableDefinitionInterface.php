<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Api\Data;

/**
 * Immutable description of one table in the customer data map.
 *
 * This is the single unit the export/import engine interprets: it declares which table to
 * process, how that table is related to the customer, and where it sits in the processing order.
 */
interface TableDefinitionInterface
{
    /** Relationship types. */
    public const string RELATION_DIRECT = 'direct';
    public const string RELATION_CHILD = 'child';
    public const string RELATION_LOOKUP = 'lookup';

    /** Clone-mode regeneration strategies. */
    public const string STRATEGY_EMAIL_SUFFIX = 'email_suffix';
    public const string STRATEGY_SUFFIX = 'suffix';

    /**
     * Table name (without DB prefix; resolved through ResourceConnection::getTableName()).
     */
    public function getTable(): string;

    /**
     * Primary key column of this table (e.g. "entity_id").
     */
    public function getPrimaryKey(): string;

    /**
     * Relationship type: self::RELATION_DIRECT, self::RELATION_CHILD or self::RELATION_LOOKUP.
     */
    public function getRelationshipType(): string;

    /**
     * For a DIRECT relationship: the column that equals the customer id
     * (e.g. "entity_id" on customer_entity, "customer_id" on quote/sales_order). Null otherwise.
     */
    public function getCustomerColumn(): ?string;

    /**
     * For a CHILD relationship: the parent table name. Null otherwise.
     */
    public function getParent(): ?string;

    /**
     * For a CHILD relationship: the column in the parent whose values this table references
     * (usually the parent's primary key). Null otherwise.
     */
    public function getParentKey(): ?string;

    /**
     * For a CHILD relationship: the column in THIS table that points at parent.parentKey. Null otherwise.
     */
    public function getForeignKey(): ?string;

    /**
     * Ordering weight for export (parents low, children high).
     */
    public function getExportPriority(): int;

    /**
     * Ordering weight for import (parents must precede children to satisfy FK constraints).
     */
    public function getImportPriority(): int;

    /**
     * Optional extra WHERE conditions, each entry ['column op ?' => value] (bound safely).
     *
     * @return array<string,mixed>
     */
    public function getFilter(): array;

    /**
     * Optional FQCN of a EcomEU\DbTool\Api\TableProcessorInterface for special handling. Null if none.
     */
    public function getProcessor(): ?string;

    /**
     * For a LOOKUP relationship: where to gather this table's ids from, as a list of
     * ['table' => another mapped table, 'column' => its column]. Rows are those whose primary key
     * appears in the union of those collected column values. Empty for non-lookup tables.
     *
     * @return array<int,array{table:string,column:string}>
     */
    public function getLookupSources(): array;

    /**
     * Foreign-key for the columns that must be remapped on import, each ['column' => local column,
     * 'table' => referenced mapped table]. Includes the auto-derived child->parent reference plus
     * any explicit cross-links declared in the map. A reference to a table that imports LATER is
     * applied as an UPDATE at the end of the run (e.g. customer_entity.default_billing).
     *
     * @return array<int,array{column:string,table:string}>
     */
    public function getReferences(): array;

    /**
     * Natural/business key columns used on import
     *
     * To detect an equivalent row already present on
     * the target (e.g. ["email", "website_id"] or ["sku"]). Empty when the table has none.
     *
     * @return string[]
     */
    public function getUniqueKey(): array;

    /**
     * When true, this (child) table's rows are skipped on import if their parent row was matched to
     * an already-existing target row — used for sub-data (product EAV/stock/website, customer grid)
     * that the target already owns.
     */
    public function isSkipIfParentReused(): bool;

    /**
     * When true, clone-mode import skips the unique_key reuse lookup for this table and always
     * inserts a fresh row — used to force-create a new customer/order instead of matching an
     * existing one. Ignored in the default (restore) mode.
     */
    public function isCloneNew(): bool;

    /**
     * Columns whose values must be rewritten to unique values in clone mode (to avoid the table's
     * unique-index collisions), as a map of column => strategy. Supported strategies:
     * "email_suffix" (insert +clone-<token> into the local part) and "suffix" (append -<token>).
     * Empty when the table needs no regeneration.
     *
     * @return array<string,string>
     */
    public function getCloneRegenerate(): array;

    /**
     * Columns holding a COPY of a business-key value regenerated elsewhere in clone mode (e.g.
     * quote.customer_email mirrors customer_entity.email), as a map of column => strategy. The value
     * is rewritten to whatever the deciding table produced under that strategy (kept or
     * regenerated), so every copy stays consistent. Empty when the table mirrors nothing.
     *
     * @return array<string,string>
     */
    public function getCloneMirror(): array;
}
