<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Config;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use EcomEU\DbTool\Api\Data\TableDefinitionInterface;

/**
 * Immutable value object built from one raw map entry.
 *
 * Instantiate through the generated TableDefinitionFactory:
 *     $factory->create(['data' => $rawRow]);
 */
class TableDefinition implements TableDefinitionInterface
{
    /**
     * @param array<string,mixed> $data
     * @throws LocalizedException
     */
    public function __construct(
        private readonly array $data
    ) {
        $this->validate();
    }

    /**
     * Validate
     *
     * @throws LocalizedException
     */
    private function validate(): void
    {
        if (empty($this->data['table'])) {
            throw new LocalizedException(new Phrase('Tool_DbTool: a map entry is missing "table".'));
        }

        $type = $this->data['relationship_type'] ?? null;
        if (!in_array($type, [self::RELATION_DIRECT, self::RELATION_CHILD, self::RELATION_LOOKUP], true)) {
            throw new LocalizedException(new Phrase(
                'Tool_DbTool: table "%1" has an invalid relationship_type "%2" '
                . '(expected "direct", "child" or "lookup").',
                [$this->data['table'], (string)$type]
            ));
        }

        if ($type === self::RELATION_DIRECT && empty($this->data['customer_column'])) {
            throw new LocalizedException(new Phrase(
                'Tool_DbTool: direct table "%1" is missing "customer_column".',
                [$this->data['table']]
            ));
        }

        if ($type === self::RELATION_CHILD) {
            foreach (['parent', 'parent_key', 'foreign_key'] as $required) {
                if (empty($this->data[$required])) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: child table "%1" is missing "%2".',
                        [$this->data['table'], $required]
                    ));
                }
            }
        }

        if ($type === self::RELATION_LOOKUP && empty($this->data['lookup_sources'])) {
            throw new LocalizedException(new Phrase(
                'Tool_DbTool: lookup table "%1" is missing "lookup_sources".',
                [$this->data['table']]
            ));
        }

        $strategies = [self::STRATEGY_EMAIL_SUFFIX, self::STRATEGY_SUFFIX];
        foreach (['clone_regenerate', 'clone_mirror'] as $field) {
            foreach ($this->normalizeStrategyMap($field) as $column => $strategy) {
                if (!in_array($strategy, $strategies, true)) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: table "%1" %2 column "%3" has an unknown strategy "%4" (expected "%5").',
                        [$this->data['table'], $field, $column, $strategy, implode('" or "', $strategies)]
                    ));
                }
            }
        }
    }

    /**
     * Normalize a column => strategy map field.
     *
     * @param string $field
     * @return array<string,string>
     */
    private function normalizeStrategyMap(string $field): array
    {
        $map = $this->data[$field] ?? [];
        if (!is_array($map)) {
            return [];
        }
        $normalized = [];
        foreach ($map as $column => $strategy) {
            $normalized[(string)$column] = (string)$strategy;
        }
        return $normalized;
    }

    /**
     * GetTable
     *
     * @return string
     */
    public function getTable(): string
    {
        return (string)$this->data['table'];
    }

    /**
     * GetPrimaryKey
     *
     * @return string
     */
    public function getPrimaryKey(): string
    {
        return (string)($this->data['primary_key'] ?? 'entity_id');
    }

    /**
     * GetRelationshipType
     *
     * @return string
     */
    public function getRelationshipType(): string
    {
        return (string)$this->data['relationship_type'];
    }

    /**
     * GetCustomerColumn
     *
     * @return string|null
     */
    public function getCustomerColumn(): ?string
    {
        return isset($this->data['customer_column']) ? (string)$this->data['customer_column'] : null;
    }

    /**
     * GetParent
     *
     * @return string|null
     */
    public function getParent(): ?string
    {
        return isset($this->data['parent']) ? (string)$this->data['parent'] : null;
    }

    /**
     * GetParentKey
     *
     * @return string|null
     */
    public function getParentKey(): ?string
    {
        return isset($this->data['parent_key']) ? (string)$this->data['parent_key'] : null;
    }

    /**
     * GetForeignKey
     *
     * @return string|null
     */
    public function getForeignKey(): ?string
    {
        return isset($this->data['foreign_key']) ? (string)$this->data['foreign_key'] : null;
    }

    /**
     * GetExportPriority
     *
     * @return int
     */
    public function getExportPriority(): int
    {
        return (int)($this->data['export_priority'] ?? 0);
    }

    /**
     * GetImportPriority
     *
     * @return int
     */
    public function getImportPriority(): int
    {
        // Import defaults to the same ordering as export unless explicitly overridden.
        return (int)($this->data['import_priority'] ?? $this->getExportPriority());
    }

    /**
     * GetFilter
     *
     * @return array<string,mixed>
     */
    public function getFilter(): array
    {
        $filter = $this->data['filter'] ?? [];
        return is_array($filter) ? $filter : [];
    }

    /**
     * GetProcessor
     *
     * @return string|null
     */
    public function getProcessor(): ?string
    {
        return isset($this->data['processor']) ? (string)$this->data['processor'] : null;
    }

    /**
     * GetLookupSources
     *
     * @return array<int,array{table:string,column:string}>
     */
    public function getLookupSources(): array
    {
        $sources = $this->data['lookup_sources'] ?? [];
        if (!is_array($sources)) {
            return [];
        }
        $normalized = [];
        foreach ($sources as $source) {
            if (!empty($source['table']) && !empty($source['column'])) {
                $normalized[] = [
                    'table' => (string)$source['table'],
                    'column' => (string)$source['column'],
                ];
            }
        }
        return $normalized;
    }

    /**
     * GetReferences
     *
     * @return array<int,array{column:string,table:string}>
     */
    public function getReferences(): array
    {
        $references = [];

        // Auto-derive the child -> parent foreign-key reference.
        if ($this->getRelationshipType() === self::RELATION_CHILD) {
            $references[] = [
                'column' => $this->getForeignKey(),
                'table' => $this->getParent(),
            ];
        }

        // Explicit cross-link references declared in the map.
        foreach ($this->data['references'] ?? [] as $reference) {
            if (!empty($reference['column']) && !empty($reference['table'])) {
                $references[] = [
                    'column' => (string)$reference['column'],
                    'table' => (string)$reference['table'],
                ];
            }
        }

        return $references;
    }

    /**
     * GetUniqueKey
     *
     * @return string[]
     */
    public function getUniqueKey(): array
    {
        $key = $this->data['unique_key'] ?? [];
        return is_array($key) ? array_values(array_map('strval', $key)) : [];
    }

    /**
     * GetSkipIfParentReused
     *
     * @return bool
     */
    public function isSkipIfParentReused(): bool
    {
        return !empty($this->data['skip_if_parent_reused']);
    }

    /**
     * GetCloneNew
     *
     * @return bool
     */
    public function isCloneNew(): bool
    {
        return !empty($this->data['clone_new']);
    }

    /**
     * GetCloneRegenerate
     *
     * @return array<string,string>
     */
    public function getCloneRegenerate(): array
    {
        return $this->normalizeStrategyMap('clone_regenerate');
    }

    /**
     * GetCloneMirror
     *
     * @return array<string,string>
     */
    public function getCloneMirror(): array
    {
        return $this->normalizeStrategyMap('clone_mirror');
    }
}
