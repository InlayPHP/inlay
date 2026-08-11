<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\HasSchema;
use Inlay\Schemas\SchemaContext;
use Inlay\Support\SafeUrl;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Mount a renderer supplied by an application or community package.
 *
 * View data is deliberately restricted to JSON-compatible values because it
 * crosses the Inertia boundary. Registered React and Vue renderers receive the
 * complete component payload and the nested schema as their default children.
 */
final class View extends Component
{
    use HasSchema;

    private const VIEW_PATTERN = '/^[a-z][a-z0-9]*(?:[._\/-][a-z0-9]+)*$/';

    /** @var array<string, mixed>|Closure */
    private array|Closure $viewData = [];

    private bool $deferred = false;

    private bool $lazy = false;

    private ?string $deferredEndpoint = null;

    private string $loadingMessage = 'Loading…';

    private string $errorMessage = 'This content could not be loaded.';

    private bool $retryable = true;

    public function __construct(private readonly string $view)
    {
        $this->assertViewName($view);

        parent::__construct(str_replace(['/', '.'], '-', $view));
    }

    public static function make(string $view): static
    {
        return new static($view);
    }

    protected function type(): string
    {
        return 'view';
    }

    protected function rendererCategory(): string
    {
        return 'schema';
    }

    /**
     * @param array<string, mixed>|Closure $data
     */
    public function viewData(array|Closure $data): self
    {
        $this->viewData = $data;

        return $this;
    }

    /**
     * Alias for viewData(), useful for renderer-neutral component libraries.
     *
     * @param array<string, mixed>|Closure $data
     */
    public function data(array|Closure $data): self
    {
        return $this->viewData($data);
    }

    public function defer(bool|string $deferred = true): self
    {
        if (is_string($deferred)) {
            $this->deferredEndpoint = SafeUrl::from($deferred)->value();
            $this->deferred = true;

            return $this;
        }

        $this->deferred = $deferred;
        if (! $deferred) {
            $this->deferredEndpoint = null;
        }

        return $this;
    }

    /**
     * Defer the view data and wait until the island approaches the viewport.
     */
    public function lazy(bool $lazy = true): self
    {
        $this->lazy = $lazy;

        if ($lazy) {
            $this->deferred = true;
        }

        return $this;
    }

    public function loadingMessage(string $message): self
    {
        if (trim($message) === '') {
            throw new InvalidArgumentException('A deferred schema view loading message cannot be empty.');
        }

        $this->loadingMessage = trim($message);

        return $this;
    }

    public function errorMessage(string $message): self
    {
        if (trim($message) === '') {
            throw new InvalidArgumentException('A deferred schema view error message cannot be empty.');
        }

        $this->errorMessage = trim($message);

        return $this;
    }

    public function retryable(bool $retryable = true): self
    {
        $this->retryable = $retryable;

        return $this;
    }

    /** @internal Used by PHP hosts that own the current authorized route. */
    public function configureDeferredEndpoint(?string $endpoint): self
    {
        if ($this->deferred && $this->deferredEndpoint === null && $endpoint !== null) {
            $this->deferredEndpoint = SafeUrl::from($endpoint)->value();
        }

        return $this;
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    public function viewName(): string
    {
        return $this->view;
    }

    /**
     * @param array<string, mixed> $named
     * @param array<class-string, object> $typed
     * @return array<string, mixed>
     */
    public function resolveDeferredData(array $named = [], array $typed = []): array
    {
        if (! $this->deferred) {
            throw new \LogicException("Schema view [{$this->view}] is not deferred.");
        }

        return $this->resolvedViewData($named, $typed);
    }

    /**
     * Build the stable JSON response used by custom hosts and Infolists.
     *
     * @param array<string, mixed> $named
     * @param array<class-string, object> $typed
     * @return array{contract: string, view: string, name: string, data: object}
     */
    public function resolveDeferredPayload(array $named = [], array $typed = []): array
    {
        return [
            'contract' => 'inlay.schemas.deferred-view.v1',
            'view' => $this->view,
            'name' => $this->name(),
            'data' => (object) $this->resolveDeferredData($named, $typed),
        ];
    }

    public function jsonSerialize(): array
    {
        if ($this->deferred && $this->deferredEndpoint === null) {
            throw new \LogicException("Deferred schema view [{$this->view}] requires an endpoint or a supported PHP host.");
        }

        return [
            ...parent::jsonSerialize(),
            ...$this->serializedSchema(),
            'view' => $this->view,
            'data' => (object) ($this->deferred ? [] : $this->resolvedViewData()),
            'deferred' => $this->deferred,
            'lazy' => $this->lazy,
            'deferredEndpoint' => $this->deferredEndpoint,
            'loadingMessage' => $this->loadingMessage,
            'errorMessage' => $this->errorMessage,
            'retryable' => $this->retryable,
        ];
    }

    /**
     * @param array<string, mixed> $named
     * @param array<class-string, object> $typed
     * @return array<string, mixed>
     */
    private function resolvedViewData(array $named = [], array $typed = []): array
    {
        $data = $this->viewData;

        if ($data instanceof Closure) {
            $context = $this->schemaContext ?? SchemaContext::make();
            $data = $this->evaluate($data, [
                ...$named,
                'component' => $this,
                'context' => $context,
                'data' => $context->state,
                'get' => $context->get(...),
                'operation' => $context->operation,
                'record' => $context->record,
                'state' => $context->state,
            ], [
                ...$typed,
                Component::class => $this,
                self::class => $this,
                SchemaContext::class => $context,
            ], [$context, $this]);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new UnexpectedValueException('Schema view data must resolve to an associative array.');
        }

        $this->assertWireValue($data, 'data');

        return $data;
    }

    private function assertViewName(string $view): void
    {
        if (preg_match(self::VIEW_PATTERN, $view) !== 1) {
            throw new InvalidArgumentException("Invalid schema view name [{$view}]. Use a lowercase package-style name such as [acme/order-summary].");
        }
    }

    private function assertWireValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException("Schema view data [{$path}] exceeds the maximum nesting depth.");
        }

        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("Schema view data [{$path}] contains a non-finite number.");
            }

            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Schema view data [{$path}] must contain only JSON-compatible values.");
        }

        foreach ($value as $key => $item) {
            if (! is_int($key) && ! is_string($key)) {
                throw new InvalidArgumentException("Schema view data [{$path}] contains an unsupported key.");
            }

            $this->assertWireValue($item, $path.'.'.$key, $depth + 1);
        }
    }
}
