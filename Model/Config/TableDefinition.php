<?php
declare(strict_types=1);

namespace Tool\DbTool\Model\Config;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Tool\DbTool\Api\Data\TableDefinitionInterface;

/**
 * Immutable value object built from one raw map entry.
 *
 * Instantiate through the generated TableDefinitionFactory:
 *     $factory->create(['data' => $rawRow]);
 */
class TableDefinition implements TableDefinitionInterface
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->validate();
    }

    /**
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
    }

    public function getTable(): string
    {
        return (string)$this->data['table'];
    }

    public function getPrimaryKey(): string
    {
        return (string)($this->data['primary_key'] ?? 'entity_id');
    }

    public function getRelationshipType(): string
    {
        return (string)$this->data['relationship_type'];
    }

    public function getCustomerColumn(): ?string
    {
        return isset($this->data['customer_column']) ? (string)$this->data['customer_column'] : null;
    }

    public function getParent(): ?string
    {
        return isset($this->data['parent']) ? (string)$this->data['parent'] : null;
    }

    public function getParentKey(): ?string
    {
        return isset($this->data['parent_key']) ? (string)$this->data['parent_key'] : null;
    }

    public function getForeignKey(): ?string
    {
        return isset($this->data['foreign_key']) ? (string)$this->data['foreign_key'] : null;
    }

    public function getExportPriority(): int
    {
        return (int)($this->data['export_priority'] ?? 0);
    }

    public function getImportPriority(): int
    {
        // Import defaults to the same ordering as export unless explicitly overridden.
        return (int)($this->data['import_priority'] ?? $this->getExportPriority());
    }

    public function getFilter(): array
    {
        $filter = $this->data['filter'] ?? [];
        return is_array($filter) ? $filter : [];
    }

    public function getProcessor(): ?string
    {
        return isset($this->data['processor']) ? (string)$this->data['processor'] : null;
    }

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

    public function getUniqueKey(): array
    {
        $key = $this->data['unique_key'] ?? [];
        return is_array($key) ? array_values(array_map('strval', $key)) : [];
    }

    public function isSkipIfParentReused(): bool
    {
        return !empty($this->data['skip_if_parent_reused']);
    }

    public function isCloneNew(): bool
    {
        return !empty($this->data['clone_new']);
    }

    public function getCloneRegenerate(): array
    {
        $map = $this->data['clone_regenerate'] ?? [];
        if (!is_array($map)) {
            return [];
        }
        $normalized = [];
        foreach ($map as $column => $strategy) {
            $normalized[(string)$column] = (string)$strategy;
        }
        return $normalized;
    }
}
