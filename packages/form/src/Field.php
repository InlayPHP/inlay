<?php

declare(strict_types=1);

namespace Inlay\Forms;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inlay\Actions\Action;
use Inlay\Forms\Support\Get;
use Inlay\Forms\Support\Set;
use Inlay\Schemas\SchemaContext;
use Inlay\Schemas\Support\SemanticColor;
use Inlay\Support\Condition;

abstract class Field extends Component
{
    protected function rendererCategory(): string
    {
        return 'field';
    }

    protected mixed $default = null;

    protected string|Closure|null $placeholder = null;

    protected string|Closure|null $helperText = null;

    protected string|Closure|null $hint = null;

    protected string|Closure|null $hintIcon = null;

    protected string|Closure|null $hintColor = null;

    protected bool|Closure $hiddenLabel = false;

    protected bool $required = false;

    protected ?Closure $requiredUsing = null;

    /**
     * An explicit required marker override.
     *
     * A field's validation requirement and its visual marker are separate
     * concerns in the documented contract. `null` preserves the usual behaviour where a
     * required field gets a marker; `false` can hide that marker, and `true`
     * can document a server-side rule without adding browser validation.
     */
    protected bool|Closure|null $markedAsRequired = null;

    protected bool $disabled = false;

    protected ?Closure $disabledUsing = null;

    protected ?Condition $requiredWhen = null;

    protected ?Condition $disabledWhen = null;

    /** @var array{mode: 'change'|'blur', debounce: int|null}|null */
    protected ?array $live = null;

    protected bool|Closure $autofocus = false;

    protected bool|Closure $readOnly = false;

    protected bool|Closure|null $dehydrated = null;

    protected bool|Closure $dehydratedWhenHidden = false;

    protected ?Closure $formatStateUsing = null;

    protected ?Closure $mutateStateForValidationUsing = null;

    protected ?Closure $dehydrateStateUsing = null;

    protected ?Closure $computedUsing = null;

    protected ?Closure $saveRelationshipUsing = null;

    protected int $saveRelationshipOrder = 0;

    /** @var list<Closure> */
    protected array $beforeStateUpdatedHooks = [];

    /** @var list<Closure> */
    protected array $afterStateUpdatedHooks = [];

    /** @var array{endpoint: string, method: 'post'|'put'|'patch'|'delete'}|null */
    protected ?array $stateUpdate = null;

    protected string|Closure|null $prefix = null;

    protected string|Closure|null $prefixIcon = null;

    protected string|Closure|null $suffix = null;

    protected string|Closure|null $suffixIcon = null;

    /** @var array<string, string> */
    protected array $extraInputAttributes = [];

    protected ?Closure $extraInputAttributesUsing = null;

    /** @var list<Action> */
    protected array $prefixActions = [];

    /** @var list<Action> */
    protected array $hintActions = [];

    protected bool|Closure|null $inlineLabel = null;

    /** @var list<Action> */
    protected array $suffixActions = [];

    /** @var list<string> */
    protected array $rules = [];

    /** @var list<array{rule: string, table: string|null, column: string|null, ignoreRecord: bool}> */
    protected array $modelRules = [];

    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function helperText(string|Closure|null $helperText): static
    {
        $this->helperText = $helperText;

        return $this;
    }

    /**
     * A short note beside the label, where helper text sits beneath the control.
     *
     * Use it for something the visitor needs while reading the label — a
     * character budget, a format, a unit — rather than an explanation, which is
     * what `helperText()` is for.
     */
    public function hint(string|Closure|null $hint): static
    {
        $this->hint = $hint;

        return $this;
    }

    public function hintIcon(string|Closure|null $icon): static
    {
        $this->hintIcon = $icon;

        return $this;
    }

    public function hintColor(string|Closure|null $color): static
    {
        if (is_string($color)) {
            SemanticColor::assert($color, 'field hint color');
        }

        $this->hintColor = $color;

        return $this;
    }

    /**
     * Hide the label visually while leaving it for assistive technology.
     *
     * A control still has to be named, so this never removes the label — it
     * only stops it taking a line, which is what a repeater row or a compact
     * filter needs.
     */
    public function hiddenLabel(bool|Closure $hidden = true): static
    {
        $this->hiddenLabel = $hidden;

        return $this;
    }

    public function required(bool|Closure $required = true): static
    {
        if ($required instanceof Closure) {
            $this->requiredUsing = $required;
        } else {
            $this->required = $required;
            $this->requiredUsing = null;
        }

        return $this;
    }

    /**
     * Show or hide the required marker without changing validation rules.
     *
     * This is useful for fields whose requirement is enforced by a central
     * Form Request or validation profile, as well as forms that intentionally
     * hide the asterisk on required fields.
     */
    public function markAsRequired(bool|Closure $required = true): static
    {
        $this->markedAsRequired = $required;

        return $this;
    }

    public function disabled(bool|Closure $disabled = true): static
    {
        if ($disabled instanceof Closure) {
            $this->disabledUsing = $disabled;
        } else {
            $this->disabled = $disabled;
            $this->disabledUsing = null;
        }

        return $this;
    }

    public function requiredWhen(Condition|string $path, mixed $value = true, string $operator = 'equals'): static
    {
        $this->requiredWhen = $path instanceof Condition ? $path : Condition::make($path, $value, $operator);

        return $this;
    }

    public function disabledWhen(Condition|string $path, mixed $value = true, string $operator = 'equals'): static
    {
        $this->disabledWhen = $path instanceof Condition ? $path : Condition::make($path, $value, $operator);

        return $this;
    }

    public function live(bool $onBlur = false, ?int $debounce = null): static
    {
        if ($debounce !== null && $debounce < 0) {
            throw new \InvalidArgumentException('Live debounce must be zero or greater.');
        }

        $this->live = ['mode' => $onBlur ? 'blur' : 'change', 'debounce' => $debounce];

        return $this;
    }

    public function debounce(int $milliseconds): static
    {
        return $this->live(debounce: $milliseconds);
    }

    /** A closure resolves against the current state, like every other guard. */
    public function autofocus(bool|Closure $autofocus = true): static
    {
        $this->autofocus = $autofocus;

        return $this;
    }

    /** A closure resolves against the current state, like every other guard. */
    public function readOnly(bool|Closure $readOnly = true): static
    {
        $this->readOnly = $readOnly;

        return $this;
    }

    public function dehydrated(bool|Closure $dehydrated = true): static
    {
        $this->dehydrated = $dehydrated;

        return $this;
    }

    public function saved(bool|Closure $saved = true): static
    {
        return $this->dehydrated($saved);
    }

    public function dehydratedWhenHidden(bool|Closure $dehydrated = true): static
    {
        $this->dehydratedWhenHidden = $dehydrated;

        return $this;
    }

    public function savedWhenHidden(bool|Closure $saved = true): static
    {
        return $this->dehydratedWhenHidden($saved);
    }

    public function formatStateUsing(Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function mutateStateForValidationUsing(Closure $callback): static
    {
        $this->mutateStateForValidationUsing = $callback;

        return $this;
    }

    public function dehydrateStateUsing(Closure $callback): static
    {
        $this->dehydrateStateUsing = $callback;

        return $this;
    }

    /**
     * Derive this field's value from the rest of the form state, in PHP.
     *
     * A computed field is read-only in the browser and its submitted value is
     * never trusted: the server recomputes it before validation, before
     * dehydration, and after every reactive state update.
     */
    public function computed(Closure $callback): static
    {
        $this->computedUsing = $callback;
        $this->readOnly = true;

        return $this;
    }

    public function isComputed(): bool
    {
        return $this->computedUsing !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function computeState(array $data, string $path): mixed
    {
        if ($this->computedUsing === null) {
            throw new \LogicException("Field [{$this->name()}] is not computed.");
        }

        $resolve = self::statedPathResolver($path);
        $get = new Get(static fn (string $target): mixed => Arr::get($data, $resolve($target)));
        $context = $this->schemaContext ?? SchemaContext::make($data);

        return $this->evaluate($this->computedUsing, [
            'component' => $this,
            'context' => $context,
            'data' => $data,
            'field' => $this,
            'get' => $get,
            'operation' => $context->operation,
            'path' => $path,
            'record' => $context->record,
            'state' => Arr::get($data, $path),
        ], [
            self::class => $this,
            Get::class => $get,
            SchemaContext::class => $context,
        ], [$get, $data, $this]);
    }

    /**
     * Resolve a sibling-relative path written inside a hook or computed value.
     *
     * @return Closure(string): string
     */
    private static function statedPathResolver(string $path): Closure
    {
        $parentPath = str_contains($path, '.') ? (string) substr($path, 0, (int) strrpos($path, '.')) : '';

        return static function (string $target) use ($parentPath): string {
            if (str_starts_with($target, '/')) {
                return ltrim($target, '/');
            }
            if ($parentPath !== '' && ! str_starts_with($target, $parentPath.'.')) {
                return $parentPath.'.'.$target;
            }

            return $target;
        };
    }

    /**
     * Write this field's relationship yourself.
     *
     * The callback replaces the built-in write entirely, which is the escape
     * hatch for a relationship Inlay does not model.
     */
    public function saveRelationshipUsing(Closure $callback): static
    {
        $this->saveRelationshipUsing = $callback;

        return $this;
    }

    /**
     * Order this field's relationship write against the others.
     *
     * Writes run from lowest to highest, so a relationship another one depends
     * on can be made to happen first. Fields default to 0 and keep their
     * declaration order.
     */
    public function saveRelationshipOrder(int $order): static
    {
        $this->saveRelationshipOrder = $order;

        return $this;
    }

    public function relationshipSaveOrder(): int
    {
        return $this->saveRelationshipOrder;
    }

    public function hasRelationshipSaveCallback(): bool
    {
        return $this->saveRelationshipUsing !== null;
    }

    /** @internal */
    public function runRelationshipSaveCallback(Model $record, mixed $state): void
    {
        if ($this->saveRelationshipUsing === null) {
            throw new \LogicException("Field [{$this->name()}] does not define a relationship save callback.");
        }

        $this->evaluate($this->saveRelationshipUsing, [
            'field' => $this,
            'record' => $record,
            'state' => $state,
        ], [
            self::class => $this,
            Model::class => $record,
            $record::class => $record,
        ], [$record, $state, $this]);
    }

    /**
     * Inspect or normalize an incoming value before it is committed.
     *
     * The hook receives the incoming `$state` and the current `$old` value and
     * may return a replacement value, or `null` to keep the incoming one. Set
     * writes apply to the same state the after hooks then observe.
     */
    public function beforeStateUpdated(Closure $callback): static
    {
        $this->beforeStateUpdatedHooks[] = $callback;
        $this->live ??= ['mode' => 'change', 'debounce' => null];

        return $this;
    }

    public function hasBeforeStateUpdatedHooks(): bool
    {
        return $this->beforeStateUpdatedHooks !== [];
    }

    /** Whether this field takes part in the reactive state-update transport. */
    public function hasStateUpdateHooks(): bool
    {
        return $this->hasBeforeStateUpdatedHooks() || $this->hasAfterStateUpdatedHooks();
    }

    /**
     * Run a server-side hook when this field changes in the browser.
     *
     * The hook may receive typed Get/Set utilities or the named `$get`/`$set`
     * callables. Registering a hook enables live change transport unless the
     * field already has an explicit live() configuration.
     */
    public function afterStateUpdated(Closure $callback): static
    {
        $this->afterStateUpdatedHooks[] = $callback;
        $this->live ??= ['mode' => 'change', 'debounce' => null];

        return $this;
    }

    public function clearAfterStateUpdatedHooks(): static
    {
        $this->afterStateUpdatedHooks = [];
        $this->beforeStateUpdatedHooks = [];
        $this->stateUpdate = null;

        return $this;
    }

    public function hasAfterStateUpdatedHooks(): bool
    {
        return $this->afterStateUpdatedHooks !== [];
    }

    /** @internal Configured by the owning Form host. */
    public function configureStateUpdate(?string $endpoint, string $method): static
    {
        if (! $this->hasStateUpdateHooks()) {
            return $this;
        }
        if ($endpoint === null) {
            $this->stateUpdate = null;

            return $this;
        }
        if (! in_array($method, ['post', 'put', 'patch', 'delete'], true)) {
            throw new \InvalidArgumentException("Unsupported state update method [{$method}].");
        }

        $this->stateUpdate = ['endpoint' => $endpoint, 'method' => $method];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function runAfterStateUpdated(
        mixed $state,
        mixed $old,
        array &$data,
        string $path,
        ?Request $request = null,
    ): array {
        return $this->runStateUpdateHooks($this->afterStateUpdatedHooks, $state, $old, $data, $path, $request)['patch'];
    }

    /**
     * Normalize an incoming value before it is committed to the form state.
     *
     * @param  array<string, mixed>  $data
     * @return array{state: mixed, patch: array<string, mixed>}
     */
    public function runBeforeStateUpdated(
        mixed $state,
        mixed $old,
        array &$data,
        string $path,
        ?Request $request = null,
    ): array {
        $result = $this->runStateUpdateHooks($this->beforeStateUpdatedHooks, $state, $old, $data, $path, $request, true);

        return ['state' => $result['state'], 'patch' => $result['patch']];
    }

    /**
     * @param  list<Closure>  $hooks
     * @param  array<string, mixed>  $data
     * @return array{state: mixed, patch: array<string, mixed>}
     */
    private function runStateUpdateHooks(
        array $hooks,
        mixed $state,
        mixed $old,
        array &$data,
        string $path,
        ?Request $request,
        bool $replaceState = false,
    ): array {
        $patch = [];
        $resolvePath = self::statedPathResolver($path);
        $get = new Get(static function (string $target) use (&$data, $resolvePath): mixed {
            return Arr::get($data, $resolvePath($target));
        });
        $set = new Set(function (string $target, mixed $value) use (&$data, &$patch, $resolvePath): void {
            $target = $resolvePath($target);
            if (preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $target) !== 1) {
                throw new \InvalidArgumentException("Invalid state patch path [{$target}].");
            }
            Arr::set($data, $target, $value);
            $patch[$target] = $value;
        });
        foreach ($hooks as $hook) {
            // Rebuild the context for every hook so later hooks observe state
            // written by an earlier hook through the Set utility.
            $context = SchemaContext::make($data, $this->schemaContext?->operation ?? 'default', $this->schemaContext?->record);
            $returned = $this->evaluate($hook, [
                'component' => $this,
                'context' => $context,
                'data' => $data,
                'field' => $this,
                'get' => $get,
                'old' => $old,
                'operation' => $context->operation,
                'path' => $path,
                'record' => $context->record,
                'request' => $request,
                'set' => $set,
                'state' => $state,
                'user' => $request?->user(),
            ], array_filter([
                self::class => $this,
                Get::class => $get,
                Set::class => $set,
                SchemaContext::class => $context,
                Request::class => $request,
            ]), [$state, $set, $get, $old, $this]);

            // A before hook may return a replacement value; returning null
            // keeps whatever the browser sent.
            if ($replaceState && $returned !== null) {
                $state = $returned;
            }
        }

        return ['state' => $state, 'patch' => $patch];
    }

    public function isDehydrated(
        ?SchemaContext $context = null,
        ?string $statePath = null,
        bool $hiddenByAncestor = false,
    ): bool {
        $context ??= $this->schemaContext ?? SchemaContext::make();
        $explicitlyConfigured = $this->dehydrated !== null;
        $dehydrated = $this->isConfiguredToDehydrate($context, $statePath);
        if (! $dehydrated) {
            return false;
        }
        if ($this->isDisabledForState($context, $statePath) && ! $explicitlyConfigured) {
            return false;
        }
        if (($hiddenByAncestor || $this->isHiddenForState($context, $statePath))
            && ! $this->isDehydratedWhenHidden($context, $statePath)) {
            return false;
        }

        return true;
    }

    public function hasInlineLabel(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        if ($this->inlineLabel !== null) {
            $context ??= $this->schemaContext ?? SchemaContext::make();
            $statePath ??= $this->statePath === '' ? null : $this->statePath;

            return $this->evaluateBoolean($this->inlineLabel, 'inlineLabel', $context, $statePath);
        }

        return parent::effectiveInlineLabel();
    }

    public function effectiveInlineLabel(): bool
    {
        return $this->hasInlineLabel();
    }

    public function hasHiddenLabel(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();

        return $this->evaluateBoolean($this->hiddenLabel, 'hiddenLabel', $context, $statePath);
    }

    public function isAutofocused(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();

        return $this->evaluateBoolean($this->autofocus, 'autofocus', $context, $statePath);
    }

    public function isReadOnly(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();

        return $this->evaluateBoolean($this->readOnly, 'readOnly', $context, $statePath);
    }

    public function isConfiguredToDehydrate(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();

        return $this->evaluateBoolean($this->dehydrated ?? true, 'saved', $context, $statePath);
    }

    public function isDehydratedWhenHidden(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();

        return $this->evaluateBoolean($this->dehydratedWhenHidden, 'savedWhenHidden', $context, $statePath);
    }

    public function isDehydratedWhenDisabled(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        return $this->dehydrated !== null && $this->isConfiguredToDehydrate($context, $statePath);
    }

    public function isDisabled(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();

        return $this->disabledUsing === null
            ? $this->disabled
            : $this->evaluateBoolean($this->disabledUsing, 'disabled', $context, $statePath);
    }

    public function isDisabledForState(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();

        return $this->disabledWhen?->matches($context->state)
            || $this->isDisabled($context, $statePath);
    }

    /** @param array<string, mixed> $data */
    public function hydrateState(mixed $state, array $data): mixed
    {
        return $this->formatStateUsing === null ? $state : $this->evaluateStateCallback($this->formatStateUsing, $state, $data);
    }

    /** @param array<string, mixed> $data */
    public function mutateStateForValidation(mixed $state, array $data): mixed
    {
        return $this->mutateStateForValidationUsing === null ? $state : $this->evaluateStateCallback($this->mutateStateForValidationUsing, $state, $data);
    }

    /** @param array<string, mixed> $data */
    public function dehydrateState(mixed $state, array $data): mixed
    {
        return $this->dehydrateStateUsing === null ? $state : $this->evaluateStateCallback($this->dehydrateStateUsing, $state, $data);
    }

    /** A closure resolves against the current state. */
    public function prefix(string|Closure $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /** An icon rendered before the field control, resolved through the host registry. */
    public function prefixIcon(string|Closure|null $icon): static
    {
        $this->prefixIcon = $icon;

        return $this;
    }

    /** A closure resolves against the current state. */
    public function suffix(string|Closure $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    /** An icon rendered after the field control, resolved through the host registry. */
    public function suffixIcon(string|Closure|null $icon): static
    {
        $this->suffixIcon = $icon;

        return $this;
    }

    /**
     * Decorate the rendered control with safe HTML attributes.
     *
     * These attributes are deliberately separate from extraAttributes(),
     * which decorates the field wrapper. A closure is evaluated on the server
     * so state-dependent aria labels and data hooks never cross the wire as
     * executable code.
     *
     * @param  array<string, scalar|null>|Closure  $attributes
     */
    public function extraInputAttributes(array|Closure $attributes, bool $merge = false): static
    {
        if ($attributes instanceof Closure) {
            $this->extraInputAttributesUsing = $attributes;

            return $this;
        }

        $safe = self::safeAttributes($attributes, $this->name().' input');
        $this->extraInputAttributes = $merge
            ? [...$this->extraInputAttributes, ...$safe]
            : $safe;

        return $this;
    }

    /**
     * Alias matching the documented field-wrapper terminology.
     *
     * Inlay's inherited extraAttributes() already targets the wrapper; this
     * method keeps the familiar API available without creating a second
     * wrapper payload or renderer path.
     *
     * @param  array<string, scalar|null>|Closure  $attributes
     */
    public function extraFieldWrapperAttributes(array|Closure $attributes): static
    {
        return $this->extraAttributes($attributes);
    }

    public function prefixAction(Action $action): static
    {
        $this->prefixActions[] = $action;

        return $this;
    }

    /** @param list<Action> $actions */
    public function prefixActions(array $actions): static
    {
        $this->prefixActions = $this->validateActions($actions);

        return $this;
    }

    /**
     * An action beside the label, next to the hint.
     *
     * Prefix and suffix actions sit inside the control; this one does not, so
     * it suits something about the field rather than about its value — "how do
     * I find this?", "generate one for me".
     */
    public function hintAction(Action $action): static
    {
        $this->hintActions[] = $action;

        return $this;
    }

    /** @param list<Action> $actions */
    public function hintActions(array $actions): static
    {
        $this->hintActions = $this->validateActions($actions);

        return $this;
    }

    /**
     * Put the label beside the control rather than above it.
     *
     * This is layout only: the label still names the control, unlike
     * `hiddenLabel()`, which keeps it for assistive technology alone.
     */
    public function inlineLabel(bool|Closure $inline = true): static
    {
        $this->inlineLabel = $inline;

        return $this;
    }

    public function suffixAction(Action $action): static
    {
        $this->suffixActions[] = $action;

        return $this;
    }

    /** @param list<Action> $actions */
    public function suffixActions(array $actions): static
    {
        $this->suffixActions = $this->validateActions($actions);

        return $this;
    }

    /**
     * Add Laravel's `unique` rule, resolving the table, column, and ignored
     * record from the form's model when they are not given.
     *
     * The generated rule always names an explicit table and column, so a
     * renamed field can never silently check the wrong one.
     */
    public function unique(?string $table = null, ?string $column = null, bool $ignoreRecord = false): static
    {
        $this->modelRules[] = [
            'rule' => 'unique',
            'table' => $table === null ? null : self::assertIdentifier($table, 'table'),
            'column' => $column === null ? null : self::assertIdentifier($column, 'column'),
            'ignoreRecord' => $ignoreRecord,
        ];

        return $this;
    }

    /**
     * Add Laravel's `exists` rule, resolving the table and column from the
     * form's model when they are not given.
     */
    public function exists(?string $table = null, ?string $column = null): static
    {
        $this->modelRules[] = [
            'rule' => 'exists',
            'table' => $table === null ? null : self::assertIdentifier($table, 'table'),
            'column' => $column === null ? null : self::assertIdentifier($column, 'column'),
            'ignoreRecord' => false,
        ];

        return $this;
    }

    /** @internal */
    final public function hasModelRules(): bool
    {
        return $this->modelRules !== [];
    }

    /**
     * @internal Materialize model-aware rules against the form's model.
     *
     * @param  Model|class-string<Model>|null  $model
     * @return list<string>
     */
    final public function resolveModelRules(Model|string|null $model): array
    {
        if ($this->modelRules === []) {
            return [];
        }

        $instance = is_string($model) ? new $model : $model;
        if (! $instance instanceof Model) {
            throw new \LogicException("Field [{$this->name}] uses a model-aware rule, so its Form needs model().");
        }

        $rules = [];
        foreach ($this->modelRules as $descriptor) {
            $table = $descriptor['table'] ?? $instance->getTable();
            $column = $descriptor['column'] ?? $this->name;
            $rule = "{$descriptor['rule']}:{$table},{$column}";

            if ($descriptor['rule'] === 'unique' && $descriptor['ignoreRecord']) {
                $key = $instance->exists ? $instance->getKey() : null;
                if ($key !== null) {
                    $rule .= ','.$key.','.$instance->getKeyName();
                }
            }

            $rules[] = $rule;
        }

        return $rules;
    }

    private static function assertIdentifier(string $value, string $context): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException("Model rule {$context} names must be simple identifiers.");
        }

        return $value;
    }

    public function rules(string ...$rules): static
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }

        return $this;
    }

    public function accepted(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'accepted');
    }

    public function alpha(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'alpha');
    }

    public function alphaDash(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'alpha_dash');
    }

    public function alphaNum(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'alpha_num');
    }

    public function ascii(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'ascii');
    }

    public function boolean(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'boolean');
    }

    public function confirmed(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'confirmed');
    }

    public function declined(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'declined');
    }

    public function different(string $field, bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'different:'.$this->validateRuleField($field));
    }

    public function email(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'email');
    }

    public function integer(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'integer');
    }

    public function ip(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'ip');
    }

    public function ipv4(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'ipv4');
    }

    public function ipv6(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'ipv6');
    }

    public function jsonValue(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'json');
    }

    public function length(int $length, bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'size:'.$this->positiveRuleInteger($length, 'Length'));
    }

    public function maxLength(int $length, bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'max:'.$this->positiveRuleInteger($length, 'Maximum length'));
    }

    public function maxValue(int|float $value, bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'max:'.$this->finiteRuleNumber($value, 'Maximum value'));
    }

    public function minLength(int $length, bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'min:'.$this->positiveRuleInteger($length, 'Minimum length'));
    }

    public function minValue(int|float $value, bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'min:'.$this->finiteRuleNumber($value, 'Minimum value'));
    }

    public function multipleOf(int|float $value, bool $condition = true): static
    {
        $value = $this->finiteRuleNumber($value, 'Multiple');
        if ((float) $value <= 0) {
            throw new \InvalidArgumentException('Multiple must be greater than zero.');
        }

        return $this->ruleWhen($condition, 'multiple_of:'.$value);
    }

    public function nullable(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'nullable');
    }

    public function numeric(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'numeric');
    }

    public function after(string $date, bool $orEqual = false, bool $condition = true): static
    {
        return $this->ruleWhen($condition, ($orEqual ? 'after_or_equal:' : 'after:').$this->ruleDate($date));
    }

    public function before(string $date, bool $orEqual = false, bool $condition = true): static
    {
        return $this->ruleWhen($condition, ($orEqual ? 'before_or_equal:' : 'before:').$this->ruleDate($date));
    }

    /**
     * Accept a date literal or another field name, never a rule fragment.
     *
     * Laravel treats `after:` arguments as either a date or a field, so the
     * value only has to be free of the separators that would smuggle in a
     * second rule.
     */
    private function ruleDate(string $date): string
    {
        $date = trim($date);
        if ($date === '' || preg_match('/^[A-Za-z0-9_:+\-\.\/ ]+$/', $date) !== 1) {
            throw new \InvalidArgumentException("Date validation requires a date literal or field name, received [{$date}].");
        }

        return $date;
    }

    public function regex(string $pattern, bool $condition = true): static
    {
        if (! $this->isValidRegex($pattern)) {
            throw new \InvalidArgumentException('Regex validation requires a valid PHP regular expression.');
        }

        return $this->ruleWhen($condition, 'regex:'.$pattern);
    }

    public function notRegex(string $pattern, bool $condition = true): static
    {
        if (! $this->isValidRegex($pattern)) {
            throw new \InvalidArgumentException('Not-regex validation requires a valid PHP regular expression.');
        }

        return $this->ruleWhen($condition, 'not_regex:'.$pattern);
    }

    public function requiredWith(string ...$fields): static
    {
        return $this->rules('required_with:'.$this->ruleFieldList($fields));
    }

    public function requiredWithAll(string ...$fields): static
    {
        return $this->rules('required_with_all:'.$this->ruleFieldList($fields));
    }

    public function requiredWithout(string ...$fields): static
    {
        return $this->rules('required_without:'.$this->ruleFieldList($fields));
    }

    public function requiredWithoutAll(string ...$fields): static
    {
        return $this->rules('required_without_all:'.$this->ruleFieldList($fields));
    }

    public function same(string $field, bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'same:'.$this->validateRuleField($field));
    }

    public function string(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'string');
    }

    public function ulid(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'ulid');
    }

    public function url(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'url');
    }

    public function uuid(bool $condition = true): static
    {
        return $this->ruleWhen($condition, 'uuid');
    }

    /** @return list<string> */
    public function validationRules(): array
    {
        $rules = $this->rules;

        if ($this->resolvedRequired() && ! in_array('required', $rules, true)) {
            array_unshift($rules, 'required');
        }

        if ($this->requiredWhen?->isLeaf() && $this->requiredWhen->operator() === 'equals' && is_scalar($this->requiredWhen->value())) {
            $conditionalRule = 'required_if:'.$this->requiredWhen->path().','.(string) $this->requiredWhen->value();

            if (! in_array($conditionalRule, $rules, true)) {
                array_unshift($rules, $conditionalRule);
            }
        }

        return $rules;
    }

    public function jsonSerialize(): array
    {
        if ($this->hasStateUpdateHooks() && $this->stateUpdate === null) {
            throw new \LogicException("Field [{$this->name()}] has state update hooks but its Form does not have an action.");
        }

        return [
            ...parent::jsonSerialize(),
            'default' => $this->evaluateFieldValue($this->default, 'default'),
            'placeholder' => $this->evaluateNullableString($this->placeholder, 'placeholder'),
            'helperText' => $this->evaluateNullableString($this->helperText, 'helper text'),
            'hint' => $this->evaluateNullableString($this->hint, 'hint'),
            'hintIcon' => $this->evaluateNullableString($this->hintIcon, 'hint icon'),
            'hintColor' => $this->resolvedHintColor(),
            'hiddenLabel' => $this->hasHiddenLabel(),
            'required' => $this->resolvedRequired(),
            'markedAsRequired' => $this->markedAsRequired === null ? null : $this->resolvedMarkedAsRequired(),
            'disabled' => $this->isDisabled(),
            'requiredWhen' => $this->requiredWhen,
            'disabledWhen' => $this->disabledWhen,
            'live' => $this->live === null ? null : [
                ...$this->live,
                ...($this->hasStateUpdateHooks() ? ['stateUpdate' => $this->stateUpdate] : []),
            ],
            'autofocus' => $this->isAutofocused(),
            'readOnly' => $this->isReadOnly(),
            'computed' => $this->isComputed(),
            'dehydrated' => $this->isConfiguredToDehydrate(),
            'dehydratedWhenHidden' => $this->isDehydratedWhenHidden(),
            'dehydratedWhenDisabled' => $this->isDehydratedWhenDisabled(),
            'prefix' => $this->evaluateNullableString($this->prefix, 'prefix'),
            'prefixIcon' => $this->evaluateNullableString($this->prefixIcon, 'prefix icon'),
            'suffix' => $this->evaluateNullableString($this->suffix, 'suffix'),
            'suffixIcon' => $this->evaluateNullableString($this->suffixIcon, 'suffix icon'),
            'extraInputAttributes' => (object) $this->resolvedExtraInputAttributes(),
            'prefixActions' => $this->prefixActions,
            'hintActions' => $this->hintActions,
            'inlineLabel' => $this->hasInlineLabel(),
            'suffixActions' => $this->suffixActions,
            'rules' => $this->validationRules(),
        ];
    }

    /** @param list<Action> $actions @return list<Action> */
    private function validateActions(array $actions): array
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \InvalidArgumentException('Field affix actions must extend '.Action::class.'.');
            }
        }

        return array_values($actions);
    }

    private function resolvedRequired(): bool
    {
        return $this->requiredUsing === null ? $this->required : $this->evaluateFieldGuard($this->requiredUsing, 'required');
    }

    private function resolvedMarkedAsRequired(): bool
    {
        if ($this->markedAsRequired === null) {
            return $this->resolvedRequired();
        }

        return $this->markedAsRequired instanceof Closure
            ? $this->evaluateFieldGuard($this->markedAsRequired, 'required marker')
            : $this->markedAsRequired;
    }

    /** @return array<string, string> */
    private function resolvedExtraInputAttributes(): array
    {
        if ($this->extraInputAttributesUsing === null) {
            return $this->extraInputAttributes;
        }

        $resolved = $this->evaluate($this->extraInputAttributesUsing);
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException("Field [{$this->name}] extra input attribute callbacks must return an array.");
        }

        return [...$this->extraInputAttributes, ...self::safeAttributes($resolved, $this->name().' input')];
    }

    private function addRule(string $rule): void
    {
        $rule = trim($rule);
        if ($rule === '' || preg_match('/[\x00-\x1F\x7F]/', $rule) === 1) {
            throw new \InvalidArgumentException('Validation rules must be non-empty strings without control characters.');
        }
        if (! in_array($rule, $this->rules, true)) {
            $this->rules[] = $rule;
        }
    }

    private function ruleWhen(bool $condition, string $rule): static
    {
        return $condition ? $this->rules($rule) : $this;
    }

    private function positiveRuleInteger(int $value, string $label): int
    {
        if ($value < 1) {
            throw new \InvalidArgumentException("{$label} must be at least 1.");
        }

        return $value;
    }

    private function finiteRuleNumber(int|float $value, string $label): string
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new \InvalidArgumentException("{$label} must be finite.");
        }

        return (string) $value;
    }

    private function isValidRegex(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function validateRuleField(string $field): string
    {
        $field = trim($field);
        if ($field === '' || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1) {
            throw new \InvalidArgumentException("Invalid validation field reference [{$field}].");
        }

        return $field;
    }

    /** @param list<string> $fields */
    private function ruleFieldList(array $fields): string
    {
        if ($fields === []) {
            throw new \InvalidArgumentException('At least one validation field reference is required.');
        }

        return implode(',', array_map($this->validateRuleField(...), $fields));
    }

    private function evaluateFieldGuard(Closure $guard, string $name): bool
    {
        $context = $this->schemaContext ?? SchemaContext::make();
        $get = new Get($context->get(...));
        $result = $this->evaluate($guard, [
            'component' => $this,
            'context' => $context,
            'field' => $this,
            'get' => $context->get(...),
            'operation' => $context->operation,
            'record' => $context->record,
            'state' => $context->get($this->name()),
        ], [
            self::class => $this,
            Get::class => $get,
            SchemaContext::class => $context,
        ], [$context, $this]);
        if (! is_bool($result)) {
            throw new \UnexpectedValueException("Field {$name} callbacks must return a boolean.");
        }

        return $result;
    }

    private function evaluateBoolean(
        bool|Closure $value,
        string $name,
        SchemaContext $context,
        ?string $statePath,
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        $get = new Get($context->get(...));
        $result = $this->evaluate($value, [
            'component' => $this,
            'context' => $context,
            'field' => $this,
            'get' => $context->get(...),
            'operation' => $context->operation,
            'record' => $context->record,
            'state' => $statePath === null ? $context->get($this->name()) : $context->get($statePath),
        ], [
            self::class => $this,
            Get::class => $get,
            SchemaContext::class => $context,
        ], [$context, $this]);
        if (! is_bool($result)) {
            throw new \UnexpectedValueException("Field {$name} callbacks must return a boolean.");
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function evaluateStateCallback(Closure $callback, mixed $state, array $data): mixed
    {
        $context = $this->schemaContext ?? SchemaContext::make($data);

        $get = new Get($context->get(...));

        return $this->evaluate($callback, [
            'component' => $this,
            'context' => $context,
            'data' => $data,
            'field' => $this,
            'get' => $context->get(...),
            'operation' => $context->operation,
            'record' => $context->record,
            'state' => $state,
        ], [
            self::class => $this,
            Get::class => $get,
            SchemaContext::class => $context,
        ], [$state, $data, $this]);
    }

    private function evaluateFieldValue(mixed $value, string $utility): mixed
    {
        $context = $this->schemaContext ?? SchemaContext::make();

        return $this->evaluate($value, [
            'state' => $context->get($this->name()),
            'utility' => $utility,
        ], [Get::class => new Get($context->get(...))]);
    }

    private function evaluateNullableString(string|Closure|null $value, string $utility): ?string
    {
        $resolved = $this->evaluateFieldValue($value, $utility);
        if ($resolved !== null && ! is_string($resolved)) {
            throw new \UnexpectedValueException("Field {$utility} callbacks must return a string or null.");
        }

        return $resolved;
    }

    private function resolvedHintColor(): ?string
    {
        $color = $this->evaluateNullableString($this->hintColor, 'hint color');
        if ($color !== null) {
            SemanticColor::assert($color, 'resolved field hint color', \UnexpectedValueException::class);
        }

        return $color;
    }
}
