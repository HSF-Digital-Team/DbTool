<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Import;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;
use EcomEU\DbTool\Api\MapProviderInterface;
use EcomEU\DbTool\Model\Processor\ProcessorProvider;
use EcomEU\DbTool\Model\Storage\CsvStorage;
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
 * The whole run is one transaction: a failure on any table rolls back everything.
 */
class Importer
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param MapProviderInterface $mapProvider
     * @param ProcessorProvider $processorProvider
     * @param CsvStorage $storage
     * @param Random $random
     */
    public function __construct(
        private ResourceConnection   $resourceConnection,
        private MapProviderInterface $mapProvider,
        private ProcessorProvider    $processorProvider,
        private CsvStorage           $storage,
        private Random               $random
    ) {
    }

    /**
     * Import a customer's previously exported footprint.
     *
     * @param int $customerId
     * @param callable|null $progress fn(string $table, int $written, string $status): void
     * @param bool $cloneMode when true, force-create a new customer (+footprint) instead of matching
     *                        and reusing an existing one; unique business keys are regenerated
     * @return array{directory: string, tables: array<string,int>, skipped: string[], remapped: int,
     *               deferred_updates: int, clone_token: ?string, new_customer_ids: array<int,mixed>,
     *               new_customers: array<int,array{id:mixed,email:mixed}>}
     * @throws LocalizedException|Throwable
     */
    public function import(int $customerId, ?callable $progress = null, bool $cloneMode = false): array
    {
        $absoluteDir = $this->storage->getImportDir($customerId);
        if (!$this->storage->dirExists($absoluteDir)) {
            throw new LocalizedException(new Phrase(
                'Tool_DbTool: import directory not found: %1',
                [$absoluteDir]
            ));
        }

        // One token per run so every regenerated value (order + its grid, customer + its grid) is
        // consistent, while re-running clone produces a fresh, distinct copy.
        $cloneToken = $cloneMode ? strtolower($this->random->getRandomString(6)) : '';

        $connection = $this->resourceConnection->getConnection();
        $results = [];
        $skipped = [];
        $remappedTotal = 0;

        $definitions = $this->mapProvider->getImportOrder();
        // table => position in the import order; a reference to a table with a higher position is
        // deferred until the end of the run.
        $order = [];
        foreach ($definitions as $position => $definition) {
            $order[$definition->getTable()] = $position;
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
                    $rows = $processor->processImportRows($table, $rows);
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
                    $cloneMode,
                    $cloneToken
                );
                $results[$table] = $stats['written'];
                $remappedTotal += $stats['remapped'];

                if ($progress !== null) {
                    $progress($table, $stats['written'], $this->statusLabel($stats));
                }
            }

            $deferredUpdates = $this->applyDeferredReferences($connection, $deferred, $idMap);

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $newCustomerIds = array_values($idMap['customer_entity'] ?? []);

        return [
            'directory' => $absoluteDir,
            'tables' => $results,
            'skipped' => $skipped,
            'remapped' => $remappedTotal,
            'deferred_updates' => $deferredUpdates,
            'clone_token' => $cloneMode ? $cloneToken : null,
            'new_customer_ids' => $newCustomerIds,
            'new_customers' => $this->resolveCustomers($connection, $newCustomerIds),
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
     * @param bool $cloneMode force-new mode
     * @param string $cloneToken per-run token used to regenerate unique business keys
     * @return array{written:int,remapped:int,matched:int,skipped:int,cloned:bool}
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
        bool $cloneMode = false,
        string $cloneToken = ''
    ): array {
        $table = $definition->getTable();
        $tableName = $this->resourceConnection->getTableName($table);
        $describe = $connection->describeTable($tableName);
        $columns = array_keys($describe);
        $pk = $definition->getPrimaryKey();
        $pkIsIdentity = !empty($describe[$pk]['IDENTITY']);

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
        $uniqueKey = $definition->getUniqueKey();
        $parentTable = $definition->getParent();
        $foreignKey = $definition->getForeignKey();

        // Clone mode: force this entity to be created new (skip the reuse lookup) and regenerate its
        // unique business-key columns so the inserts don't collide.
        $cloneNew = $cloneMode && $definition->isCloneNew();
        $cloneRegenerate = $cloneMode ? $definition->getCloneRegenerate() : [];
        $cloneMirror = $cloneMode ? $definition->getCloneMirror() : [];

        $idMap[$table] = $idMap[$table] ?? [];
        $written = $matched = $remapped = $skippedRows = 0;

        // Prepare rows: keep real columns, empty->NULL, remap references. Preserve the original
        // primary key (before any reference remap can touch it) for the id map key.
        $prepared = [];
        foreach ($rows as $row) {
            $clean = [];
            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                $value = $row[$column];
                if ($value === '' && !empty($describe[$column]['NULLABLE'])) {
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

            foreach ($references as $reference) {
                $col = $reference['column'];
                if (array_key_exists($col, $clean) && $clean[$col] !== null) {
                    $key = (string)$clean[$col];
                    if (isset($idMap[$reference['table']][$key])) {
                        $clean[$col] = $idMap[$reference['table']][$key];
                    }
                }
            }

            $deferredValues = [];
            foreach ($deferredReferences as $reference) {
                $col = $reference['column'];
                if (array_key_exists($col, $clean) && $clean[$col] !== null && $clean[$col] !== '') {
                    $deferredValues[] = [
                        'column' => $col,
                        'ref_table' => $reference['table'],
                        'old_value' => $clean[$col],
                    ];
                }
            }

            // Clone mode: mirror the business-key values decided by a force-new entity imported
            // earlier (kept or regenerated), so every copy of the value stays consistent: the grid
            // rows (clone_regenerate on a non-clone_new table) and the denormalised copies such as
            // quote.customer_email / sales_order_address.email (clone_mirror). The deciding parent
            // (clone_new) is handled at insert time below, not here.
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

            $prepared[] = ['original_pk' => $originalPk, 'row' => $clean, 'deferred' => $deferredValues];
        }

        if ($prepared === []) {
            return ['written' => 0, 'remapped' => 0, 'matched' => 0, 'skipped' => $skippedRows, 'cloned' => $cloneNew];
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
            $pkValue = $row[$pk] ?? null;
            $forceNewId = $cloneNew && $pkIsIdentity;
            $collision = $pkIsIdentity && $pkValue !== null && isset($takenPks[(string)$pkValue]);
            if ($forceNewId || $collision) {
                unset($row[$pk]);
                $connection->insert($tableName, $row);
                $targetPk = $this->lastInsertId($connection);
                $remapped++;
            } else {
                $connection->insert($tableName, $row);
                $targetPk = $pkValue !== null ? $pkValue : $this->lastInsertId($connection);
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
                    ];
                }
            }
        }

        return [
            'written' => $written,
            'remapped' => $remapped,
            'matched' => $matched,
            'skipped' => $skippedRows,
            'cloned' => $cloneNew,
        ];
    }

    /**
     * ApplyDeferredReferences
     *
     * Rewrite the references that point at tables imported later, now that every id is known.
     * Only rows this run inserted are touched (a reused target row keeps its own links). Returns
     * the number of UPDATEs issued.
     *
     * @param AdapterInterface $connection
     * @param array<int,array<string,mixed>> $deferred
     * @param array<string,array<string,mixed>> $idMap
     * @return int
     */
    private function applyDeferredReferences(AdapterInterface $connection, array $deferred, array $idMap): int
    {
        $updates = 0;
        foreach ($deferred as $entry) {
            $new = $idMap[$entry['ref_table']][(string)$entry['old_value']] ?? null;
            if ($new === null || (string)$new === (string)$entry['old_value']) {
                continue;
            }
            $connection->update(
                $entry['table'],
                [$entry['column'] => $new],
                [$connection->quoteIdentifier($entry['pk']) . ' = ?' => $entry['target_pk']]
            );
            $updates++;
        }
        return $updates;
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
        if ($ids === []) {
            return [];
        }
        $select = $connection->select()
            ->from($tableName, [$pk])
            ->where($connection->quoteIdentifier($pk) . ' IN (?)', array_values($ids));

        $taken = [];
        foreach ($connection->fetchCol($select) as $value) {
            $taken[(string)$value] = true;
        }
        return $taken;
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
     * StatusLabel
     *
     * @param array{written:int,remapped:int,matched:int,skipped:int,cloned:bool} $stats
     * @return string
     */
    private function statusLabel(array $stats): string
    {
        $parts = [];
        if ($stats['written'] > 0) {
            $verb = !empty($stats['cloned']) ? 'cloned' : 'imported';
            $parts[] = $verb . ($stats['remapped'] > 0 ? " ({$stats['remapped']} new id)" : '');
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
