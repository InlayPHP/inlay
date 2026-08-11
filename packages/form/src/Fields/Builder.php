<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Blocks\Block;
use Inlay\Schemas\SchemaContext;

/**
 * A repeatable field whose items each choose one named block. Every item is
 * stored as `{type: <block name>, data: {...}}`, so a page builder can mix
 * headings, paragraphs, and galleries in one field.
 */
final class Builder extends Repeater
{
    /** @var array<string, Block> */
    private array $blocks = [];

    protected function type(): string
    {
        return 'builder';
    }

    /** @param list<Block> $blocks */
    public function blocks(array $blocks): self
    {
        $resolved = [];
        foreach ($blocks as $block) {
            if (! $block instanceof Block) {
                throw new \InvalidArgumentException("Builder [{$this->name}] blocks must be ".Block::class.' instances.');
            }
            if (isset($resolved[$block->name()])) {
                throw new \InvalidArgumentException("Builder [{$this->name}] block names must be unique; [{$block->name()}] is duplicated.");
            }
            $resolved[$block->name()] = $block;
        }

        if ($resolved === []) {
            throw new \InvalidArgumentException("Builder [{$this->name}] must declare at least one block.");
        }

        $this->blocks = $resolved;

        return $this;
    }

    /** @return array<string, Block> */
    public function blockDefinitions(): array
    {
        return $this->blocks;
    }

    /**
     * Build Laravel rules for the submitted items. Each item is validated
     * against its own block, and an unknown block name fails on `type` rather
     * than silently skipping that item's rules.
     *
     * @return array<string, list<string>>
     */
    public function stateRules(string $path, mixed $state): array
    {
        $this->assertBlocks();
        $names = implode(',', array_keys($this->blocks));
        $rules = [
            $path.'.*.type' => ['required', 'string', 'in:'.$names],
            $path.'.*.data' => ['array'],
        ];

        if (! is_array($state)) {
            return $rules;
        }

        foreach (array_values($state) as $index => $item) {
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            if (! is_string($type) || ! isset($this->blocks[$type])) {
                continue;
            }
            $dataPath = $path.'.'.$index.'.data.';
            $itemData = is_array($item['data'] ?? null) ? $item['data'] : null;
            $rules = [...$rules, ...$this->blocks[$type]->fieldRules($dataPath, $itemData)];
        }

        return $rules;
    }

    /**
     * @return list<string> Block names used more often than they allow.
     */
    public function exceededBlocks(mixed $state): array
    {
        $counts = [];
        foreach (is_array($state) ? $state : [] as $item) {
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            if (is_string($type)) {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        $exceeded = [];
        foreach ($this->blocks as $name => $block) {
            $maximum = $block->maxItemsValue();
            if ($maximum !== null && ($counts[$name] ?? 0) > $maximum) {
                $exceeded[] = $name;
            }
        }

        return $exceeded;
    }

    /**
     * Resolve the schema for each active row under its own block data.
     *
     * The map is keyed by the submitted Builder index so a renderer can keep
     * row identity stable while a block type appears more than once.  It is a
     * renderer-neutral compatibility extension: the existing `blocks` array
     * remains the block-definition registry, while `resolvedSchemas` carries
     * only the schemas authorized for currently active rows.
     *
     * @return array<string, array{type: string, schema: list<object>}>
     */
    public function resolvedBlockSchemas(): array
    {
        $serverConditions = $this->getOwningSchema()?->usesServerConditions() === true;
        if (! $serverConditions) {
            return [];
        }

        $this->assertBlocks();
        $state = $this->schemaContext?->get($this->name());
        if (! is_array($state)) {
            return [];
        }

        $context = $this->schemaContext ?? SchemaContext::make();
        $resolved = [];
        foreach (array_values($state) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;
            if (! is_string($type) || ! isset($this->blocks[$type])) {
                continue;
            }

            $data = is_array($item['data'] ?? null) ? $item['data'] : [];
            $resolved[(string) $index] = [
                'type' => $type,
                'schema' => $this->blocks[$type]->serializedSchemaForState($data, $context, true, $this->effectiveInlineLabel()),
            ];
        }

        return $resolved;
    }

    private function assertBlocks(): void
    {
        if ($this->blocks === []) {
            throw new \LogicException("Builder [{$this->name}] must declare blocks.");
        }
    }

    /**
     * Summaries for the items currently in state, keyed by position.
     *
     * A collapsed block would otherwise show only its type, so each block that
     * declares a preview contributes text for its own items.
     *
     * @return array<int, string>
     */
    private function resolvedPreviews(): array
    {
        $items = $this->schemaContext?->get($this->name());
        if (! is_array($items)) {
            return [];
        }

        $previews = [];
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $block = $this->blocks[$item['type'] ?? ''] ?? null;
            if ($block === null || ! $block->hasPreview()) {
                continue;
            }
            $preview = $block->resolvePreview(is_array($item['data'] ?? null) ? $item['data'] : []);
            if ($preview !== null) {
                $previews[$index] = $preview;
            }
        }

        return $previews;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $this->assertBlocks();

        $serverConditions = $this->getOwningSchema()?->usesServerConditions() === true;
        $blocks = array_values(array_map(
            function (Block $block) use ($serverConditions): array {
                $definition = $block->jsonSerializeWithInlineLabel($this->effectiveInlineLabel());

                // A definition is shared by every row. Under authoritative
                // conditions it cannot safely contain a conditional schema;
                // active rows use `resolvedSchemas` below instead. Keeping
                // the metadata (name, label, icon, limits) preserves the
                // original block registry contract for pickers and
                // third-party clients.
                if ($serverConditions) {
                    $definition['schema'] = [];
                }

                return $definition;
            },
            $this->blocks,
        ));

        $payload = [
            ...parent::jsonSerialize(),
            'schema' => [],
            'blocks' => $blocks,
            'previews' => $this->resolvedPreviews(),
        ];

        if ($serverConditions) {
            $payload['resolvedSchemas'] = $this->resolvedBlockSchemas();
        }

        return $payload;
    }
}
