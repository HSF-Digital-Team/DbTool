<?php
declare(strict_types=1);

namespace HenrySchein\DbTool\Model\Import;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Framework\Phrase;
use HenrySchein\DbTool\Api\Data\TableDefinitionInterface;
use HenrySchein\DbTool\Api\MapProviderInterface;
use HenrySchein\DbTool\Model\Processor\ProcessorProvider;
use HenrySchein\DbTool\Model\Storage\CsvStorage;

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
 * target already owns (product sub-data, customer grid).
 */
class Importer
{
    private ResourceConnection $resourceConnection;
    private MapProviderInterface $mapProvider;
    private ProcessorProvider $processorProvider;
    private CsvStorage $storage;
    private Random $random;

    public function __construct(
        ResourceConnection $resourceConnection,
        MapProviderInterface $mapProvider,
        ProcessorProvider $processorProvider,
        CsvStorage $storage,
        Random $random
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->mapProvider = $mapProvider;
        $this->processorProvider = $processorProvider;
        $this->storage = $storage;
        $this->random = $random;
    }

    /**
     * Import a customer's previously exported footprint.
     *
     * @param int $customerId
     * @param callable|null $progress fn(string $table, int $written, string $status): void
     * @param bool $cloneMode when true, force-create a new customer (+footprint) instead of matching
     *                        and reusing an existing one; unique business keys are regenerated
     * @return array{directory: string, tables: array<string, int>, skipped: string[], remapped: int,
     *               clone_token: ?string, new_customer_ids: array<int, mixed>}
     * @throws LocalizedException
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

        // Shared across tables for the whole import.
        $idMap = [];   // table => [ (string)oldId => newId ]
        $reused = [];  // table => [ (string)targetId => true ] rows matched to existing target rows

        foreach ($this->mapProvider->getImportOrder() as $definition) {
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

            $stats = $this->writeTable($connection, $definition, $rows, $idMap, $reused, $cloneMode, $cloneToken);
            $results[$table] = $stats['written'];
            $remappedTotal += $stats['remapped'];

            if ($progress !== null) {
                $progress($table, $stats['written'], $this->statusLabel($stats));
            }
        }

        return [
            'directory' => $absoluteDir,
            'tables' => $results,
            'skipped' => $skipped,
            'remapped' => $remappedTotal,
            'clone_token' => $cloneMode ? $cloneToken : null,
            'new_customer_ids' => array_values($idMap['customer_entity'] ?? []),
        ];
    }

    /**
     * Import one table's rows, remapping foreign keys and resolving id collisions.
     *
     * @param AdapterInterface $connection
     * @param TableDefinitionInterface $definition
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, mixed>> $idMap  by-ref, accumulated across tables
     * @param array<string, array<string, bool>> $reused  by-ref, accumulated across tables
     * @param bool $cloneMode force-new mode
     * @param string $cloneToken per-run token used to regenerate unique business keys
     * @return array{written: int, remapped: int, matched: int, skipped: int, cloned: bool}
     */
    private function writeTable(
        AdapterInterface $connection,
        TableDefinitionInterface $definition,
        array $rows,
        array &$idMap,
        array &$reused,
        bool $cloneMode = false,
        string $cloneToken = ''
    ): array {
        $table = $definition->getTable();
        $tableName = $connection->getTableName($table);
        $describe = $connection->describeTable($tableName);
        $columns = array_keys($describe);
        $pk = $definition->getPrimaryKey();
        $pkIsIdentity = !empty($describe[$pk]['IDENTITY']);
        // External references are remapped in the prep phase; self-references (a FK back into this
        // same table, e.g. a configurable/bundle parent_item_id) are remapped in the insert loop
        // once the parent row's new id is known.
        $references = [];
        $selfReferences = [];
        foreach ($definition->getReferences() as $reference) {
            if ($reference['table'] === $table) {
                $selfReferences[] = $reference['column'];
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

            foreach ($references as $reference) {
                $col = $reference['column'];
                if (array_key_exists($col, $clean) && $clean[$col] !== null) {
                    $key = (string)$clean[$col];
                    if (isset($idMap[$reference['table']][$key])) {
                        $clean[$col] = $idMap[$reference['table']][$key];
                    }
                }
            }

            // Clone mode: rewrite unique business-key columns to fresh, consistent values.
            foreach ($cloneRegenerate as $col => $strategy) {
                if (array_key_exists($col, $clean) && $clean[$col] !== null && $clean[$col] !== '') {
                    $clean[$col] = $this->regenerate($strategy, (string)$clean[$col], $cloneToken);
                }
            }

            // Skip sub-data whose parent already exists on the target.
            if ($definition->isSkipIfParentReused() && $parentTable !== null && $foreignKey !== null) {
                $parentId = $clean[$foreignKey] ?? null;
                if ($parentId !== null && isset($reused[$parentTable][(string)$parentId])) {
                    $skippedRows++;
                    continue;
                }
            }

            $prepared[] = ['original_pk' => $originalPk, 'row' => $clean];
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

        $connection->beginTransaction();
        try {
            foreach ($prepared as $entry) {
                $row = $entry['row'];
                $originalPk = $entry['original_pk'];

                // 0) Remap self-references to the parent row inserted earlier in this same loop.
                foreach ($selfReferences as $selfColumn) {
                    if (isset($row[$selfColumn]) && $row[$selfColumn] !== null) {
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

                // 2) Insert, keeping the id when free or allocating a new one on collision.
                $pkValue = $row[$pk] ?? null;
                if ($pkIsIdentity && $pkValue !== null && isset($takenPks[(string)$pkValue])) {
                    unset($row[$pk]);
                    $connection->insert($tableName, $row);
                    $targetPk = $connection->lastInsertId($tableName);
                    $remapped++;
                } else {
                    $connection->insert($tableName, $row);
                    $targetPk = $pkValue !== null ? $pkValue : $connection->lastInsertId($tableName);
                }
                $written++;

                if ($targetPk !== null) {
                    $takenPks[(string)$targetPk] = true;
                    if ($originalPk !== null) {
                        $idMap[$table][(string)$originalPk] = $targetPk;
                    }
                }
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
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
     * 1 when the row sets any self-reference column (a child), 0 when none are set (a parent) —
     * used to order parents before children for self-referential tables.
     *
     * @param array<string, mixed> $row
     * @param string[] $selfReferences
     */
    private function hasSelfParent(array $row, array $selfReferences): int
    {
        foreach ($selfReferences as $column) {
            if (isset($row[$column]) && $row[$column] !== null && $row[$column] !== '') {
                return 1;
            }
        }
        return 0;
    }

    /**
     * Rewrite a unique business-key value to a fresh, deterministic-per-run value so clone-mode
     * inserts don't violate a unique index.
     *
     * @param string $strategy "email_suffix" | "suffix"
     */
    private function regenerate(string $strategy, string $value, string $token): string
    {
        if ($strategy === 'email_suffix') {
            $at = strrpos($value, '@');
            if ($at !== false) {
                return substr($value, 0, $at) . '+clone-' . $token . substr($value, $at);
            }
        }
        // Default "suffix" (and email fallback when there is no @).
        return $value . '-' . $token;
    }

    /**
     * Existing target primary keys among the incoming (remapped) primary-key values.
     *
     * @param array<int, array{original_pk: mixed, row: array<string, mixed>}> $prepared
     * @return array<string, bool>
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
     * Find an existing row's primary key by natural key, or null.
     *
     * @param string[] $uniqueKey
     * @param array<string, mixed> $row
     * @return mixed|null
     */
    private function findByUniqueKey(
        AdapterInterface $connection,
        string $tableName,
        string $pk,
        array $uniqueKey,
        array $row
    ) {
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

        $found = $connection->fetchOne($select);
        return $found === false ? null : $found;
    }

    /**
     * @param array{written: int, remapped: int, matched: int, skipped: int} $stats
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
