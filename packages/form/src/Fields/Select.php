<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inlay\Forms\Concerns\HasOptions;
use Inlay\Forms\Field;
use Inlay\Forms\Form;
use Inlay\Schemas\Component as SchemaComponent;
use Inlay\Support\ClosureEvaluator;

final class Select extends Field
{
    use HasOptions;

    private bool $multiple = false;

    private bool $searchable = false;

    private ?bool $native = null;

    private bool $preload = false;

    private int $searchDebounce = 1000;

    private int $optionsLimit = 50;

    private string $loadingMessage = 'Loading options…';

    private string $noSearchResultsMessage = 'No results found.';

    private string $noOptionsMessage = 'No options available.';

    private string $searchPrompt = 'Type to search…';

    private string $searchingMessage = 'Searching…';

    private ?Closure $getSearchResultsUsing = null;

    private ?Closure $getOptionLabelUsing = null;

    private ?Closure $getOptionLabelsUsing = null;

    private ?string $remoteOptionsEndpoint = null;

    /** @var array<string|int, string> */
    private array $selectedOptionLabels = [];

    /** @var array{name: string, titleAttribute: string, type: 'belongsTo'|'belongsToMany'}|null */
    private ?array $relationship = null;

    private ?Closure $modifyRelationshipQueryUsing = null;

    /** @var array<string, mixed>|Closure|null */
    private array|Closure|null $pivotData = null;

    /** @var list<SchemaComponent>|Closure|null */
    private array|Closure|null $createOptionForm = null;

    private ?Closure $createOptionUsing = null;

    /** @var list<SchemaComponent>|Closure|null */
    private array|Closure|null $editOptionForm = null;

    private ?Closure $fillEditOptionActionFormUsing = null;

    private ?Closure $updateOptionUsing = null;

    private string $createOptionActionLabel = 'Create option';

    private string $editOptionActionLabel = 'Edit option';

    private string $createOptionModalHeading = 'Create option';

    private string $editOptionModalHeading = 'Edit option';

    private ?string $optionActionEndpoint = null;

    private string $optionActionMethod = 'post';

    private ?string $optionActionField = null;

    protected function type(): string
    {
        return 'select';
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function searchable(bool $searchable = true): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Choose the browser control this select renders.
     *
     * The native control cannot search, load options remotely, or host the
     * option create/edit forms, so those configurations refuse it rather than
     * silently rendering something that cannot do the job.
     */
    public function native(bool $native = true): self
    {
        $this->native = $native;

        return $this;
    }

    public function isNative(): bool
    {
        $requiresCustomControl = $this->searchable
            || $this->hasRemoteOptions()
            || $this->hasOptionAction('create')
            || $this->hasOptionAction('edit');

        if ($this->native === true && $requiresCustomControl) {
            throw new \LogicException("Select [{$this->name()}] cannot use the native control with searching, remote options, or option forms.");
        }

        return $this->native ?? ! $requiresCustomControl;
    }

    public function preload(bool $preload = true): self
    {
        $this->preload = $preload;

        return $this;
    }

    public function searchDebounce(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('Select search debounce must be zero or greater.');
        }

        $this->searchDebounce = $milliseconds;

        return $this;
    }

    public function optionsLimit(int $limit): self
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Select options limit must be between 1 and 500.');
        }

        $this->optionsLimit = $limit;

        return $this;
    }

    public function loadingMessage(string $message): self
    {
        $this->loadingMessage = $message;

        return $this;
    }

    public function noSearchResultsMessage(string $message): self
    {
        $this->noSearchResultsMessage = $message;

        return $this;
    }

    public function noOptionsMessage(string $message): self
    {
        $this->noOptionsMessage = $message;

        return $this;
    }

    public function searchPrompt(string $message): self
    {
        $this->searchPrompt = $message;

        return $this;
    }

    public function searchingMessage(string $message): self
    {
        $this->searchingMessage = $message;

        return $this;
    }

    public function getSearchResultsUsing(Closure $callback): self
    {
        $this->getSearchResultsUsing = $callback;
        $this->searchable = true;

        return $this;
    }

    public function getOptionLabelUsing(Closure $callback): self
    {
        $this->getOptionLabelUsing = $callback;

        return $this;
    }

    public function getOptionLabelsUsing(Closure $callback): self
    {
        $this->getOptionLabelsUsing = $callback;

        return $this;
    }

    public function relationship(string $name, string $titleAttribute, ?Closure $modifyQueryUsing = null): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Select relationship names must be valid PHP method names.');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $titleAttribute) !== 1) {
            throw new \InvalidArgumentException('Select relationship title attributes must be plain column names.');
        }

        $this->relationship = ['name' => $name, 'titleAttribute' => $titleAttribute, 'type' => 'belongsTo'];
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;

        return $this;
    }

    /** @param array<string, mixed>|Closure $data */
    public function pivotData(array|Closure $data): self
    {
        $this->pivotData = $data;

        return $this;
    }

    /** @param list<SchemaComponent>|Closure $schema */
    public function createOptionForm(array|Closure $schema): self
    {
        $this->assertOptionSchema($schema);
        $this->createOptionForm = $schema;

        return $this;
    }

    public function createOptionUsing(Closure $callback): self
    {
        $this->createOptionUsing = $callback;

        return $this;
    }

    /** @param list<SchemaComponent>|Closure $schema */
    public function editOptionForm(array|Closure $schema): self
    {
        $this->assertOptionSchema($schema);
        $this->editOptionForm = $schema;

        return $this;
    }

    public function fillEditOptionActionFormUsing(Closure $callback): self
    {
        $this->fillEditOptionActionFormUsing = $callback;

        return $this;
    }

    public function updateOptionUsing(Closure $callback): self
    {
        $this->updateOptionUsing = $callback;

        return $this;
    }

    public function createOptionActionLabel(string $label): self
    {
        $this->createOptionActionLabel = $this->nonEmpty($label, 'create option action label');

        return $this;
    }

    public function editOptionActionLabel(string $label): self
    {
        $this->editOptionActionLabel = $this->nonEmpty($label, 'edit option action label');

        return $this;
    }

    public function createOptionModalHeading(string $heading): self
    {
        $this->createOptionModalHeading = $this->nonEmpty($heading, 'create option modal heading');

        return $this;
    }

    public function editOptionModalHeading(string $heading): self
    {
        $this->editOptionModalHeading = $this->nonEmpty($heading, 'edit option modal heading');

        return $this;
    }

    public function configureOptionActions(?string $endpoint, string $method, string $field): void
    {
        $this->optionActionEndpoint = $endpoint;
        $this->optionActionMethod = $method;
        $this->optionActionField = $field;
    }

    public function hasOptionAction(string $action): bool
    {
        return match ($action) {
            'create' => $this->createOptionForm !== null && $this->createOptionUsing !== null,
            'edit' => $this->editOptionForm !== null && $this->fillEditOptionActionFormUsing !== null && $this->updateOptionUsing !== null,
            default => false,
        };
    }

    public function optionActionForm(string $action, mixed $value = null, ?Request $request = null, ?ValidationFactory $validationFactory = null): Form
    {
        if (! $this->hasOptionAction($action)) {
            throw new \InvalidArgumentException("Select [{$this->name()}] does not define the [{$action}] option action.");
        }
        if ($action === 'edit') {
            $valid = $value !== null && $value !== '' && $this->getOptionLabelUsing !== null
                && $this->evaluateSelectCallback($this->getOptionLabelUsing, compact('value', 'request'), [$value, $request, $this]) !== null;
            if (! $valid) {
                if ($validationFactory === null) {
                    throw new \LogicException('Validating an edit option form requires a Laravel validation factory.');
                }
                $validator = $validationFactory->make(
                    [$this->name() => $value],
                    [$this->name() => [fn (string $attribute, mixed $selected, Closure $fail) => $fail('The selected option is invalid.')]],
                );
                throw new ValidationException($validator);
            }
        }

        $schema = $this->resolveOptionSchema($action, $value, $request);
        $form = Form::make($this->name().'.'.$action.'-option')
            ->schema($schema)
            ->submitLabel($action === 'create' ? $this->createOptionActionLabel : $this->editOptionActionLabel);
        if ($this->optionActionEndpoint !== null) {
            $form->action($this->optionActionUrl($action, $value))->method($this->optionActionMethod);
        }
        if ($action === 'edit') {
            $data = $this->evaluateSelectCallback($this->fillEditOptionActionFormUsing, compact('value', 'request'), [$value, $request, $this]);
            if (! is_array($data)) {
                throw new \UnexpectedValueException('An edit option form fill callback must return an array.');
            }
            $form->data($data);
        }

        return $form;
    }

    /** @param array<string, mixed> $data @return array{value: string|int, label: string} */
    public function processOptionAction(string $action, array $data, mixed $value, Request $request, ValidationFactory $validationFactory): array
    {
        $form = $this->optionActionForm($action, $value, $request, $validationFactory);
        $prepared = $form->mutateStateForValidation($data);
        $validator = $validationFactory->make($prepared, $form->validationRules());
        $validator->addRules($form->remoteOptionValidationRules($request));
        $validated = $form->dehydrateState($validator->validate());

        if ($action === 'create') {
            $value = $this->evaluateSelectCallback($this->createOptionUsing, [
                'data' => $validated,
                'request' => $request,
            ], [$validated, $request, $this]);
            if (! is_string($value) && ! is_int($value)) {
                throw new \UnexpectedValueException('A create option callback must return the created option string or integer key.');
            }
        } else {
            $this->evaluateSelectCallback($this->updateOptionUsing, [
                'data' => $validated,
                'request' => $request,
                'value' => $value,
            ], [$value, $validated, $request, $this]);
        }

        if ($this->getOptionLabelUsing === null) {
            throw new \LogicException("Select [{$this->name()}] requires getOptionLabelUsing() to return option action results.");
        }
        $label = $this->evaluateSelectCallback($this->getOptionLabelUsing, compact('value', 'request'), [$value, $request, $this]);
        if (! is_string($label) || $label === '') {
            throw new \UnexpectedValueException('The saved option could not be resolved to a non-empty label.');
        }

        return ['value' => $value, 'label' => $label];
    }

    public function hasRelationship(): bool
    {
        return $this->relationship !== null;
    }

    /** @param Model|class-string<Model> $owner */
    public function bindRelationship(Model|string $owner): void
    {
        if ($this->relationship === null) {
            return;
        }

        $owner = is_string($owner) ? new $owner : $owner;
        $name = $this->relationship['name'];
        if (! method_exists($owner, $name)) {
            throw new \LogicException("Relationship [{$name}] does not exist on [".$owner::class.'].');
        }
        $relation = $owner->{$name}();
        if (! $relation instanceof BelongsTo && ! $relation instanceof BelongsToMany) {
            throw new \LogicException("Select relationship [{$name}] must be an Eloquent BelongsTo or BelongsToMany relationship.");
        }
        if ($relation instanceof BelongsTo && ! hash_equals($relation->getForeignKeyName(), $this->name())) {
            throw new \LogicException("Relationship select [{$this->name()}] must use the BelongsTo foreign key [{$relation->getForeignKeyName()}] as its field name.");
        }

        $isMany = $relation instanceof BelongsToMany;
        $this->relationship['type'] = $isMany ? 'belongsToMany' : 'belongsTo';
        if ($isMany) {
            $this->multiple = true;
        }

        $related = $relation->getRelated();
        $title = $this->relationship['titleAttribute'];
        $query = function (?Request $request = null) use ($related): Builder {
            $builder = $related->newQuery();
            if ($this->modifyRelationshipQueryUsing !== null) {
                $modified = $this->evaluateSelectCallback($this->modifyRelationshipQueryUsing, [
                    'query' => $builder,
                    'request' => $request,
                ], [$builder, $request, $this]);
                if ($modified !== null && ! $modified instanceof Builder) {
                    throw new \UnexpectedValueException('A Select relationship query callback must return an Eloquent Builder or null.');
                }
                $builder = $modified ?? $builder;
            }

            return $builder;
        };
        $this->getSearchResultsUsing = fn (string $search, ?Request $request = null): array => $query($request)
            ->when($search !== '', fn (Builder $builder): Builder => $builder->where($title, 'like', '%'.$search.'%'))
            ->orderBy($title)
            ->limit($this->optionsLimit)
            ->pluck($title, $related->getKeyName())
            ->all();
        $this->getOptionLabelUsing = fn (string|int $value, ?Request $request = null): ?string => $query($request)
            ->whereKey($value)
            ->value($title);
        if ($isMany) {
            $this->getOptionLabelsUsing = fn (array $values, ?Request $request = null): array => $query($request)
                ->whereKey($values)
                ->pluck($title, $related->getKeyName())
                ->all();
        }

        if (! $this->searchable) {
            $this->options = $query()
                ->orderBy($title)
                ->limit($this->optionsLimit)
                ->pluck($title, $related->getKeyName())
                ->all();
        }
    }

    public function managesRelationshipPersistence(): bool
    {
        return ($this->relationship['type'] ?? null) === 'belongsToMany';
    }

    /** @param Model|class-string<Model> $owner @return list<string|int> */
    public function relationshipState(Model|string $owner): array
    {
        $this->bindRelationship($owner);
        if (! $this->managesRelationshipPersistence() || is_string($owner) || ! $owner->exists) {
            return [];
        }

        $name = $this->relationship['name'];
        $relation = $owner->{$name}();
        $ids = $relation->allRelatedIds()->values()->all();
        if ($ids === []) {
            return [];
        }

        return array_keys($this->evaluateSelectCallback($this->getOptionLabelsUsing, ['values' => $ids, 'request' => null], [$ids, null, $this]));
    }

    public function saveRelationship(Model $record, mixed $state): void
    {
        $this->bindRelationship($record);
        if (! $this->managesRelationshipPersistence()) {
            throw new \LogicException("Select [{$this->name()}] does not manage a BelongsToMany relationship.");
        }

        $values = array_values(array_unique(array_filter(
            is_array($state) ? $state : [],
            static fn (mixed $value): bool => is_string($value) || is_int($value),
        ), SORT_REGULAR));
        $name = $this->relationship['name'];
        $relation = $record->{$name}();
        $existing = $relation->allRelatedIds()->values()->all();
        $visibleExisting = $existing === [] ? [] : array_keys($this->evaluateSelectCallback($this->getOptionLabelsUsing, ['values' => $existing, 'request' => null], [$existing, null, $this]));
        $hiddenExisting = array_values(array_filter(
            $existing,
            static fn (string|int $id): bool => ! in_array((string) $id, array_map('strval', $visibleExisting), true),
        ));
        $sync = [...$hiddenExisting, ...$values];
        if ($this->pivotData !== null) {
            $sync = array_fill_keys($hiddenExisting, []);
            foreach ($values as $value) {
                $pivot = $this->pivotData instanceof Closure
                    ? $this->evaluateSelectCallback($this->pivotData, compact('value', 'record'), [$value, $record, $this])
                    : $this->pivotData;
                if (! is_array($pivot)) {
                    throw new \UnexpectedValueException('Select pivot data callbacks must return an array.');
                }
                $sync[$value] = $pivot;
            }
        }

        $relation->sync($sync);
    }

    public function hasRemoteOptions(): bool
    {
        return $this->getSearchResultsUsing !== null;
    }

    public function remoteOptionsEndpoint(string $endpoint): void
    {
        $this->remoteOptionsEndpoint = $endpoint;
    }

    public function resolveSelectedOptions(mixed $value, ?Request $request = null): void
    {
        $this->selectedOptionLabels = [];
        if (! $this->hasRemoteOptions() || $value === null || $value === '' || $value === []) {
            return;
        }
        if ($this->multiple ? $this->getOptionLabelsUsing === null : $this->getOptionLabelUsing === null) {
            throw new \LogicException("Remote select [{$this->name()}] requires a selected-option label resolver.");
        }

        $resolved = $this->multiple
            ? $this->evaluateSelectCallback($this->getOptionLabelsUsing, ['values' => array_values((array) $value), 'request' => $request], [array_values((array) $value), $request, $this])
            : (($label = $this->evaluateSelectCallback($this->getOptionLabelUsing, compact('value', 'request'), [$value, $request, $this])) === null ? [] : [$value => $label]);

        $this->selectedOptionLabels = $this->normalizeOptions($resolved, 'selected option labels');
    }

    /** @return list<array{value: string|int, label: string}> */
    public function searchOptions(string $search, ?Request $request = null): array
    {
        if ($this->getSearchResultsUsing === null) {
            throw new \LogicException("Select [{$this->name()}] does not have a remote search provider.");
        }

        $options = $this->normalizeOptions($this->evaluateSelectCallback($this->getSearchResultsUsing, compact('search', 'request'), [$search, $request, $this]), 'search results');

        return $this->serializeOptionMap(array_slice($options, 0, $this->optionsLimit, true));
    }

    public function hasValidSelection(mixed $value, ?Request $request = null): bool
    {
        if (! $this->hasRemoteOptions()) {
            return true;
        }
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if ($this->multiple) {
            if ($this->getOptionLabelsUsing === null || ! is_array($value)) {
                return false;
            }
            $values = array_values($value);
            $labels = $this->normalizeOptions($this->evaluateSelectCallback($this->getOptionLabelsUsing, compact('values', 'request'), [$values, $request, $this]), 'selected option labels');
            $valid = array_map('strval', array_keys($labels));

            return array_diff(array_map('strval', $value), $valid) === [];
        }

        return $this->getOptionLabelUsing !== null
            && $this->evaluateSelectCallback($this->getOptionLabelUsing, compact('value', 'request'), [$value, $request, $this]) !== null;
    }

    public function jsonSerialize(): array
    {
        if ($this->hasRemoteOptions() && ($this->multiple ? $this->getOptionLabelsUsing === null : $this->getOptionLabelUsing === null)) {
            throw new \LogicException("Remote select [{$this->name()}] requires a selected-option label resolver.");
        }
        if (($this->hasOptionAction('create') || $this->hasOptionAction('edit')) && $this->getOptionLabelUsing === null) {
            throw new \LogicException("Select [{$this->name()}] option actions require getOptionLabelUsing() or relationship().");
        }

        return [
            ...parent::jsonSerialize(),
            'options' => $this->serializeOptionMap($this->selectedOptionLabels + $this->resolvedOptions()),
            'multiple' => $this->multiple,
            'searchable' => $this->searchable,
            'native' => $this->isNative(),
            'relationship' => $this->relationship,
            'hasPivotData' => $this->pivotData !== null,
            'optionActions' => $this->serializeOptionActions(),
            'remoteOptions' => $this->hasRemoteOptions() ? [
                'endpoint' => $this->remoteOptionsEndpoint,
                'preload' => $this->preload,
                'searchDebounce' => $this->searchDebounce,
                'optionsLimit' => $this->optionsLimit,
                'loadingMessage' => $this->loadingMessage,
                'noSearchResultsMessage' => $this->noSearchResultsMessage,
                'noOptionsMessage' => $this->noOptionsMessage,
                'searchPrompt' => $this->searchPrompt,
                'searchingMessage' => $this->searchingMessage,
            ] : null,
        ];
    }

    /** @return array<string|int, string> */
    private function normalizeOptions(mixed $options, string $source): array
    {
        if (! is_array($options)) {
            throw new \UnexpectedValueException("Select {$source} must return an array of value => label pairs.");
        }
        foreach ($options as $value => $label) {
            if ((! is_string($value) && ! is_int($value)) || ! is_string($label)) {
                throw new \UnexpectedValueException("Select {$source} must contain only string or integer values and string labels.");
            }
        }

        return $options;
    }

    /** @param array<string|int, string> $options @return list<array{value: string|int, label: string}> */
    private function serializeOptionMap(array $options): array
    {
        return array_map(
            fn (string|int $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($options),
            array_values($options),
        );
    }

    /** @return array{create: array<string, mixed>|null, edit: array<string, mixed>|null} */
    private function serializeOptionActions(): array
    {
        return [
            'create' => $this->hasOptionAction('create') ? [
                'label' => $this->createOptionActionLabel,
                'modalHeading' => $this->createOptionModalHeading,
                'endpoint' => $this->optionActionUrl('create'),
                'method' => $this->optionActionMethod,
                'form' => $this->optionActionForm('create'),
            ] : null,
            'edit' => $this->hasOptionAction('edit') ? [
                'label' => $this->editOptionActionLabel,
                'modalHeading' => $this->editOptionModalHeading,
                'endpoint' => $this->optionActionUrl('edit'),
                'method' => $this->optionActionMethod,
                'form' => null,
            ] : null,
        ];
    }

    private function optionActionUrl(string $action, mixed $value = null): ?string
    {
        if ($this->optionActionEndpoint === null) {
            return null;
        }
        $separator = str_contains($this->optionActionEndpoint, '?') ? '&' : '?';
        $url = $this->optionActionEndpoint.$separator.'_inlay_select_action='.$action.'&_inlay_field='.rawurlencode($this->optionActionField ?? $this->name());

        return $value === null ? $url : $url.'&value='.rawurlencode((string) $value);
    }

    /** @param list<SchemaComponent>|Closure $schema */
    private function assertOptionSchema(array|Closure $schema): void
    {
        if (is_array($schema)) {
            foreach ($schema as $component) {
                if (! $component instanceof SchemaComponent) {
                    throw new \InvalidArgumentException('Select option form schemas must contain schema components.');
                }
            }
        }
    }

    /** @return list<SchemaComponent> */
    private function resolveOptionSchema(string $action, mixed $value, ?Request $request): array
    {
        $schema = $action === 'create' ? $this->createOptionForm : $this->editOptionForm;
        $resolved = $schema instanceof Closure
            ? $this->evaluateSelectCallback($schema, compact('action', 'value', 'request'), [$value, $request, $this])
            : $schema;
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException('A Select option form schema callback must return an array.');
        }
        $this->assertOptionSchema($resolved);

        return array_values($resolved);
    }

    /** @param array<string, mixed> $named @param list<mixed> $positional */
    private function evaluateSelectCallback(Closure $callback, array $named, array $positional): mixed
    {
        return ClosureEvaluator::evaluate($callback, [
            'component' => $this,
            'field' => $this,
            ...$named,
        ], [self::class => $this], $positional);
    }

    private function nonEmpty(string $value, string $description): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("A {$description} cannot be empty.");
        }

        return $value;
    }
}
