<?php

declare(strict_types=1);

namespace Inlay\Imports;

use InvalidArgumentException;
use JsonSerializable;

final class ImportDefinition implements JsonSerializable
{
    private ?string $label = null;

    /** @var array{upload: string|null, preview: string|null, start: string|null, status: string|null} */
    private array $endpoints = [
        'upload' => null,
        'preview' => null,
        'start' => null,
        'status' => null,
    ];

    /** @var list<string> */
    private array $acceptedFileTypes = ['text/csv', '.csv'];

    private int $maxFileSize = 10240;

    private int $previewLimit = 50;

    private ?ImportPreview $initialPreview = null;

    /** @var array<string, mixed> */
    private array $options = [];

    private function __construct(
        private readonly string $name,
        private readonly Importer $importer,
    ) {}

    public static function make(string $name, Importer $importer): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('An import definition name cannot be empty.');
        }

        return new self($name, $importer);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function endpoints(
        ?string $upload = null,
        ?string $preview = null,
        ?string $start = null,
        ?string $status = null,
    ): self {
        $this->endpoints = compact('upload', 'preview', 'start', 'status');

        return $this;
    }

    public function uploadEndpoint(string $endpoint): self
    {
        $this->endpoints['upload'] = $endpoint;

        return $this;
    }

    public function previewEndpoint(string $endpoint): self
    {
        $this->endpoints['preview'] = $endpoint;

        return $this;
    }

    public function startEndpoint(string $endpoint): self
    {
        $this->endpoints['start'] = $endpoint;

        return $this;
    }

    public function statusEndpoint(string $endpoint): self
    {
        $this->endpoints['status'] = $endpoint;

        return $this;
    }

    public function acceptedFileTypes(string ...$types): self
    {
        $types = array_values(array_unique(array_filter(array_map('trim', $types))));

        if ($types === []) {
            throw new InvalidArgumentException('At least one accepted import file type is required.');
        }

        $this->acceptedFileTypes = $types;

        return $this;
    }

    /** Set the maximum upload size in kilobytes. */
    public function maxFileSize(int $kilobytes): self
    {
        if ($kilobytes < 1) {
            throw new InvalidArgumentException('The maximum import file size must be at least one kilobyte.');
        }

        $this->maxFileSize = $kilobytes;

        return $this;
    }

    public function previewLimit(int $rows): self
    {
        if ($rows < 1) {
            throw new InvalidArgumentException('The import preview limit must be at least one row.');
        }

        $this->previewLimit = $rows;

        return $this;
    }

    public function preview(?ImportPreview $preview): self
    {
        $this->initialPreview = $preview;

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function importer(): Importer
    {
        return $this->importer;
    }

    public function previewRows(): int
    {
        return $this->previewLimit;
    }

    /** @return array<string, mixed> */
    public function executionOptions(): array
    {
        return $this->options;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'contract' => 'inlay.imports.v1',
            'type' => 'import',
            'name' => $this->name,
            'label' => $this->label ?? ucwords(str_replace(['_', '-'], ' ', $this->name)),
            'endpoints' => $this->endpoints,
            'acceptedFileTypes' => $this->acceptedFileTypes,
            'maxFileSize' => $this->maxFileSize,
            'previewLimit' => $this->previewLimit,
            'preview' => $this->initialPreview,
            'options' => (object) $this->options,
            'columns' => $this->serializedColumns(),
        ];
    }

    /** @return list<ImportColumn> */
    private function serializedColumns(): array
    {
        $columns = [];
        $names = [];

        foreach ($this->importer->columns() as $column) {
            if (! $column instanceof ImportColumn) {
                throw new InvalidArgumentException('Importer columns must be ImportColumn instances.');
            }

            if (isset($names[$column->name()])) {
                throw new InvalidArgumentException("Duplicate import column [{$column->name()}].");
            }

            $names[$column->name()] = true;
            $columns[] = $column;
        }

        return $columns;
    }
}
