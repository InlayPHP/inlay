<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields\RichEditor;

use Closure;
use Illuminate\Http\Request;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\SafeUrl;

final class MentionProvider implements \JsonSerializable
{
    /** @var array<string, string> */
    private array $items = [];

    private ?Closure $getSearchResultsUsing = null;

    private ?Closure $getLabelsUsing = null;

    private Closure|string|null $url = null;

    private ?string $endpoint = null;

    private string $method = 'post';

    private int $optionsLimit = 20;

    private int $searchDebounce = 300;

    private function __construct(private readonly string $trigger)
    {
        if (mb_strlen($trigger) !== 1 || preg_match('/^[\pL\pN\s]$/u', $trigger) === 1 || preg_match('/[<>"\'`]/', $trigger) === 1) {
            throw new \InvalidArgumentException('Mention triggers must be one safe non-alphanumeric character.');
        }
    }

    public static function make(string $trigger): self
    {
        return new self($trigger);
    }

    /** @param array<string|int, string> $items */
    public function items(array $items): self
    {
        $this->items = $this->normalizeOptions($items);

        return $this;
    }

    public function getSearchResultsUsing(Closure $callback): self
    {
        $this->getSearchResultsUsing = $callback;

        return $this;
    }

    public function getLabelsUsing(Closure $callback): self
    {
        $this->getLabelsUsing = $callback;

        return $this;
    }

    public function url(Closure|string|null $url): self
    {
        if (is_string($url)) {
            SafeUrl::from($url);
        }
        $this->url = $url;

        return $this;
    }

    public function optionsLimit(int $limit): self
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Mention option limits must be between 1 and 100.');
        }
        $this->optionsLimit = $limit;

        return $this;
    }

    public function searchDebounce(int $milliseconds): self
    {
        if ($milliseconds < 0 || $milliseconds > 2000) {
            throw new \InvalidArgumentException('Mention search debounce must be between 0 and 2000 milliseconds.');
        }
        $this->searchDebounce = $milliseconds;

        return $this;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function configureEndpoint(?string $endpoint, string $method = 'post'): void
    {
        $this->endpoint = $endpoint;
        $this->method = $method;
    }

    /** @return list<array{id: string, label: string}> */
    public function search(string $search, ?Request $request = null): array
    {
        if (mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Mention searches may not exceed 200 characters.');
        }
        $options = $this->getSearchResultsUsing === null
            ? $this->items
            : $this->normalizeOptions(ClosureEvaluator::evaluate($this->getSearchResultsUsing, [
                'provider' => $this,
                'request' => $request,
                'search' => $search,
            ], [self::class => $this], [$search, $request, $this]));
        if ($this->getSearchResultsUsing === null && $search !== '') {
            $options = array_filter($options, static fn (string $label): bool => str_contains(mb_strtolower($label), mb_strtolower($search)));
        }

        return array_map(
            static fn (string $label, string $id): array => ['id' => $id, 'label' => $label],
            array_slice($options, 0, $this->optionsLimit, true),
            array_keys(array_slice($options, 0, $this->optionsLimit, true)),
        );
    }

    /** @param list<string|int> $ids @return array<string, string> */
    public function labels(array $ids, ?Request $request = null): array
    {
        $ids = array_values(array_unique(array_map(static fn (string|int $id): string => (string) $id, $ids)));
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Mention label requests may not exceed 100 IDs.');
        }
        $labels = $this->getLabelsUsing === null
            ? array_intersect_key($this->items, array_fill_keys($ids, true))
            : $this->normalizeOptions(ClosureEvaluator::evaluate($this->getLabelsUsing, [
                'ids' => $ids,
                'provider' => $this,
                'request' => $request,
            ], [self::class => $this], [$ids, $request, $this]));

        return array_intersect_key($labels, array_fill_keys($ids, true));
    }

    public function render(string|int $id, ?string $fallbackLabel = null, ?Request $request = null): string
    {
        $id = (string) $id;
        $label = $this->labels([$id], $request)[$id] ?? $fallbackLabel;
        if (! is_string($label) || $label === '') {
            return '';
        }
        $text = htmlspecialchars($this->trigger.$label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $url = $this->url;
        if ($url instanceof Closure) {
            $url = ClosureEvaluator::evaluate($url, [
                'id' => $id,
                'label' => $label,
                'provider' => $this,
                'request' => $request,
            ], [self::class => $this], [$id, $label, $request, $this]);
        }
        if ($url === null) {
            return '<span>'.$text.'</span>';
        }
        if (! is_string($url)) {
            throw new \UnexpectedValueException('Mention URLs must resolve to a string or null.');
        }

        return '<a href="'.htmlspecialchars(SafeUrl::from($url)->value(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'">'.$text.'</a>';
    }

    public function jsonSerialize(): array
    {
        if ($this->getSearchResultsUsing !== null && $this->getLabelsUsing === null) {
            throw new \LogicException("Dynamic mention provider [{$this->trigger}] requires getLabelsUsing().");
        }

        return [
            'trigger' => $this->trigger,
            'items' => array_map(static fn (string $label, string $id): array => ['id' => $id, 'label' => $label], $this->items, array_keys($this->items)),
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'dynamic' => $this->getSearchResultsUsing !== null,
            'optionsLimit' => $this->optionsLimit,
            'searchDebounce' => $this->searchDebounce,
        ];
    }

    /** @return array<string, string> */
    private function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            throw new \UnexpectedValueException('Mention option callbacks must return an associative array.');
        }
        $normalized = [];
        foreach ($options as $id => $label) {
            if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '' || ! is_string($label) || trim($label) === '') {
                throw new \UnexpectedValueException('Mention options require non-empty scalar IDs and labels.');
            }
            $normalized[(string) $id] = trim($label);
        }

        return $normalized;
    }
}
