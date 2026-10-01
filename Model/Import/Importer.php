<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Import;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;
use EcomEU\DbTool\Api\MapProviderInterface;
use EcomEU\DbTool\Model\Processor\ProcessorProvider;
use EcomEU\DbTool\Model\Relation\RelationResolver;
use EcomEU\DbTool\Model\Storage\CsvStorage;
use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Framework\Phrase;
use Throwable;

/**
 * Import engine with collision-safe id remapping.
 *
 * Tables are processed in import-priority order (parents before children). For every table an
 * id map (old primary key -> id on the target) is built and used to rewrite the foreign keys of
 * later tables, so the exported graph stays linked even when ids change:
 *
 *  - unique_key match  -> reuse the existing target row's id (no insert);
 *  - primary key free  -> insert keeping the original id;
 *  - primary key taken -> insert with the id omitted and capture the new auto-increment id.
 *
 * Children flagged skip_if_parent_reused are dropped when their parent was matched to a row the
 * target already owns (product sub-data, customer grid). A dropped row's own children are dropped
 * as well (cascade), since they could no longer be linked to anything.
 *
 * A reference to a table that imports LATER (e.g. customer_entity.default_billing ->
 * customer_address_entity) cannot be rewritten at insert time; it is recorded and applied as an
 * UPDATE once every table has been written and the referenced ids are known.
 *
 * A cross-link whose target row is unknown to the run (not exported, or skipped because its parent
 * was reused) and does not exist on the target either is a dangling pointer: it is set to NULL when
 * the column allows it, otherwise the row is dropped (with its own children, by cascade), instead
 * of being left pointing at whatever row owns that id on the target or failing on the foreign key.
 * The same applies to "requires" links into unmapped tables (a category, say). The parent link of a
 * child table and the customer link of a direct table are never touched: a missing parent must fail
 * the run, not silently detach the rows.
 *
 * A mapped table that does not exist on the target database is skipped and reported. A CSV column
 * that does not exist on the target table is ignored and reported (an export from a newer schema).
 * The map's structural columns (primary key, foreign key, customer column, unique key) must exist
 * on the target table, otherwise the run fails before writing anything.
 *
 * The whole run is one transaction: a failure on any table rolls back everything. A dry run goes
 * through every step and rolls back at the end instead of committing.
 */
class Importer
{
    /** Column auto-bumped by MySQL on UPDATE; preserved when back-references are rewritten. */
    private const string UPDATED_AT = 'updated_at';

    /**
     * @param ResourceConnection $resourceConnection
     * @param MapProviderInterface $mapProvider
     * @param ProcessorProvider $processorProvider
     * @param CsvStorage $storage
     * @param Random $random
     */
    public function __construct(
        private readonly ResourceConnection   $resourceConnection,
        private readonly MapProviderInterface $mapProvider,
        private readonly ProcessorProvider    $processorProvider,
        private readonly CsvStorage           $storage,
        private readonly Random               $random
    ) {
    }

    /**
     * Import a customer's previously exported footprint.
     *
     * @param int $customerId
     * @param callable|null $progress fn(string $table, int $written, string $status): void
     * @param bool $cloneMode when true, force-create a new customer (+footprint) instead of matching
     *                        and reusing an existing one; unique business keys are regenerated
     * @param bool $dryRun when true, every step runs but the transaction is rolled back at the end
     * @return array{directory: string, tables: array<string,int>, skipped: string[], absent: string[],
     *               ignored_columns: array<string,string[]>, remapped: int, unlinked: int, dropped: int,
     *               deferred_updates: int, clone_token: ?string, dry_run: bool,
     *               new_customer_ids: array<int,mixed>, new_customers: array<int,array{id:mixed,email:mixed}>}
     * @throws LocalizedException|Throwable
     */
    public function import(
        int $customerId,
        ?callable $progress = null,
        bool $cloneMode = false,
        bool $dryRun = false
    ): array {
        $absoluteDir = $this->storage->getImportDir($customerId);
        if (!$this->storage->dirExists($absoluteDir)) {
            throw new LocalizedException(new Phrase(
                'EcomEU_DbTool: import directory not found: %1',
                [$absoluteDir]
            ));
        }

        // Resolve and validate the map before opening the transaction.
        $definitions = $this->mapProvider->getImportOrder();

        // One token per run so every regenerated value (order + its grid, customer + its grid) is
        // consistent, while re-running clone produces a fresh, distinct copy.
        $cloneToken = $cloneMode ? strtolower($this->random->getRandomString(6)) : '';

        $connection = $this->resourceConnection->getConnection();
        $results = [];
        $skipped = [];
        $absent = [];
        $ignoredColumns = [];
        $remappedTotal = 0;
        $unlinkedTotal = 0;
        $droppedTotal = 0;

        // table => position in the import order; a reference to a table with a higher position is
        // deferred until the end of the run. table => primary key serves the dangling-pointer checks.
        $order = [];
        $pkByTable = [];
        foreach ($definitions as $position => $definition) {
            $order[$definition->getTable()] = $position;
            $pkByTable[$definition->getTable()] = $definition->getPrimaryKey();
        }

        // Shared across tables for the whole import.
        $idMap = [];     // table => [ (string)oldId => newId ]
        $reused = [];    // table => [ (string)targetId => true ] rows matched to existing target rows
        $dropped = [];   // table => [ (string)oldId => true ] rows not imported (parent reused/dropped)
        $deferred = [];  // list of {table, pk, target_pk, column, ref_table, old_value}
        // Clone-mode business-key rewrites decided on the force-new parent (customer_entity /
        // sales_order), keyed by strategy, and mirrored by every table carrying a copy of the value.
        $regen = [];     // strategy => [ (string)oldValue => newValue ]

        $connection->beginTransaction();
        try {
            foreach ($definitions as $definition) {
                $table = $definition->getTable();
                if (!$connection->isTableExists($this->resourceConnection->getTableName($table))) {
                    $absent[] = $table;
                    if ($progress !== null) {
                        $progress($table, 0, 'absent');
                    }
                    continue;
                }
                $rows = $this->storage->readTable($absoluteDir, $table);

                if ($rows === null) {
                    $skipped[] = $table;
                    if ($progress !== null) {
                        $progress($table, 0, 'missing');
                    }
                    continue;
                }
                if ($rows === []) {
                    $results[$table] = 0;
                    if ($progress !== null) {
                        $progress($table, 0, 'empty');
                    }
                    continue;
                }

                $processor = $this->processorProvider->get($definition->getProcessor());
                if ($processor !== null) {
                    $rows = array_values($processor->processImportRows($table, $rows));
                }

                $stats = $this->writeTable(
                    $connection,
                    $definition,
                    $rows,
                    $order,
                    $idMap,
                    $reused,
                    $dropped,
                    $deferred,
                    $regen,
                    $pkByTable,
                    $cloneMode,
                    $cloneToken
                );
                $results[$table] = $stats['written'];
                $remappedTotal += $stats['remapped'];
                $unlinkedTotal += $stats['unlinked'];
                $droppedTotal += $stats['dropped'];
                if ($stats['ignored_columns'] !== []) {
                    $ignoredColumns[$table] = $stats['ignored_columns'];
                }

                if ($progress !== null) {
                    $progress($table, $stats['written'], $this->statusLabel($stats));
                }
            }

            $deferredStats = $this->applyDeferredReferences($connection, $deferred, $idMap, $pkByTable);
            $unlinkedTotal += $deferredStats['unlinked'];

            // Resolved inside the transaction: in a dry run the rows are gone after the rollback.
            $newCustomerIds = array_values($idMap['customer_entity'] ?? []);
            $newCustomers = $this->resolveCustomers($connection, $newCustomerIds);

            if ($dryRun) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return [
            'directory' => $absoluteDir,
            'tables' => $results,
            'skipped' => $skipped,
            'absent' => $absent,
            'ignored_columns' => $ignoredColumns,
            'remapped' => $remappedTotal,
            'unlinked' => $unlinkedTotal,
            'dropped' => $droppedTotal,
            'deferred_updates' => $deferredStats['updates'],
            'clone_token' => $cloneMode ? $cloneToken : null,
            'dry_run' => $dryRun,
            'new_customer_ids' => $newCustomerIds,
            'new_customers' => $newCustomers,
        ];
    }

    /**
     * Resolve the target customer id + email
     *
     * For the ids the import mapped customers onto, so the
     * caller can tell you exactly which customer to look at (a fresh one in clone mode).
     *
     * @param AdapterInterface $connection
     * @param array<int,mixed> $customerIds target customer_entity ids
     * @return array<int,array{id:mixed,email:mixed}>
     */
    private function resolveCustomers(AdapterInterface $connection, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('customer_entity'), ['entity_id', 'email'])
            ->where('entity_id IN (?)', $customerIds);

        $customers = [];
        foreach ($connection->fetchAll($select) as $row) {
            $customers[] = ['id' => $row['entity_id'], 'email' => $row['email']];
        }
        return $customers;
    }

    /**
     * Import one table's rows, remapping foreign keys and resolving id collisions.
     *
     * @param AdapterInterface $connection
     * @param TableDefinitionInterface $definition
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,int> $order table => position in the import order
     * @param array<string,array<string,mixed>> $idMap by-ref, accumulated across tables
     * @param array<string,array<string,true>> $reused by-ref, accumulated across tables
     * @param array<string,array<string,true>> $dropped by-ref, rows not imported, accumulated across tables
     * @param array<int,array<string,mixed>> $deferred by-ref, reference rewrites applied after all tables
     * @param array<string,array<string,mixed>> $regen by-ref, clone-mode strategy => oldValue => newValue
     * @param array<string,string> $pkByTable table => primary key column
     * @param bool $cloneMode force-new mode
     * @param string $cloneToken per-run token used to regenerate unique business keys
     * @return array{written:int,remapped:int,matched:int,skipped:int,unlinked:int,dropped:int,cloned:bool,
     *               ignored_columns:string[]}
     * @throws LocalizedException
     */
    private function writeTable(
        AdapterInterface $connection,
        TableDefinitionInterface $definition,
        array $rows,
        array $order,
        array &$idMap,
        array &$reused,
        array &$dropped,
        array &$deferred,
        array &$regen,
        array $pkByTable,
        bool $cloneMode = false,
        string $cloneToken = ''
    ): array {
        $table = $definition->getTable();
        $tableName = $this->resourceConnection->getTableName($table);
        $describe = $connection->describeTable($tableName);
        $columns = array_keys($describe);
        $pk = $definition->getPrimaryKey();
        $uniqueKey = $definition->getUniqueKey();
        $parentTable = $definition->getParent();
        $foreignKey = $definition->getForeignKey();
        $customerColumn = $definition->getCustomerColumn();
        $requires = $definition->getRequires();

        $this->assertStructuralColumns($describe, $definition);

        $pkIsIdentity = !empty($describe[$pk]['IDENTITY']);
        // MySQL's ON UPDATE CURRENT_TIMESTAMP would silently bump updated_at when the deferred
        // back-references are rewritten; the exported value is re-asserted in that UPDATE.
        $hasUpdatedAt = isset($describe[self::UPDATED_AT]);

        // CSV columns unknown to the target table (export from a newer schema, hand-added column):
        // dropped, but reported so a typo in a header does not go unnoticed.
        $ignoredColumns = array_values(array_diff(array_map('strval', array_keys($rows[0] ?? [])), $columns));

        // Split the references three ways:
        //  - immediate: the referenced table imported earlier -> remapped in the prep phase;
        //  - self: a FK back into this same table (configurable/bundle parent_item_id) -> remapped
        //    in the insert loop once the parent row's new id is known;
        //  - deferred: the referenced table imports later (default_billing -> address) -> the old
        //    value is remembered and rewritten by UPDATE at the end of the run.
        $references = [];
        $selfReferences = [];
        $deferredReferences = [];
        $position = $order[$table] ?? PHP_INT_MAX;
        foreach ($definition->getReferences() as $reference) {
            if ($reference['table'] === $table) {
                $selfReferences[] = $reference['column'];
            } elseif (($order[$reference['table']] ?? -1) > $position) {
                $deferredReferences[] = $reference;
            } else {
                $references[] = $reference;
            }
        }

        // Clone mode: force this entity to be created new (skip the reuse lookup) and regenerate its
        // unique business-key columns so the inserts don't collide.
        $cloneNew = $cloneMode && $definition->isCloneNew();
        $cloneRegenerate = $cloneMode ? $definition->getCloneRegenerate() : [];
        $cloneMirror = $cloneMode ? $definition->getCloneMirror() : [];

        $idMap[$table] = $idMap[$table] ?? [];
        $written = $matched = $remapped = $skippedRows = $unlinked = $droppedRows = 0;
        // Links whose target row is unknown to this run: table => ['pk' => key column,
        // 'ids' => [(string)id => id]], checked against the target in one query per table.
        $pendingLookup = [];

        // Prepare rows: keep real columns, normalise empties, remap references. Preserve the
        // original primary key (before any reference remap can touch it) for the id map key.
        $prepared = [];
        foreach ($rows as $row) {
            $clean = [];
            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                $value = $row[$column];
                // NULL travels as the CSV NULL marker, so an empty cell is a real empty string for
                // a text column. For a typed (int/decimal/datetime…) column '' is never valid: it
                // is read as NULL, which also keeps files written before the marker importable.
                if ($value === ''
                    && !empty($describe[$column]['NULLABLE'])
                    && !$this->isTextColumn($describe[$column])
                ) {
                    $value = null;
                }
                $clean[$column] = $value;
            }
            if ($clean === []) {
                continue;
            }
            $originalPk = $clean[$pk] ?? null;
            // The parent's ORIGINAL id, read before remapping, keys the dropped-parent lookup.
            $rawParentId = ($parentTable !== null && $foreignKey !== null) ? ($clean[$foreignKey] ?? null) : null;

            $unresolved = [];
            foreach ($references as $reference) {
                $col = $reference['column'];
                if (!array_key_exists($col, $clean) || $clean[$col] === null || (string)$clean[$col] === '0') {
                    continue;
                }
                $key = (string)$clean[$col];
                if (isset($idMap[$reference['table']][$key])) {
                    $clean[$col] = $idMap[$reference['table']][$key];
                    continue;
                }
                // The structural links are never touched: a missing parent must fail the run.
                $structural = ($col === $foreignKey && $reference['table'] === $parentTable)
                    || $col === $customerColumn;
                if (!$structural) {
                    $unresolved[] = [
                        'column' => $col,
                        'ref_table' => $reference['table'],
                        'key' => $key,
                        'nullable' => !empty($describe[$col]['NULLABLE']),
                    ];
                    $pendingLookup[$reference['table']]['pk'] = $pkByTable[$reference['table']];
                    $pendingLookup[$reference['table']]['ids'][$key] = $clean[$col];
                }
            }
            // Links into unmapped tables are checked the same way (never remapped).
            foreach ($requires as $requirement) {
                $col = $requirement['column'];
                if (!array_key_exists($col, $clean) || $clean[$col] === null || (string)$clean[$col] === '0') {
                    continue;
                }
                $key = (string)$clean[$col];
                $unresolved[] = [
                    'column' => $col,
                    'ref_table' => $requirement['table'],
                    'key' => $key,
                    'nullable' => !empty($describe[$col]['NULLABLE']),
                ];
                $pendingLookup[$requirement['table']]['pk'] = $pkByTable[$requirement['table']] ?? $requirement['key'];
                $pendingLookup[$requirement['table']]['ids'][$key] = $clean[$col];
            }

            $deferredValues = [];
            foreach ($deferredReferences as $reference) {
                $col = $reference['column'];
                if (array_key_exists($col, $clean) && $clean[$col] !== null && $clean[$col] !== ''
                    && (string)$clean[$col] !== '0'
                ) {
                    $deferredValues[] = [
                        'column' => $col,
                        'ref_table' => $reference['table'],
                        'old_value' => $clean[$col],
                        'nullable' => !empty($describe[$col]['NULLABLE']),
                    ];
                }
            }

            // Clone mode: mirror the business-key values decided by a force-new entity imported
            // earlier (kept or regenerated), so every copy of the value stays consistent — the
            // denormalised copies such as customer_grid_flat.email / quote.customer_email /
            // sales_order_grid.increment_id (clone_mirror). A clone_regenerate on a non-clone_new
            // table behaves the same way. The deciding parent (clone_new) is handled at insert time
            // below, not here.
            $mirror = $cloneNew ? $cloneMirror : $cloneMirror + $cloneRegenerate;
            foreach ($mirror as $col => $strategy) {
                if (array_key_exists($col, $clean) && $clean[$col] !== null && $clean[$col] !== '') {
                    $mapped = $regen[$strategy][(string)$clean[$col]] ?? null;
                    if ($mapped !== null) {
                        $clean[$col] = $mapped;
                    }
                }
            }

            if ($parentTable !== null && $foreignKey !== null && $rawParentId !== null) {
                // Cascade: the parent row itself was not imported, so this row cannot be linked.
                $parentDropped = isset($dropped[$parentTable][(string)$rawParentId]);
                // Skip sub-data whose parent already exists on the target.
                $parentReused = $definition->isSkipIfParentReused()
                    && isset($clean[$foreignKey])
                    && isset($reused[$parentTable][(string)$clean[$foreignKey]]);
                if ($parentDropped || $parentReused) {
                    $skippedRows++;
                    if ($originalPk !== null) {
                        $dropped[$table][(string)$originalPk] = true;
                    }
                    continue;
                }
            }

            $prepared[] = [
                'original_pk' => $originalPk,
                'row' => $clean,
                'deferred' => $deferredValues,
                'unresolved' => $unresolved,
            ];
        }

        // Dangling links (ids absent from the target): null the column when allowed, else drop the
        // row (its children follow by cascade). An id that does exist there is kept: on a same-DB
        // restore it is the right row.
        $dangling = $this->findDangling($connection, $pendingLookup);
        $kept = [];
        foreach ($prepared as $entry) {
            $drop = false;
            foreach ($entry['unresolved'] as $ref) {
                if (!isset($dangling[$ref['ref_table']][$ref['key']])) {
                    continue;
                }
                if ($ref['nullable']) {
                    $entry['row'][$ref['column']] = null;
                    $unlinked++;
                } else {
                    $drop = true;
                    break;
                }
            }
            if ($drop) {
                $droppedRows++;
                if ($entry['original_pk'] !== null) {
                    $dropped[$table][(string)$entry['original_pk']] = true;
                }
                continue;
            }
            $kept[] = $entry;
        }
        $prepared = $kept;

        if ($prepared === []) {
            return [
                'written' => 0,
                'remapped' => 0,
                'matched' => 0,
                'skipped' => $skippedRows,
                'unlinked' => $unlinked,
                'dropped' => $droppedRows,
                'cloned' => $cloneNew,
                'ignored_columns' => $ignoredColumns,
            ];
        }

        // For self-referential tables, insert parents (no self-FK set) before children so the
        // child's self-FK can be remapped to the parent's freshly assigned id. Magento's
        // configurable/bundle nesting is one level, so this ordering suffices. usort is stable (PHP 8).
        if ($selfReferences !== []) {
            usort($prepared, function (array $a, array $b) use ($selfReferences): int {
                return $this->hasSelfParent($a['row'], $selfReferences)
                    <=> $this->hasSelfParent($b['row'], $selfReferences);
            });
        }

        // Preload target primary keys that are already taken among the incoming (remapped) ids,
        // so we only allocate a fresh id on a real collision.
        $takenPks = $pkIsIdentity ? $this->loadTakenPks($connection, $tableName, $pk, $prepared) : [];

        foreach ($prepared as $entry) {
            $row = $entry['row'];
            $originalPk = $entry['original_pk'];

            // 0) Remap self-references to the parent row inserted earlier in this same loop.
            foreach ($selfReferences as $selfColumn) {
                if (isset($row[$selfColumn])) {
                    $selfKey = (string)$row[$selfColumn];
                    if (isset($idMap[$table][$selfKey])) {
                        $row[$selfColumn] = $idMap[$table][$selfKey];
                    }
                }
            }

            // 1) Reuse an equivalent existing row when a natural key is defined — unless clone
            //    mode forces a brand-new row for this entity.
            if ($uniqueKey !== [] && !$cloneNew) {
                $existingPk = $this->findByUniqueKey($connection, $tableName, $pk, $uniqueKey, $row);
                if ($existingPk !== null) {
                    if ($originalPk !== null) {
                        $idMap[$table][(string)$originalPk] = $existingPk;
                    }
                    $reused[$table][(string)$existingPk] = true;
                    $matched++;
                    continue;
                }
            }

            // 1b) Force-new entity (clone mode): keep the exported business key (real prod email /
            //     order number) when it is still free on the target, and regenerate it only when
            //     it would collide. Record the decision so every copy of the value mirrors it.
            if ($cloneNew && $cloneRegenerate !== []) {
                $collides = $uniqueKey !== []
                    && $this->findByUniqueKey($connection, $tableName, $pk, $uniqueKey, $row) !== null;
                foreach ($cloneRegenerate as $col => $strategy) {
                    if (!array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '') {
                        continue;
                    }
                    $old = (string)$row[$col];
                    if ($collides) {
                        $row[$col] = $this->regenerate($strategy, $old, $cloneToken);
                    }
                    $regen[$strategy][$old] = $row[$col];
                }
            }

            // 2) Insert. A clone-mode force-new identity row always takes a fresh auto-increment
            //    id (so the imported customer/order is a brand-new local record and its children
            //    remap onto the new id); otherwise keep the id when free, allocate on collision.
            //    Only an auto-increment key can be allocated by the database: for any other key
            //    the exported value is the id, and a row without one simply maps nothing.
            $pkValue = $row[$pk] ?? null;
            $forceNewId = $cloneNew && $pkIsIdentity;
            $collision = $pkIsIdentity && $pkValue !== null && isset($takenPks[(string)$pkValue]);
            if ($forceNewId || $collision) {
                unset($row[$pk]);
                $this->insertRow($connection, $tableName, $row, $pk);
                $targetPk = $this->lastInsertId($connection);
                $remapped++;
            } else {
                $this->insertRow($connection, $tableName, $row, $pk);
                $targetPk = $pkValue ?? ($pkIsIdentity ? $this->lastInsertId($connection) : null);
            }
            $written++;

            if ($targetPk !== null) {
                $takenPks[(string)$targetPk] = true;
                if ($originalPk !== null) {
                    $idMap[$table][(string)$originalPk] = $targetPk;
                }
                foreach ($entry['deferred'] as $deferredValue) {
                    $deferred[] = $deferredValue + [
                        'table' => $tableName,
                        'pk' => $pk,
                        'target_pk' => $targetPk,
                        'updated_at' => $hasUpdatedAt ? ($row[self::UPDATED_AT] ?? null) : null,
                    ];
                }
            }
        }

        return [
            'written' => $written,
            'remapped' => $remapped,
            'matched' => $matched,
            'skipped' => $skippedRows,
            'unlinked' => $unlinked,
            'dropped' => $droppedRows,
            'cloned' => $cloneNew,
            'ignored_columns' => $ignoredColumns,
        ];
    }

    /**
     * Fail fast when a structural column of the map is missing on the target table.
     *
     * The table and column are named; the engine would otherwise import rows it cannot link,
     * match or remap.
     *
     * @param array<string,array<string,mixed>> $describe
     * @param TableDefinitionInterface $definition
     * @return void
     * @throws LocalizedException
     */
    private function assertStructuralColumns(array $describe, TableDefinitionInterface $definition): void
    {
        $required = [
            'primary_key' => [$definition->getPrimaryKey()],
            'foreign_key' => array_filter([$definition->getForeignKey()]),
            'customer_column' => array_filter([$definition->getCustomerColumn()]),
            'unique_key' => $definition->getUniqueKey(),
        ];
        foreach ($required as $field => $columns) {
            foreach ($columns as $column) {
                if (!isset($describe[$column])) {
                    throw new LocalizedException(new Phrase(
                        'EcomEU_DbTool: table "%1" has no column "%2" on this database (its "%3" in the map).',
                        [$definition->getTable(), $column, $field]
                    ));
                }
            }
        }
    }

    /**
     * Insert one row, turning a database failure into an error that names the table and row.
     *
     * The transaction is rolled back by the caller; this only makes the cause findable (a secondary
     * unique index, a foreign key to a table outside the map, a column too short for a regenerated
     * value) without digging through the SQL.
     *
     * @param AdapterInterface $connection
     * @param string $tableName
     * @param array<string,mixed> $row
     * @param string $pk
     * @return void
     * @throws LocalizedException
     */
    private function insertRow(AdapterInterface $connection, string $tableName, array $row, string $pk): void
    {
        try {
            $connection->insert($tableName, $row);
        } catch (Exception $e) {
            throw new LocalizedException(
                new Phrase(
                    'EcomEU_DbTool: cannot insert into %1 (%2 = %3): %4',
                    [$tableName, $pk, (string)($row[$pk] ?? 'auto'), $e->getMessage()]
                ),
                $e
            );
        }
    }

    /**
     * FindDangling
     *
     * Among the referenced ids unknown to this run, those that do not exist on the target either.
     *
     * @param AdapterInterface $connection
     * @param array<string,array{pk:string,ids:array<string,mixed>}> $pending per referenced table
     * @return array<string,array<string,true>> refTable => [ (string)id => true ]
     */
    private function findDangling(AdapterInterface $connection, array $pending): array
    {
        $dangling = [];
        foreach ($pending as $refTable => $lookup) {
            $existing = $this->existingValues(
                $connection,
                $this->resourceConnection->getTableName($refTable),
                $lookup['pk'],
                $lookup['ids']
            );
            foreach ($lookup['ids'] as $key => $id) {
                if (!isset($existing[(string)$key])) {
                    $dangling[$refTable][(string)$key] = true;
                }
            }
        }
        return $dangling;
    }

    /**
     * ApplyDeferredReferences
     *
     * Rewrite the references that point at tables imported later, now that every id is known.
     * Only rows this run inserted are touched (a reused target row keeps its own links). A pointer
     * whose target is unknown to the run and absent from the target is set to NULL (if allowed).
     *
     * @param AdapterInterface $connection
     * @param array<int,array<string,mixed>> $deferred
     * @param array<string,array<string,mixed>> $idMap
     * @param array<string,string> $pkByTable
     * @return array{updates:int,unlinked:int}
     */
    private function applyDeferredReferences(
        AdapterInterface $connection,
        array $deferred,
        array $idMap,
        array $pkByTable
    ): array {
        $updates = 0;
        $unlinked = 0;
        foreach ($deferred as $entry) {
            $old = (string)$entry['old_value'];
            $new = $idMap[$entry['ref_table']][$old] ?? null;
            if ($new === null) {
                $pending = [$entry['ref_table'] => [
                    'pk' => $pkByTable[$entry['ref_table']],
                    'ids' => [$old => $entry['old_value']],
                ]];
                if (!empty($entry['nullable'])
                    && isset($this->findDangling($connection, $pending)[$entry['ref_table']][$old])
                ) {
                    $new = null;
                    $unlinked++;
                } else {
                    continue;
                }
            } elseif ((string)$new === $old) {
                continue;
            }
            $bind = [$entry['column'] => $new];
            if (($entry['updated_at'] ?? null) !== null) {
                $bind[self::UPDATED_AT] = $entry['updated_at'];
            }
            $connection->update(
                $entry['table'],
                $bind,
                [$connection->quoteIdentifier($entry['pk']) . ' = ?' => $entry['target_pk']]
            );
            $updates++;
        }
        return ['updates' => $updates, 'unlinked' => $unlinked];
    }

    /**
     * LastInsertId
     *
     * The auto-increment id allocated by the last INSERT on this connection. Read through SQL so
     * that only the AdapterInterface contract is relied upon (lastInsertId() is adapter-specific).
     *
     * @param AdapterInterface $connection
     * @return string|null
     */
    private function lastInsertId(AdapterInterface $connection): ?string
    {
        /** @var string|false|null $id */
        $id = $connection->fetchOne('SELECT LAST_INSERT_ID()');
        return ($id === false || $id === null || $id === '0' || $id === '') ? null : (string)$id;
    }

    /**
     * HasSelfParent
     *
     * 1 when the row sets any self-reference column (a child), 0 when none are set (a parent) —
     * used to order parents before children for self-referential tables.
     *
     * @param array<string,mixed> $row
     * @param string[] $selfReferences
     * @return int
     */
    private function hasSelfParent(array $row, array $selfReferences): int
    {
        foreach ($selfReferences as $column) {
            if (isset($row[$column]) && $row[$column] !== '') {
                return 1;
            }
        }
        return 0;
    }

    /**
     * Regenerate
     *
     * Rewrite a unique business-key value to a fresh, deterministic-per-run value so clone-mode
     * inserts don't violate a unique index.
     *
     * @param string $strategy
     * @param string $value
     * @param string $token
     * @return string
     */
    private function regenerate(string $strategy, string $value, string $token): string
    {
        if ($strategy === TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX) {
            $at = strrpos($value, '@');
            if ($at !== false) {
                return substr($value, 0, $at) . '+clone-' . $token . substr($value, $at);
            }
        }
        // Default "suffix" (and email fallback when there is no @).
        return $value . '-' . $token;
    }

    /**
     * LoadTakenPks
     *
     * Existing target primary keys among the incoming (remapped) primary-key values.
     *
     * @param AdapterInterface $connection
     * @param string $tableName
     * @param string $pk
     * @param array<int,array<string,mixed>> $prepared
     * @return array<string,true>
     */
    private function loadTakenPks(AdapterInterface $connection, string $tableName, string $pk, array $prepared): array
    {
        $ids = [];
        foreach ($prepared as $entry) {
            $value = $entry['row'][$pk] ?? null;
            if ($value !== null) {
                $ids[(string)$value] = $value;
            }
        }
        return $this->existingValues($connection, $tableName, $pk, $ids);
    }

    /**
     * Which of the given values exist in a column of the target table.
     *
     * Queried in chunks of RelationResolver::IN_CHUNK_SIZE so a very large table never produces
     * one oversized statement.
     *
     * @param AdapterInterface $connection
     * @param string $tableName prefixed table name
     * @param string $column
     * @param array<string,mixed> $values (string)value => value
     * @return array<string,true> (string)value => true for the values present on the target
     */
    private function existingValues(
        AdapterInterface $connection,
        string $tableName,
        string $column,
        array $values
    ): array {
        $existing = [];
        foreach (array_chunk(array_values($values), RelationResolver::IN_CHUNK_SIZE) as $chunk) {
            $select = $connection->select()
                ->from($tableName, [$column])
                ->where($connection->quoteIdentifier($column) . ' IN (?)', $chunk);
            foreach ($connection->fetchCol($select) as $value) {
                $existing[(string)$value] = true;
            }
        }
        return $existing;
    }

    /**
     * FindByUniqueKey
     *
     * Find an existing row's primary key by natural key, or null.
     *
     * @param AdapterInterface $connection
     * @param string $tableName
     * @param string $pk
     * @param string[] $uniqueKey
     * @param array<string,mixed> $row
     * @return string|null
     */
    private function findByUniqueKey(
        AdapterInterface $connection,
        string $tableName,
        string $pk,
        array $uniqueKey,
        array $row
    ): ?string {
        $select = $connection->select()->from($tableName, [$pk]);
        foreach ($uniqueKey as $column) {
            $value = $row[$column] ?? null;
            if ($value === null) {
                $select->where($connection->quoteIdentifier($column) . ' IS NULL');
            } else {
                $select->where($connection->quoteIdentifier($column) . ' = ?', $value);
            }
        }
        $select->limit(1);

        // fetchOne() returns false (not null) when no row matches.
        /** @var string|false|null $found */
        $found = $connection->fetchOne($select);
        return ($found === false || $found === null) ? null : (string)$found;
    }

    /**
     * IsTextColumn
     *
     * Whether a column (as described by the adapter) holds free text, i.e. '' is a legitimate value.
     *
     * @param array<string,mixed> $column
     * @return bool
     */
    private function isTextColumn(array $column): bool
    {
        $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
        return str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'blob')
            || in_array($type, ['enum', 'set', 'json'], true);
    }

    /**
     * StatusLabel
     *
     * @param array<string,mixed> $stats the per-table stats returned by writeTable()
     * @return string
     */
    private function statusLabel(array $stats): string
    {
        $parts = [];
        if ($stats['written'] > 0) {
            $verb = !empty($stats['cloned']) ? 'cloned' : 'imported';
            $parts[] = $verb . ($stats['remapped'] > 0 ? " ({$stats['remapped']} new id)" : '');
        }
        if ($stats['unlinked'] > 0) {
            $parts[] = "unlinked {$stats['unlinked']}";
        }
        if ($stats['dropped'] > 0) {
            $parts[] = "dropped {$stats['dropped']} (dangling link)";
        }
        if ($stats['matched'] > 0) {
            $parts[] = "matched {$stats['matched']}";
        }
        if ($stats['skipped'] > 0) {
            $parts[] = "skipped {$stats['skipped']}";
        }
        return $parts === [] ? 'empty' : implode(', ', $parts);
    }
}
