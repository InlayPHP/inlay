<?php

declare(strict_types=1);

namespace Inlay\Actions;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Inlay\Actions\Enums\ActionSize;
use Inlay\Actions\Enums\ActionTriggerStyle;
use Inlay\Actions\Enums\IconPosition;
use Inlay\Schemas\Support\TextPresentation;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\Condition;
use Inlay\Support\SafeUrl;
use InvalidArgumentException;
use JsonSerializable;

class Action implements JsonSerializable
{
    /**
     * Optional renderer identity for repeated or nested action instances.
     *
     * This never participates in authorization or endpoint lookup: the
     * public action name remains the server identity. It only gives React and
     * Vue a stable key when the same action name is rendered more than once.
     */
    private ?string $instanceKey = null;

    private string|Closure|null $label = null;

    private string|Closure|null $url = null;

    private string $method = 'get';

    /** Render this action as a browser download link instead of an Inertia visit. */
    private bool $download = false;

    private string|Closure $color = 'default';

    private bool $requiresConfirmation = false;

    private string|Closure|null $icon = null;

    private ?string $modalHeading = null;

    private ?ActionModal $modal = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $arguments = [];

    private bool $modalSubmitVariant = false;

    private bool|string $cancelParentActions = false;

    private ?Closure $authorizeUsing = null;

    private ?Condition $visibleWhen = null;

    /** @var array<string, mixed>|Closure */
    private array|Closure $rules = [];

    /** @var array<string, string> */
    private array $messages = [];

    /** @var array<string, string> */
    private array $validationAttributes = [];

    /** @var list<Closure> */
    private array $beforeValidation = [];

    /** @var list<Closure> */
    private array $afterValidation = [];

    /** @var list<Closure> */
    private array $before = [];

    /** @var list<Closure> */
    private array $after = [];

    /** @var list<Closure> */
    private array $failure = [];

    private ?Closure $mutateDataUsing = null;

    private ?Closure $handler = null;

    /** @var list<mixed>|Closure|null */
    private array|Closure|null $formSchema = null;

    /** @var array<string, mixed>|Closure */
    private array|Closure $formData = [];

    /** @var list<Closure> */
    private array $beforeFormFilled = [];

    /** @var list<Closure> */
    private array $afterFormFilled = [];

    private string|Closure|null $successMessage = null;

    private string|Closure|null $failureMessage = null;

    /** @var list<array{record: string|int|null, reason: string|null}> */
    private array $recordFailures = [];

    private bool $databaseTransaction = false;

    private bool $executingLifecycle = false;

    private ?string $executionStatus = null;

    private ?string $executionMessage = null;

    protected function __construct(private readonly string $name) {}

    /**
     * Resolve a presentation value that may be closure-backed.
     *
     * Actions travel to the browser as data, so a callback runs here and only
     * its result is serialized.
     */
    private function resolvePresentation(string|Closure|null $value, string $property): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = $value instanceof Closure
            ? ClosureEvaluator::evaluate($value, ['action' => $this], [self::class => $this], [$this])
            : $value;

        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new \UnexpectedValueException("Action [{$this->name}] {$property} must resolve to a non-empty string.");
        }

        return $resolved;
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    /** A closure resolves when the action is serialized, never in the browser. */
    public function label(string|Closure $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Set a safe action target. A closure is resolved while the server builds
     * the contract, so renderer- or request-specific URLs never cross into the
     * browser as executable code.
     */
    public function url(string|Closure $url): static
    {
        $this->url = $url instanceof Closure ? $url : SafeUrl::from($url)->value();

        return $this;
    }

    public function method(string $method): static
    {
        $method = strtolower($method);

        if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
            throw new InvalidArgumentException("Unsupported action method [{$method}].");
        }

        $this->method = $method;

        return $this;
    }

    /**
     * Mark the action as a download trigger.
     *
     * The URL still belongs to the server and must be authorized there. This
     * flag only tells the Inlay renderers to let the browser handle the
     * response, which is required for streamed CSV/PDF exports.
     */
    public function download(bool $enabled = true): static
    {
        $this->download = $enabled;

        return $this;
    }

    /** A closure resolves when the action is serialized, never in the browser. */
    public function color(string|Closure $color): static
    {
        $this->color = $color;

        return $this;
    }

    protected IconPosition|string|Closure $iconPosition = IconPosition::Before;

    protected ActionSize|string|Closure $size = ActionSize::Medium;

    protected ActionTriggerStyle|string|Closure $triggerStyle = ActionTriggerStyle::Button;

    protected string|Closure|null $tooltip = null;

    protected string|int|Closure|null $badge = null;

    protected string|Closure $badgeColor = 'default';

    protected bool|Closure $outlined = false;

    protected bool|Closure $disabled = false;

    /** @var list<string>|Closure */
    protected array|Closure $keyBindings = [];

    public function requiresConfirmation(bool $required = true): static
    {
        $this->requiresConfirmation = $required;

        return $this;
    }

    /** A closure resolves when the action is serialized, never in the browser. */
    public function icon(string|Closure $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /** Which side of the label the icon sits on. */
    public function iconPosition(IconPosition|string|Closure $position): static
    {
        if (is_string($position) && IconPosition::tryFrom($position) === null) {
            throw new InvalidArgumentException("Unsupported action icon position [{$position}].");
        }

        $this->iconPosition = $position;

        return $this;
    }

    /**
     * How large the trigger is drawn.
     *
     * The vocabulary is the one Text and infolist entries already read, so a
     * button beside a heading is described in the same words as the heading.
     */
    public function size(ActionSize|string|Closure $size): static
    {
        if (is_string($size)) {
            TextPresentation::assertSize($size, 'action');
        }

        $this->size = $size;

        return $this;
    }

    /** A closure resolves when the action is serialized, never in the browser. */
    public function tooltip(string|Closure|null $tooltip): static
    {
        if (is_string($tooltip) && trim($tooltip) === '') {
            throw new InvalidArgumentException('An action tooltip cannot be empty.');
        }

        $this->tooltip = $tooltip;

        return $this;
    }

    /**
     * Draw the action as a compact badge when called without arguments, or
     * attach badge content to any trigger when a value is supplied.
     */
    public function badge(string|int|Closure|null $badge = null): static
    {
        if (func_num_args() === 0) {
            $this->triggerStyle = ActionTriggerStyle::Badge;

            return $this;
        }
        if (is_string($badge) && trim($badge) === '') {
            throw new InvalidArgumentException('An action badge cannot be empty.');
        }

        $this->badge = $badge;

        return $this;
    }

    public function badgeColor(string|Closure $color): static
    {
        if (is_string($color) && trim($color) === '') {
            throw new InvalidArgumentException('An action badge color cannot be empty.');
        }

        $this->badgeColor = $color;

        return $this;
    }

    public function triggerStyle(ActionTriggerStyle|string|Closure $style): static
    {
        if (is_string($style) && ActionTriggerStyle::tryFrom($style) === null) {
            throw new InvalidArgumentException("Unsupported action trigger style [{$style}].");
        }

        $this->triggerStyle = $style;

        return $this;
    }

    public function button(): static
    {
        return $this->triggerStyle(ActionTriggerStyle::Button);
    }

    public function link(): static
    {
        return $this->triggerStyle(ActionTriggerStyle::Link);
    }

    public function iconButton(): static
    {
        return $this->triggerStyle(ActionTriggerStyle::IconButton);
    }

    public function badgeTrigger(): static
    {
        return $this->triggerStyle(ActionTriggerStyle::Badge);
    }

    /** Draw the trigger as an outline rather than a filled button. */
    public function outlined(bool|Closure $outlined = true): static
    {
        $this->outlined = $outlined;

        return $this;
    }

    /** @param string|list<string>|Closure $bindings */
    public function keyBindings(string|array|Closure $bindings): static
    {
        if (is_string($bindings)) {
            $bindings = [$bindings];
        }
        if (is_array($bindings)) {
            $bindings = $this->normalizeKeyBindings($bindings);
        }

        $this->keyBindings = $bindings;

        return $this;
    }

    /**
     * Refuse the action in the browser without hiding it.
     *
     * This is presentation, not authorization: a disabled trigger still has to
     * be refused by `authorizeUsing()` on the server, because nothing stops a
     * visitor from posting to the endpoint anyway.
     */
    public function disabled(bool|Closure $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function modalHeading(string|Closure $heading): static
    {
        if (is_string($heading)) {
            $this->modalHeading = trim($heading);

            if ($this->modalHeading === '') {
                throw new InvalidArgumentException('An action modal heading cannot be empty.');
            }
        } else {
            $this->modalHeading = null;
        }

        $this->modal ??= ActionModal::make();
        $this->modal->heading($heading);
        $this->requiresConfirmation = true;

        return $this;
    }

    /** Record- and selection-aware modal description resolved when the modal mounts. */
    public function modalDescription(string|Closure $description): static
    {
        $this->modal ??= ActionModal::make();
        $this->modal->description($description);
        $this->requiresConfirmation = true;

        return $this;
    }

    public function modal(ActionModal $modal): static
    {
        $this->modal = $modal;
        $this->modalHeading = $modal->headingValue();
        $this->requiresConfirmation = true;

        return $this;
    }

    /** Present the action dialog as a full-height panel from the inline end. */
    public function slideOver(bool $enabled = true): static
    {
        $this->modal ??= ActionModal::make();
        $this->modal->slideOver($enabled);
        $this->requiresConfirmation = true;

        return $this;
    }

    public function stickyModalHeader(bool $enabled = true): static
    {
        $this->modal ??= ActionModal::make();
        $this->modal->stickyHeader($enabled);
        $this->requiresConfirmation = true;

        return $this;
    }

    public function stickyModalFooter(bool $enabled = true): static
    {
        $this->modal ??= ActionModal::make();
        $this->modal->stickyFooter($enabled);
        $this->requiresConfirmation = true;

        return $this;
    }

    /** compatible shortcut for configuring the owned modal. */
    public function modalWidth(string $width): static
    {
        $this->modal ??= ActionModal::make();
        $this->modal->width($width);
        $this->requiresConfirmation = true;

        return $this;
    }

    /**
     * Customize or hide the primary modal submit trigger.
     *
     * A callback receives the default footer action and may mutate and return
     * it, return a replacement action, or return false to hide the trigger.
     */
    public function modalSubmitAction(Action|Closure|bool|null $action = null): static
    {
        $this->modal ??= ActionModal::make();
        $this->modal->submitAction($this->resolveModalFooterAction(
            $action,
            Action::make('submit')->label($this->resolvedLabel())->color('primary'),
            'submit',
        ));
        $this->requiresConfirmation = true;

        return $this;
    }

    /** Customize or hide the modal cancel trigger. */
    public function modalCancelAction(Action|Closure|bool|null $action = null): static
    {
        $this->modal ??= ActionModal::make();
        $this->modal->cancelAction($this->resolveModalFooterAction(
            $action,
            Action::make('cancel')->label('Cancel'),
            'cancel',
        ));
        $this->requiresConfirmation = true;

        return $this;
    }

    /**
     * @param  list<Action>|Closure  $actions
     */
    public function extraModalFooterActions(array|Closure $actions): static
    {
        if ($actions instanceof Closure) {
            $actions = ClosureEvaluator::evaluate(
                $actions,
                ['action' => $this],
                [self::class => $this],
                [$this],
            );
        }
        if (! is_array($actions) || ! array_is_list($actions)) {
            throw new InvalidArgumentException('Extra modal footer actions must be a list of actions.');
        }
        foreach ($actions as $action) {
            if ($action instanceof Action) {
                $this->assertNestedActionIsAcyclic($action, [spl_object_id($this) => true]);
            }
        }

        $this->modal ??= ActionModal::make();
        $this->modal->extraFooterActions($actions);
        $this->requiresConfirmation = true;

        return $this;
    }

    /**
     * Build another submit trigger for the current modal.
     *
     * Its arguments are delivered separately from validated form data.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function makeModalSubmitAction(string $name, array $arguments = []): Action
    {
        return Action::make($name)
            ->color('primary')
            ->arguments($arguments)
            ->asModalSubmitVariant();
    }

    /**
     * Close parent modal actions after this independent nested action succeeds.
     *
     * Pass a parent name to close that action and every child between it and
     * the current action.
     */
    public function cancelParentActions(bool|string $parent = true): static
    {
        if (is_string($parent)) {
            $parent = trim($parent);
            if ($parent === '') {
                throw new InvalidArgumentException('A parent action name cannot be empty.');
            }
        }

        $this->cancelParentActions = $parent;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): static
    {
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Action data keys must be non-empty strings.');
            }
        }

        $this->data = $data;

        return $this;
    }

    /** @param array<string, mixed> $arguments */
    public function arguments(array $arguments): static
    {
        foreach (array_keys($arguments) as $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Action argument keys must be non-empty strings.');
            }
        }

        $this->arguments = $arguments;

        return $this;
    }

    public function authorizeUsing(Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Display this action only when the current row matches the safe,
     * serializable condition. Server-side authorization is still required.
     */
    public function visibleWhen(Condition $condition): static
    {
        $this->visibleWhen = $condition;

        return $this;
    }

    /** @param list<mixed>|Closure $schema */
    public function form(array|Closure $schema): static
    {
        if (is_array($schema) && ! array_is_list($schema)) {
            throw new InvalidArgumentException('An action form schema must be a list of components.');
        }

        $this->formSchema = $schema;
        $this->requiresConfirmation = true;

        return $this;
    }

    /** @param array<string, mixed>|Closure $data */
    public function fillForm(array|Closure $data): static
    {
        if (is_array($data) && $data !== [] && array_is_list($data)) {
            throw new InvalidArgumentException('Action form data must be an associative array.');
        }

        $this->formData = $data;

        return $this;
    }

    public function beforeFormFilled(Closure $callback): static
    {
        $this->beforeFormFilled[] = $callback;

        return $this;
    }

    public function afterFormFilled(Closure $callback): static
    {
        $this->afterFormFilled[] = $callback;

        return $this;
    }

    /** @param array<string, mixed>|Closure $rules */
    public function rules(array|Closure $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /** @param array<string, string> $messages */
    public function messages(array $messages): static
    {
        $this->messages = $messages;

        return $this;
    }

    /** @param array<string, string> $attributes */
    public function validationAttributes(array $attributes): static
    {
        $this->validationAttributes = $attributes;

        return $this;
    }

    public function beforeFormValidated(Closure $callback): static
    {
        $this->beforeValidation[] = $callback;

        return $this;
    }

    public function afterFormValidated(Closure $callback): static
    {
        $this->afterValidation[] = $callback;

        return $this;
    }

    public function mutateFormDataUsing(Closure $callback): static
    {
        $this->mutateDataUsing = $callback;

        return $this;
    }

    public function before(Closure $callback): static
    {
        $this->before[] = $callback;

        return $this;
    }

    public function action(Closure $callback): static
    {
        $this->handler = $callback;
        if ($this->method === 'get') {
            $this->method = 'post';
        }

        return $this;
    }

    public function after(Closure $callback): static
    {
        $this->after[] = $callback;

        return $this;
    }

    public function failure(Closure $callback): static
    {
        $this->failure[] = $callback;

        return $this;
    }

    public function successNotificationTitle(string|Closure|null $message): static
    {
        if (is_string($message) && trim($message) === '') {
            throw new InvalidArgumentException('An action success notification title cannot be empty.');
        }

        $this->successMessage = $message;

        return $this;
    }

    /**
     * Message used when a run skipped or failed records. It receives the same
     * utilities as every other callback plus the `$report` counts.
     */
    public function failureNotificationTitle(string|Closure|null $message): static
    {
        if (is_string($message) && trim($message) === '') {
            throw new InvalidArgumentException('An action failure notification title cannot be empty.');
        }

        $this->failureMessage = $message;

        return $this;
    }

    /**
     * Mark one record of the current run as failed. The lifecycle continues, and
     * the result reports the record alongside the successful ones.
     */
    public function reportRecordFailure(mixed $record, ?string $reason = null): void
    {
        if (! $this->executingLifecycle) {
            throw new \LogicException('Record failures may only be reported while an action lifecycle is executing.');
        }
        if ($reason !== null && trim($reason) === '') {
            throw new InvalidArgumentException('A record failure reason cannot be empty.');
        }

        $this->recordFailures[] = [
            'record' => self::recordKey($record),
            'reason' => $reason === null ? null : trim($reason),
        ];
    }

    /** @internal @return list<array{record: string|int|null, reason: string|null}> */
    public function reportedRecordFailures(): array
    {
        return $this->recordFailures;
    }

    /** @internal */
    public function failureMessageValue(): string|Closure|null
    {
        return $this->failureMessage;
    }

    /** @internal Resolve a stable key for a record of any supported shape. */
    public static function recordKey(mixed $record): string|int|null
    {
        if (is_string($record) || is_int($record)) {
            return $record;
        }
        if (is_object($record) && method_exists($record, 'getKey')) {
            $key = $record->getKey();

            return is_string($key) || is_int($key) ? $key : null;
        }
        if (is_object($record) && isset($record->id) && (is_string($record->id) || is_int($record->id))) {
            return $record->id;
        }
        if (is_array($record) && isset($record['id']) && (is_string($record['id']) || is_int($record['id']))) {
            return $record['id'];
        }

        return null;
    }

    public function databaseTransaction(bool $enabled = true): static
    {
        $this->databaseTransaction = $enabled;

        return $this;
    }

    public function halt(?string $message = null): void
    {
        $this->interruptLifecycle('halted', $message);
    }

    public function cancel(?string $message = null): void
    {
        $this->interruptLifecycle('cancelled', $message);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Set a stable renderer identity for this mounted action instance.
     *
     * Keep this value stable for the lifetime of a mounted action. It is
     * intentionally independent from the action name used by authorization,
     * routing, and lifecycle lookup.
     */
    public function instanceKey(string $key): static
    {
        $key = trim($key);

        if ($key === '' || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException('An action instance key must be a non-empty printable string.');
        }

        $this->instanceKey = $key;

        return $this;
    }

    public function methodValue(): string
    {
        return $this->method;
    }

    public function hasLifecycleHandler(): bool
    {
        return $this->handler !== null;
    }

    public function hasForm(): bool
    {
        return $this->formSchema !== null;
    }

    /** @internal */
    public function isModalSubmitVariant(): bool
    {
        return $this->modalSubmitVariant;
    }

    /** @internal @return list<Action> */
    public function nestedModalActions(): array
    {
        return $this->modal?->extraFooterActionObjects() ?? [];
    }

    /** @internal */
    public function defaultUrl(string $url): static
    {
        if ($this->url === null && $this->handler !== null) {
            $this->url($url);
        }

        return $this;
    }

    /** @internal */
    public function authorizationCallback(): ?Closure
    {
        return $this->authorizeUsing;
    }

    /** @internal @return list<mixed>|Closure */
    public function formSchemaDefinition(): array|Closure
    {
        return $this->formSchema ?? throw new \LogicException("Action [{$this->name}] does not define a form.");
    }

    /** @internal @return array<string, mixed>|Closure */
    public function formDataDefinition(): array|Closure
    {
        return $this->formData;
    }

    /** @internal @return list<Closure> */
    public function beforeFormFilledHooks(): array
    {
        return $this->beforeFormFilled;
    }

    /** @internal @return list<Closure> */
    public function afterFormFilledHooks(): array
    {
        return $this->afterFormFilled;
    }

    /** @internal */
    public function urlValue(): ?string
    {
        return $this->resolvedUrl();
    }

    /** @internal Whether an explicit URL (including a deferred closure) exists. */
    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    /** @internal @return array<string, mixed>|Closure */
    public function validationRules(): array|Closure
    {
        return $this->rules;
    }

    /** @internal @return array<string, string> */
    public function validationMessages(): array
    {
        return $this->messages;
    }

    /** @internal @return array<string, string> */
    public function validationAttributeLabels(): array
    {
        return $this->validationAttributes;
    }

    /** @internal @return list<Closure> */
    public function beforeValidationHooks(): array
    {
        return $this->beforeValidation;
    }

    /** @internal @return list<Closure> */
    public function afterValidationHooks(): array
    {
        return $this->afterValidation;
    }

    /** @internal */
    public function dataMutationCallback(): ?Closure
    {
        return $this->mutateDataUsing;
    }

    /** @internal @return list<Closure> */
    public function beforeHooks(): array
    {
        return $this->before;
    }

    /** @internal */
    public function lifecycleHandler(): Closure
    {
        return $this->handler ?? throw new \LogicException("Action [{$this->name}] has no lifecycle handler.");
    }

    /** @internal @return list<Closure> */
    public function afterHooks(): array
    {
        return $this->after;
    }

    /** @internal @return list<Closure> */
    public function failureHooks(): array
    {
        return $this->failure;
    }

    /** @internal */
    public function successMessageValue(): string|Closure|null
    {
        return $this->successMessage;
    }

    /** @internal */
    public function usesDatabaseTransaction(): bool
    {
        return $this->databaseTransaction;
    }

    /** @internal */
    public function beginLifecycleExecution(): void
    {
        if ($this->executingLifecycle) {
            throw new \LogicException("Action [{$this->name}] is already executing.");
        }

        $this->executingLifecycle = true;
        $this->executionStatus = null;
        $this->executionMessage = null;
        $this->recordFailures = [];
    }

    /** @internal */
    public function finishLifecycleExecution(): void
    {
        $this->executingLifecycle = false;
        $this->executionStatus = null;
        $this->executionMessage = null;
    }

    /** @internal */
    public function isLifecycleInterrupted(): bool
    {
        return $this->executionStatus !== null;
    }

    /** @internal */
    public function lifecycleStatus(): ?string
    {
        return $this->executionStatus;
    }

    /** @internal */
    public function lifecycleMessage(): ?string
    {
        return $this->executionMessage;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $icon = $this->resolvePresentation($this->icon, 'icon');

        return [
            'name' => $this->name,
            ...($this->instanceKey === null ? [] : ['instanceKey' => $this->instanceKey]),
            'label' => $this->resolvePresentation($this->label, 'label')
                ?? ucwords(str_replace(['_', '-'], ' ', $this->name)),
            'url' => $this->resolvedUrl(),
            'method' => $this->method,
            ...($this->download ? ['download' => true] : []),
            'color' => $this->resolvePresentation($this->color, 'color') ?? 'default',
            'requiresConfirmation' => $this->requiresConfirmation,
            'icon' => $icon,
            'iconPosition' => $this->resolvedIconPosition(),
            'size' => $this->resolvedSize(),
            'triggerStyle' => $this->resolvedTriggerStyle($icon),
            'tooltip' => $this->resolvePresentation($this->tooltip, 'tooltip'),
            'badge' => $this->resolvedBadge(),
            'badgeColor' => $this->resolvePresentation($this->badgeColor, 'badge color') ?? 'default',
            'outlined' => $this->resolvedBoolean($this->outlined, 'outlined'),
            'disabled' => $this->resolvedDisabled(),
            'keyBindings' => $this->resolvedKeyBindings(),
            'modalHeading' => $this->modalHeading,
            'modal' => $this->requiresConfirmation ? [
                ...($this->modal ?? ActionModal::make())->jsonSerialize(),
                'endpoint' => $this->modal?->isDynamic() === true ? $this->formEndpoint() : null,
            ] : null,
            'data' => (object) $this->data,
            'arguments' => (object) $this->arguments,
            ...($this->cancelParentActions === false ? [] : ['cancelParentActions' => $this->cancelParentActions]),
            ...($this->visibleWhen === null ? [] : ['visibleWhen' => $this->visibleWhen]),
            'lifecycle' => $this->handler !== null,
            'form' => $this->formSchema === null ? null : [
                'contract' => 'inlay.actions.form-trigger.v1',
                'endpoint' => $this->formEndpoint(),
                'method' => 'post',
            ],
        ];
    }

    private function resolvedLabel(): string
    {
        return $this->resolvePresentation($this->label, 'label')
            ?? ucwords(str_replace(['_', '-'], ' ', $this->name));
    }

    private function resolveModalFooterAction(Action|Closure|bool|null $configuration, Action $default, string $role): Action|false|null
    {
        if ($configuration === null || $configuration === true) {
            return $configuration === null ? null : $default;
        }
        if ($configuration === false || $configuration instanceof Action) {
            return $configuration;
        }

        $resolved = ClosureEvaluator::evaluate(
            $configuration,
            ['action' => $default],
            [self::class => $default],
            [$default],
        );
        if ($resolved === null) {
            return $default;
        }
        if ($resolved === false || $resolved instanceof Action) {
            return $resolved;
        }

        throw new InvalidArgumentException("Modal {$role} action callbacks must return an action, false, or null.");
    }

    private function asModalSubmitVariant(): static
    {
        $this->modalSubmitVariant = true;

        return $this;
    }

    /** @param array<int, true> $ancestors */
    private function assertNestedActionIsAcyclic(Action $action, array $ancestors): void
    {
        $id = spl_object_id($action);
        if (isset($ancestors[$id])) {
            throw new \LogicException("Action [{$this->name}] contains a recursive nested modal action reference.");
        }

        $ancestors[$id] = true;
        foreach ($action->nestedModalActions() as $nested) {
            $this->assertNestedActionIsAcyclic($nested, $ancestors);
        }
    }

    private function resolvedSize(): string
    {
        $size = $this->resolveEnumPresentation($this->size, 'size');
        TextPresentation::assertResolved($size, TextPresentation::SIZES, "action [{$this->name}] size");

        return $size;
    }

    private function resolvedIconPosition(): string
    {
        $position = $this->resolveEnumPresentation($this->iconPosition, 'icon position');
        if (IconPosition::tryFrom($position) === null) {
            throw new \UnexpectedValueException("Action [{$this->name}] icon position must resolve to before or after.");
        }

        return $position;
    }

    private function resolvedTriggerStyle(?string $icon): string
    {
        $style = $this->resolveEnumPresentation($this->triggerStyle, 'trigger style');
        if (ActionTriggerStyle::tryFrom($style) === null) {
            throw new \UnexpectedValueException("Action [{$this->name}] trigger style [{$style}] is unsupported.");
        }
        if ($style === ActionTriggerStyle::IconButton->value && $icon === null) {
            throw new \UnexpectedValueException("Action [{$this->name}] icon-button triggers require an icon.");
        }

        return $style;
    }

    private function resolvedBadge(): string|int|null
    {
        $badge = $this->badge instanceof Closure
            ? ClosureEvaluator::evaluate($this->badge, ['action' => $this], [self::class => $this], [$this])
            : $this->badge;

        if ($badge === null) {
            return null;
        }
        if (is_int($badge)) {
            return $badge;
        }
        if (! is_string($badge) || trim($badge) === '') {
            throw new \UnexpectedValueException("Action [{$this->name}] badge must resolve to a non-empty string or an integer.");
        }

        return $badge;
    }

    private function resolvedDisabled(): bool
    {
        return $this->resolvedBoolean($this->disabled, 'disabled');
    }

    private function resolvedBoolean(bool|Closure $value, string $property): bool
    {
        $resolved = $value instanceof Closure
            ? ClosureEvaluator::evaluate($value, ['action' => $this], [self::class => $this], [$this])
            : $value;

        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException("Action [{$this->name}] {$property} must resolve to a boolean.");
        }

        return $resolved;
    }

    private function resolveEnumPresentation(ActionSize|ActionTriggerStyle|IconPosition|string|Closure $value, string $property): string
    {
        $resolved = $value instanceof Closure
            ? ClosureEvaluator::evaluate($value, ['action' => $this], [self::class => $this], [$this])
            : $value;
        if ($resolved instanceof ActionSize || $resolved instanceof ActionTriggerStyle || $resolved instanceof IconPosition) {
            return $resolved->value;
        }
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new \UnexpectedValueException("Action [{$this->name}] {$property} must resolve to a supported string or enum.");
        }

        return $resolved;
    }

    /** @return list<string> */
    private function resolvedKeyBindings(): array
    {
        $bindings = $this->keyBindings instanceof Closure
            ? ClosureEvaluator::evaluate($this->keyBindings, ['action' => $this], [self::class => $this], [$this])
            : $this->keyBindings;
        if (is_string($bindings)) {
            $bindings = [$bindings];
        }
        if (! is_array($bindings)) {
            throw new \UnexpectedValueException("Action [{$this->name}] key bindings must resolve to a string or an array of strings.");
        }

        return $this->normalizeKeyBindings($bindings, \UnexpectedValueException::class);
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  class-string<\Throwable>  $exception
     * @return list<string>
     */
    private function normalizeKeyBindings(array $bindings, string $exception = InvalidArgumentException::class): array
    {
        $normalized = [];
        foreach ($bindings as $binding) {
            if (! is_string($binding)) {
                throw new $exception("Action [{$this->name}] key bindings must contain only strings.");
            }
            $parts = array_values(array_filter(array_map(
                static fn (string $part): string => match (strtolower(trim($part))) {
                    'cmd', 'command' => 'meta',
                    'control' => 'ctrl',
                    'option' => 'alt',
                    'esc' => 'escape',
                    default => strtolower(trim($part)),
                },
                explode('+', $binding),
            )));
            $key = array_pop($parts);
            $allowedModifiers = ['mod', 'ctrl', 'meta', 'alt', 'shift'];
            $validKey = is_string($key) && preg_match('/^(?:[a-z0-9]|f(?:[1-9]|1[0-2])|enter|escape|space|tab|backspace|delete|home|end|pageup|pagedown|arrowup|arrowdown|arrowleft|arrowright)$/', $key) === 1;
            $ambiguousPortableModifier = in_array('mod', $parts, true)
                && (in_array('ctrl', $parts, true) || in_array('meta', $parts, true));
            if (! $validKey || $ambiguousPortableModifier || count($parts) !== count(array_unique($parts)) || array_diff($parts, $allowedModifiers) !== []) {
                throw new $exception("Action [{$this->name}] has an invalid key binding [{$binding}].");
            }
            $normalized[] = implode('+', [...$parts, $key]);
        }

        return array_values(array_unique($normalized));
    }

    /** @internal Whether this action must mount before its modal can be shown. */
    public function requiresMount(): bool
    {
        return $this->hasForm() || ($this->modal?->isDynamic() ?? false);
    }

    /** @internal */
    public function modalDefinition(): ?ActionModal
    {
        return $this->modal;
    }

    /** @internal The endpoint an open action form uses for its sub-transports. */
    public function formEndpointValue(): ?string
    {
        return $this->formEndpoint();
    }

    /** @internal Derive the action form endpoint from an already record-bound URL. */
    public static function formEndpointUrl(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'_inlay_action_form=1';
    }

    private function formEndpoint(): ?string
    {
        $url = $this->resolvedUrl();

        return $url === null ? null : self::formEndpointUrl($url);
    }

    /** Resolve and validate a URL closure without transporting the closure. */
    private function resolvedUrl(): ?string
    {
        if ($this->url === null) {
            return null;
        }

        if (is_string($this->url)) {
            return $this->url;
        }

        $container = Container::getInstance();
        $request = $container->bound(Request::class) ? $container->make(Request::class) : null;
        $typed = [self::class => $this];
        if ($request instanceof Request) {
            $typed[Request::class] = $request;
        }
        $resolved = ClosureEvaluator::evaluate(
            $this->url,
            ['action' => $this, 'request' => $request],
            $typed,
            [$this],
        );

        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new \UnexpectedValueException("Action [{$this->name}] URL callbacks must return a string or null.");
        }

        return SafeUrl::from($resolved)->value();
    }

    private function interruptLifecycle(string $status, ?string $message): void
    {
        if (! $this->executingLifecycle) {
            throw new \LogicException('Actions may only be halted or cancelled while their lifecycle is executing.');
        }
        if ($message !== null && trim($message) === '') {
            throw new InvalidArgumentException('An action interruption message cannot be empty.');
        }

        $this->executionStatus = $status;
        $this->executionMessage = $message === null ? null : trim($message);
    }
}
