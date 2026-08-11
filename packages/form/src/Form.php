<?php

declare(strict_types=1);

namespace Inlay\Forms;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;
use Inlay\Forms\Fields\Builder;
use Inlay\Forms\Fields\FileUpload;
use Inlay\Forms\Fields\MorphToSelect;
use Inlay\Forms\Fields\Repeater;
use Inlay\Forms\Fields\RichEditor;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TagsInput;
use Inlay\Forms\Relationships\ContainerRelationship;
use Inlay\Forms\Uploads\TemporaryUploadManager;
use Inlay\Schemas\Component as SchemaComponent;
use Inlay\Schemas\Components\View;
use Inlay\Schemas\Components\Wizard;
use Inlay\Schemas\Components\WizardStep;
use Inlay\Schemas\Schema;
use Inlay\Schemas\SchemaContext;
use Inlay\Support\Concerns\Configurable;
use Inlay\Support\SafeUrl;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\ValidationRunner;
use JsonSerializable;

final class Form implements JsonSerializable
{
    use Configurable;

    private Schema $schemaKernel;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, Field> */
    private array $computedFields = [];

    private ?string $action = null;

    private ?string $deferredViewEndpoint = null;

    private ?string $subRequestEndpoint = null;

    private string $method = 'post';

    private string $submitLabel = 'Save';

    /** @var Validation|class-string<Validation>|null */
    private Validation|string|null $validation = null;

    private string $validationOperation = 'default';

    private bool $mergeFieldRules = false;

    /** @var Model|class-string<Model>|null */
    private Model|string|null $model = null;

    /** @var array{transport: 'precognition', mode: 'change'|'blur', debounce: int|null}|null */
    private ?array $liveValidation = null;

    private function __construct(private readonly string $name)
    {
        $this->schemaKernel = Schema::make($name);
        $this->applyGlobalConfiguration();
    }

    public static function make(string $name = 'form'): self
    {
        return new self($name);
    }

    /** @param list<SchemaComponent>|Closure $components */
    public function schema(array|Closure $components): self
    {
        $this->schemaKernel->components($components);

        return $this;
    }

    /**
     * Decide component visibility in PHP instead of in the browser.
     *
     * Hidden components are left out of the payload entirely, and each reactive
     * state update republishes the schema, so what a visitor may see is decided
     * on the server every time it can change.
     */
    public function serverConditions(bool $enabled = true): self
    {
        $this->schemaKernel->serverConditions($enabled);

        return $this;
    }

    public function schemaKernel(): Schema
    {
        return $this->schemaKernel;
    }

    public function getField(string $name): ?Field
    {
        $component = $this->schemaKernel->getComponent($name);

        return $component instanceof Field ? $component : null;
    }

    /** @return array<string, Field> */
    public function getFields(): array
    {
        return array_filter(
            $this->schemaKernel->getFlatComponents(),
            static fn (SchemaComponent $component): bool => $component instanceof Field,
        );
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): self
    {
        $this->data = $data;
        $this->schemaKernel->state($data);

        return $this;
    }

    public function action(string $action): self
    {
        $this->action = SafeUrl::from($action)->value();

        return $this;
    }

    /**
     * Set the current authorized display route for automatic View::defer()
     * requests when it differs from the form mutation action.
     */
    public function deferredViewEndpoint(string $endpoint): self
    {
        $this->deferredViewEndpoint = SafeUrl::from($endpoint)->value();

        return $this;
    }

    /**
     * Route live state updates, uploads, option actions, remote options, and
     * deferred views to a dedicated endpoint when the form submits somewhere
     * else, as hosted action forms do.
     */
    public function subRequestEndpoint(string $endpoint): self
    {
        $this->subRequestEndpoint = SafeUrl::from($endpoint)->value();

        return $this;
    }

    /** The base every sub-transport endpoint is derived from. */
    private function subRequestBase(): ?string
    {
        return $this->subRequestEndpoint ?? $this->action;
    }

    public function method(string $method): self
    {
        $method = strtolower($method);

        if (! in_array($method, ['post', 'put', 'patch', 'delete'], true)) {
            throw new \InvalidArgumentException("Unsupported form method [{$method}].");
        }

        $this->method = $method;

        return $this;
    }

    /** @param int|array<string, int>|Closure $columns */
    public function columns(int|array|Closure $columns): self
    {
        $this->schemaKernel->columns($columns);

        return $this;
    }

    /** Display every field label beside its control, with per-field opt-out. */
    public function inlineLabel(bool|Closure $inline = true): self
    {
        $this->schemaKernel->inlineLabel($inline);

        return $this;
    }

    public function submitLabel(string $label): self
    {
        $this->submitLabel = $label;

        return $this;
    }

    /** @param Validation|class-string<Validation> $validation */
    public function validation(Validation|string $validation, string $operation = 'default'): self
    {
        if (is_string($validation) && ! is_subclass_of($validation, Validation::class)) {
            throw new \InvalidArgumentException("Validation class [{$validation}] must extend ".Validation::class.'.');
        }

        $this->validation = $validation;

        return $this->operation($operation);
    }

    public function operation(string $operation): self
    {
        $operation = trim($operation);
        if ($operation === '') {
            throw new \InvalidArgumentException('A form operation cannot be empty.');
        }

        $this->validationOperation = $operation;
        $this->schemaKernel->operation($operation);

        return $this;
    }

    public function mergeFieldRules(bool $merge = true): self
    {
        $this->mergeFieldRules = $merge;

        return $this;
    }

    /** @param Model|class-string<Model> $model */
    public function model(Model|string $model): self
    {
        if (is_string($model) && ! is_subclass_of($model, Model::class)) {
            throw new \InvalidArgumentException("Form model [{$model}] must be an Eloquent model class.");
        }

        $this->model = $model;
        $this->schemaKernel->model($model);

        return $this;
    }

    public function hasValidation(): bool
    {
        return $this->validation !== null;
    }

    public function precognitive(string $mode = 'blur', ?int $debounce = 350): self
    {
        if (! in_array($mode, ['change', 'blur'], true)) {
            throw new \InvalidArgumentException("Unsupported live validation mode [{$mode}].");
        }

        if ($debounce !== null && $debounce < 0) {
            throw new \InvalidArgumentException('Live validation debounce cannot be negative.');
        }

        $this->liveValidation = [
            'transport' => 'precognition',
            'mode' => $mode,
            'debounce' => $debounce,
        ];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function validator(
        ValidationRunner $validator,
        array $data,
        mixed $record = null,
        mixed $user = null,
        string $source = ValidationContext::SOURCE_FORM,
        array $options = [],
    ): Validator {
        if ($this->validation === null) {
            throw new \LogicException('Assign a validation class before creating a form validator.');
        }

        $this->configureRemoteSelects($this->components(), $data);
        $this->configureSchemaContext($this->components(), SchemaContext::make(
            $data,
            $this->validationOperation,
            $record ?? $this->model,
        ));

        $context = ValidationContext::make(
            operation: $this->validationOperation,
            source: $source,
            record: $record,
            user: $user,
            options: $options,
        );
        $resolved = $validator->make($this->validation, $data, $context);

        if ($this->mergeFieldRules) {
            $resolved->addRules($this->collectRules($this->components()));
        }

        $this->assertBuilderBlockLimits($this->components(), $data);
        $resolved->addRules($this->collectBuilderRules($this->components(), $data));
        $resolved->addRules($this->remoteOptionValidationRules(
            ($options['request'] ?? null) instanceof Request ? $options['request'] : null,
        ));

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function validate(
        ValidationRunner $validator,
        array $data,
        mixed $record = null,
        mixed $user = null,
        string $source = ValidationContext::SOURCE_FORM,
        array $options = [],
    ): array {
        $prepared = $this->mutateStateForValidation($data);
        $validated = $this->validator($validator, $prepared, $record, $user, $source, $options)->validate();

        return $this->dehydrateState($validated, $prepared);
    }

    /**
     * Validate only the fields owned by one Wizard step while retaining the
     * complete payload for conditional and cross-field Laravel rules.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function validateWizardStep(
        ValidationRunner $validationRunner,
        ValidationFactory $validationFactory,
        string $wizardName,
        string $stepName,
        array $data,
        mixed $record = null,
        mixed $user = null,
        array $options = [],
    ): ?string {
        [$wizard, $step] = $this->resolveWizardStep($wizardName, $stepName);
        if (! $step->shouldValidateBeforeNext($wizard->validatesSteps())) {
            throw new \LogicException("Wizard step [{$wizardName}.{$stepName}] is not configured for validation.");
        }

        $prepared = $this->mutateStateForValidation($data);
        $paths = $this->collectFieldPaths($step->childComponents());
        $options = [...$options, 'wizard' => $wizardName, 'step' => $stepName];
        $schemaContext = SchemaContext::make($prepared, $this->validationOperation, $record ?? $this->model);
        $this->configureSchemaContext($this->components(), $schemaContext);
        $validationContext = ValidationContext::make(
            operation: $this->validationOperation,
            source: ValidationContext::SOURCE_FORM,
            record: $record,
            user: $user,
            options: $options,
        )->withData($prepared);
        $utilities = [
            'context' => $validationContext,
            'data' => $prepared,
            'form' => $this,
            'get' => $schemaContext->get(...),
            'operation' => $this->validationOperation,
            'options' => $options,
            'record' => $record ?? $this->model,
            'request' => ($options['request'] ?? null) instanceof Request ? $options['request'] : null,
            'user' => $user,
            'validationContext' => $validationContext,
            'wizard' => $wizard,
        ];
        $step->runBeforeValidation($utilities);

        if ($this->validation !== null) {
            $validator = $this->validator(
                $validationRunner,
                $prepared,
                $record,
                $user,
                ValidationContext::SOURCE_FORM,
                $options,
            );
        } else {
            $validator = $validationFactory->make($prepared, [
                ...$this->collectRules($step->childComponents()),
                ...$this->remoteOptionValidationRules(
                    ($options['request'] ?? null) instanceof Request ? $options['request'] : null,
                ),
            ]);
        }

        $validator->setRules($this->rulesForFieldPaths($validator->getRules(), $paths));
        $validated = $validator->validate();
        $afterUtilities = [
            ...$utilities,
            'data' => $validator->getData(),
            'validated' => $validated,
        ];
        $step->runAfterValidation($afterUtilities);

        return $step->validationHaltMessage($afterUtilities);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function hydrateState(array $data): array
    {
        $this->configureSchemaContext($this->components(), SchemaContext::make($data, $this->validationOperation, $this->model));
        $data = $this->runSchemaLifecycle($this->components(), 'before-hydrate', $data);
        $data = $this->transformFields($data, fn (Field $field, mixed $state, array $root): mixed => $field->hydrateState($state, $root));
        $this->configureSchemaContext($this->components(), SchemaContext::make($data, $this->validationOperation, $this->model));

        return $this->runSchemaLifecycle($this->components(), 'after-hydrate', $data, childrenFirst: true);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function mutateStateForValidation(array $data): array
    {
        $data = $this->restoreProtectedState($data, $data);
        $data = $this->applyComputedState($data);
        $this->configureSchemaContext($this->components(), SchemaContext::make($data, $this->validationOperation, $this->model));

        return $this->transformFields($data, fn (Field $field, mixed $state, array $root): mixed => $field->mutateStateForValidation($state, $root));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function dehydrateState(array $data, ?array $evaluationState = null): array
    {
        $evaluationState ??= $data;
        $data = $this->restoreProtectedState($data, $evaluationState);
        $data = $this->applyComputedState($data);
        $this->configureSchemaContext($this->components(), SchemaContext::make($data, $this->validationOperation, $this->model));
        $data = $this->runSchemaLifecycle($this->components(), 'before-dehydrate', $data);
        $data = $this->transformFields(
            $data,
            fn (Field $field, mixed $state, array $root): mixed => $field->dehydrateState($state, $root),
            removeNonDehydrated: true,
            evaluationState: $evaluationState,
        );
        $this->configureSchemaContext($this->components(), SchemaContext::make($data, $this->validationOperation, $this->model));

        return $this->runSchemaLifecycle($this->components(), 'after-dehydrate', $data, childrenFirst: true);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function storeUploadedFiles(array $data, ?Request $request = null): array
    {
        return $this->transformFields(
            $data,
            fn (Field $field, mixed $state, array $root): mixed => $field instanceof FileUpload
                ? $field->storeUploadedState($state, $root, $request)
                : $state,
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function resolveTemporaryUploads(array $data, Request $request, TemporaryUploadManager $manager): array
    {
        return $this->transformFields(
            $data,
            function (Field $field, mixed $state, array $root, string $path) use ($request, $manager): mixed {
                if (! $field instanceof FileUpload || ! $field->usesTemporaryUploads()) {
                    return $state;
                }
                if (is_array($state) && array_is_list($state)) {
                    return array_map(fn (mixed $value): mixed => $manager->resolve($value, $path, $request, $field), $state);
                }

                return $manager->resolve($state, $path, $request, $field);
            },
        );
    }

    public function temporaryUploadField(string $path): FileUpload
    {
        $field = $this->findFileUpload($this->components(), $path);
        if ($field === null || ! $field->usesTemporaryUploads()) {
            throw new \InvalidArgumentException("Unknown temporary upload field [{$path}].");
        }

        return $field;
    }

    public function richEditorAttachmentField(string $path): RichEditor
    {
        $field = $this->richEditorField($path);
        if (! $field->usesFileAttachments()) {
            throw new \InvalidArgumentException("Unknown rich editor attachment field [{$path}].");
        }

        return $field;
    }

    public function richEditorField(string $path): RichEditor
    {
        $field = $this->findRichEditor($this->components(), $path);
        if ($field === null) {
            throw new \InvalidArgumentException("Unknown rich editor field [{$path}].");
        }

        return $field;
    }

    /** @return array{contract: string, view: string, name: string, data: object} */
    public function resolveDeferredView(string $name, Request $request): array
    {
        $view = $this->findSchemaView($this->components(), $name);
        if ($view === null || ! $view->isDeferred()) {
            throw new \InvalidArgumentException("Unknown deferred schema view [{$name}].");
        }

        $data = $this->hydrateRelationshipState($this->data);
        $data = $this->hydrateState($data);
        $this->configureSchemaContext($this->components(), SchemaContext::make(
            $data,
            $this->validationOperation,
            $this->model,
        ));

        return $view->resolveDeferredPayload([
            'request' => $request,
            'user' => $request->user(),
        ], [
            Request::class => $request,
        ]);
    }

    /**
     * Execute the server-only hooks for one browser-originated field change.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     contract: string,
     *     path: string,
     *     revision: int,
     *     patch: object,
     *     schemaPatches?: list<array<string, mixed>>
     * }
     */
    public function processStateUpdate(
        string $path,
        mixed $state,
        mixed $old,
        array $data,
        int $revision,
        Request $request,
    ): array {
        if (preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $path) !== 1) {
            throw new \InvalidArgumentException('The state update path is invalid.');
        }
        if ($revision < 1) {
            throw new \InvalidArgumentException('The state update revision must be a positive integer.');
        }
        if ($data !== [] && array_is_list($data)) {
            throw new \InvalidArgumentException('The state update data must be an associative array.');
        }

        $data = $this->restoreProtectedState($data, $data);
        $beforeData = $data;
        Arr::set($beforeData, $path, $old);
        $beforeSchema = $this->schemaSnapshot($beforeData);

        $field = $this->findStateUpdateField($this->components(), $path, data: $data);
        if ($field === null || ! $field->hasStateUpdateHooks()) {
            throw new \InvalidArgumentException("Unknown reactive field [{$path}].");
        }

        $candidateData = $data;
        Arr::set($candidateData, $path, $state);
        if ($this->isProtectedFieldPath($this->components(), $path, $candidateData)) {
            throw new \InvalidArgumentException("Reactive field [{$path}] is hidden or disabled.");
        }

        $updateContext = SchemaContext::make(
            $candidateData,
            $this->validationOperation,
            $this->model,
        );
        $this->configureSchemaContext($this->components(), $updateContext);
        $field->context($updateContext);

        // Before hooks see the incoming value while the state still holds the
        // old one, so they can normalize it before anything observes it.
        $incoming = $state;
        $before = $field->runBeforeStateUpdated($state, $old, $data, $path, $request);
        $state = $before['state'];
        Arr::set($data, $path, $state);

        $patch = $before['patch'];
        // A normalized value has to travel back, or the browser keeps the value
        // the server just rejected.
        if ($state !== $incoming) {
            $patch[$path] = $state;
        }
        $patch = [...$patch, ...$field->runAfterStateUpdated($state, $old, $data, $path, $request)];

        // Computed values are server-owned, so every reactive update republishes
        // the ones the change moved.
        $computed = $this->applyComputedState($data);
        foreach ($this->computedPaths($this->components(), $computed) as $computedPath) {
            $value = $this->getPathIfExists($computed, explode('.', $computedPath));
            if ($value !== $this->getPathIfExists($data, explode('.', $computedPath))) {
                $patch[$computedPath] = $value;
            }
        }
        $data = $computed;
        foreach ($patch as $patchPath => $value) {
            $this->assertStatePatchValue($value, $patchPath);
        }

        $schemaPatches = $this->diffSchemaSnapshots($beforeSchema, $this->schemaSnapshot($data));
        $response = [
            'contract' => 'inlay.forms.state-update.v1',
            'path' => $path,
            'revision' => $revision,
            'patch' => (object) $patch,
        ];
        if ($schemaPatches !== []) {
            $response['schemaPatches'] = $schemaPatches;
        }

        return $response;
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private function schemaSnapshot(array $data): array
    {
        $this->schemaKernel->context(SchemaContext::make(
            $data,
            $this->validationOperation,
            $this->model,
        ));
        $components = $this->components();
        $this->configureRemoteSelects($components, $data);
        $this->configureDeferredViews($components);

        /** @var mixed $snapshot */
        $snapshot = json_decode(
            json_encode($this->schemaKernel->serializedComponents(), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (! is_array($snapshot) || ($snapshot !== [] && ! array_is_list($snapshot))) {
            throw new \UnexpectedValueException('A serialized form schema must be a list of components.');
        }

        return $snapshot;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array<string, mixed>>
     */
    private function diffSchemaSnapshots(array $before, array $after): array
    {
        $patches = [];
        $this->diffSchemaComponentList($before, $after, $patches);

        return $patches;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  list<array<string, mixed>>  $patches
     */
    private function diffSchemaComponentList(
        array $before,
        array $after,
        array &$patches,
        ?string $parentKey = null,
        ?string $collection = null,
    ): void {
        $beforeKeys = array_map($this->schemaPatchKey(...), $before);
        $afterKeys = array_map($this->schemaPatchKey(...), $after);
        if ($beforeKeys !== $afterKeys) {
            $patches[] = $parentKey === null
                ? ['op' => 'replace-root', 'components' => $after]
                : ['op' => 'replace-children', 'key' => $parentKey, 'collection' => $collection, 'components' => $after];

            return;
        }

        foreach ($after as $index => $afterComponent) {
            $beforeComponent = $before[$index];
            [$beforeCollection, $beforeChildren] = $this->schemaPatchChildren($beforeComponent);
            [$afterCollection, $afterChildren] = $this->schemaPatchChildren($afterComponent);
            $beforeComparable = $beforeComponent;
            $afterComparable = $afterComponent;
            foreach (['schema', 'tabs', 'steps'] as $childCollection) {
                unset($beforeComparable[$childCollection], $afterComparable[$childCollection]);
            }

            if ($beforeComparable !== $afterComparable || $beforeCollection !== $afterCollection) {
                $patches[] = [
                    'op' => 'replace',
                    'key' => $this->schemaPatchKey($afterComponent),
                    'component' => $afterComponent,
                ];

                continue;
            }

            if ($afterCollection !== null) {
                $this->diffSchemaComponentList(
                    $beforeChildren,
                    $afterChildren,
                    $patches,
                    $this->schemaPatchKey($afterComponent),
                    $afterCollection,
                );
            }
        }
    }

    /** @param array<string, mixed> $component */
    private function schemaPatchKey(array $component): string
    {
        $key = $component['absoluteKey'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            throw new \UnexpectedValueException('Reactive schema components require an absolute key.');
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array{0: 'schema'|'tabs'|'steps'|null, 1: list<array<string, mixed>>}
     */
    private function schemaPatchChildren(array $component): array
    {
        foreach (['schema', 'tabs', 'steps'] as $collection) {
            if (! array_key_exists($collection, $component)) {
                continue;
            }
            $children = $component[$collection];
            if (! is_array($children) || ($children !== [] && ! array_is_list($children))) {
                throw new \UnexpectedValueException("Reactive schema collection [{$collection}] must be a list.");
            }

            return [$collection, $children];
        }

        return [null, []];
    }

    /** @param list<SchemaComponent> $components @param array<string, mixed> $data */
    private function isProtectedFieldPath(
        array $components,
        string $targetPath,
        array $data,
        string $prefix = '',
        bool $hiddenByAncestor = false,
    ): bool {
        $context = SchemaContext::make($data, $this->validationOperation, $this->model);

        foreach ($components as $component) {
            $path = $prefix.$component->name();
            $hidden = $hiddenByAncestor || $component->isHiddenForState(
                $context,
                $component instanceof Field ? $path : null,
            );
            if ($component instanceof Field && hash_equals($path, $targetPath)) {
                return $hidden || $component->isDisabledForState($context, $path);
            }

            if ($component instanceof Builder) {
                foreach ($this->selectedBuilderSchemas($component, $data, $path) as $selected) {
                    if ($this->isProtectedFieldPath(
                        $selected['components'],
                        $targetPath,
                        $data,
                        $selected['prefix'],
                        $hidden,
                    )) {
                        return true;
                    }
                }

                continue;
            }

            $nested = $component->childComponents();
            if ($nested === []) {
                continue;
            }
            if ($component instanceof Repeater || $component instanceof Builder) {
                $remainder = str_starts_with($targetPath, $path.'.')
                    ? substr($targetPath, strlen($path) + 1)
                    : '';
                $index = $remainder === '' ? null : strtok($remainder, '.');
                if ($index !== false && $index !== null && ctype_digit($index)) {
                    if ($this->isProtectedFieldPath(
                        $nested,
                        $targetPath,
                        $data,
                        $path.'.'.$index.'.',
                        $hidden,
                    )) {
                        return true;
                    }
                }

                continue;
            }
            if ($this->isProtectedFieldPath($nested, $targetPath, $data, $prefix, $hidden)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function validateWithFactory(ValidationFactory $factory, array $data, ?Request $request = null): array
    {
        $prepared = $this->mutateStateForValidation($data);
        $this->assertBuilderBlockLimits($this->components(), $prepared);
        $rules = [
            ...$this->validationRules(),
            ...$this->collectBuilderRules($this->components(), $prepared),
            ...$this->remoteOptionValidationRules($request),
        ];
        $validated = $factory->make($prepared, $rules)->validate();

        return $this->dehydrateState($validated, $prepared);
    }

    public function hasTemporaryUploads(): bool
    {
        return $this->containsTemporaryUpload($this->components());
    }

    /** @return array<string, list<string>> */
    public function validationRules(): array
    {
        $this->configureSchemaContext($this->components(), SchemaContext::make($this->data, $this->validationOperation, $this->model));

        return $this->collectRules($this->components());
    }

    public function jsonSerialize(): array
    {
        $data = $this->hydrateRelationshipState($this->data);
        $data = $this->hydrateState($data);
        $data = $this->applyComputedState($data);
        $components = $this->components();
        $this->configureRemoteSelects($components, $data);
        $this->configureDeferredViews($components);
        $serialized = $this->schemaKernel->serializedComponents();

        return [
            'contract' => 'inlay.forms.v1',
            'type' => 'form',
            'name' => $this->name,
            'action' => $this->action,
            'method' => $this->method,
            'columns' => $this->schemaKernel->getColumns(),
            'inlineLabel' => $this->schemaKernel->hasInlineLabel(),
            'submitLabel' => $this->submitLabel,
            'validation' => $this->validation === null ? null : [
                'mode' => $this->mergeFieldRules ? 'merge' : 'centralized',
                'operation' => $this->validationOperation,
                'live' => $this->liveValidation,
            ],
            'data' => (object) $data,
            'schema' => $serialized,
        ];
    }

    /** @param list<SchemaComponent> $components */
    private function configureSchemaContext(array $components, SchemaContext $context): void
    {
        $this->schemaKernel->context($context);
    }

    /** @return list<SchemaComponent> */
    private function components(): array
    {
        return $this->schemaKernel->getComponents();
    }

    /** @param list<SchemaComponent> $components */
    private function configureDeferredViews(array $components): void
    {
        foreach ($components as $component) {
            if ($component instanceof View && $component->isDeferred()) {
                $endpoint = null;
                $base = $this->deferredViewEndpoint ?? $this->subRequestBase();
                if ($base !== null) {
                    $separator = str_contains($base, '?') ? '&' : '?';
                    $endpoint = $base.$separator.'_inlay_view='.rawurlencode($component->name());
                }
                $component->configureDeferredEndpoint($endpoint);
            }

            if ($component->childComponents() !== []) {
                $this->configureDeferredViews($component->childComponents());
            }
        }
    }

    /** @param list<SchemaComponent> $components */
    private function findSchemaView(array $components, string $name): ?View
    {
        $matches = [];
        foreach ($components as $component) {
            if ($component instanceof View && hash_equals($component->name(), $name)) {
                $matches[] = $component;
            }
            if ($component->childComponents() !== []) {
                $nested = $this->findSchemaView($component->childComponents(), $name);
                if ($nested !== null) {
                    $matches[] = $nested;
                }
            }
        }

        if (count($matches) > 1) {
            throw new \InvalidArgumentException("Ambiguous deferred schema view [{$name}].");
        }

        return $matches[0] ?? null;
    }

    /**
     * @param  list<SchemaComponent>  $components
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function runSchemaLifecycle(
        array $components,
        string $phase,
        array $data,
        bool $childrenFirst = false,
        string $prefix = '',
    ): array
    {
        foreach ($components as $component) {
            $componentPath = $prefix.$component->name();
            if ($component instanceof Repeater) {
                if ($childrenFirst) {
                    $data = $this->runRepeaterLifecycle($component, $phase, $data, true, $componentPath);
                }
                $data = $component->runStateLifecycle($phase, $data);
                if (! $childrenFirst) {
                    $data = $this->runRepeaterLifecycle($component, $phase, $data, false, $componentPath);
                }

                continue;
            }

            $children = $component->childComponents();
            if ($childrenFirst && $children !== []) {
                $data = $this->runSchemaLifecycle(
                    $children,
                    $phase,
                    $data,
                    true,
                    $this->nestedPrefix($component, $prefix, $componentPath),
                );
            }
            $data = $component->runStateLifecycle($phase, $data);
            if (! $childrenFirst && $children !== []) {
                $data = $this->runSchemaLifecycle(
                    $children,
                    $phase,
                    $data,
                    false,
                    $this->nestedPrefix($component, $prefix, $componentPath),
                );
            }
        }

        return $data;
    }

    /**
     * Run lifecycle callbacks in active Repeater rows and selected Builder
     * block schemas. Builder extends Repeater, so it is checked first.
     *
     * @param  Repeater  $component
     * @param  array<string, mixed>  $data
     */
    private function runRepeaterLifecycle(
        Repeater $component,
        string $phase,
        array $data,
        bool $childrenFirst,
        string $path,
    ): array {
        $children = $component instanceof Builder
            ? []
            : $component->childComponents();

        if ($component instanceof Builder) {
            foreach ($this->selectedBuilderSchemas($component, $data, $path) as $selected) {
                $this->configureDynamicContext($selected['components'], $data, $selected['prefix']);
                $data = $this->runSchemaLifecycle(
                    $selected['components'],
                    $phase,
                    $data,
                    $childrenFirst,
                    $selected['prefix'],
                );
            }

            return $data;
        }

        if ($children === []) {
            return $data;
        }

        $items = $this->getPathIfExists($data, explode('.', $path));
        if (! is_array($items)) {
            return $data;
        }

        foreach (array_keys($items) as $index) {
            $data = $this->runSchemaLifecycle(
                $children,
                $phase,
                $data,
                $childrenFirst,
                $path.'.'.$index.'.',
            );
        }

        return $data;
    }

    /**
     * Separate relationship-managed state from model attributes before persistence.
     *
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, relationships: array<string, mixed>}
     */
    public function splitRelationshipData(array $data): array
    {
        $this->configureRemoteSelects($this->components(), $data);
        $attributes = $data;
        $relationships = [];

        foreach ($this->relationshipSelects($this->components()) as $path => $select) {
            if (! $select->managesRelationshipPersistence()) {
                continue;
            }
            $segments = explode('.', $path);
            if (! $this->pathExists($attributes, $segments)) {
                continue;
            }
            $relationships[$path] = $this->getPath($attributes, $segments);
            $this->forgetPath($attributes, $segments);
        }
        foreach ($this->relationshipRepeaters($this->components()) as $path => $repeater) {
            $segments = explode('.', $path);
            if (! $this->pathExists($attributes, $segments)) {
                continue;
            }
            $relationships[$path] = $this->getPath($attributes, $segments);
            $this->forgetPath($attributes, $segments);
        }
        foreach ($this->relationshipContainers($this->components()) as $path => $container) {
            $segments = explode('.', $path);
            if (! $this->pathExists($attributes, $segments)) {
                continue;
            }
            $relationships[$path] = $this->getPath($attributes, $segments);
            $this->forgetPath($attributes, $segments);
        }
        foreach ($this->morphToSelects($this->components()) as $path => $morphTo) {
            $segments = explode('.', $path);
            if (! $this->pathExists($attributes, $segments)) {
                continue;
            }
            $state = $this->getPath($attributes, $segments);
            $this->forgetPath($attributes, $segments);
            $attributes = [...$attributes, ...$morphTo->relationshipAttributes($this->model ?? throw new \LogicException('MorphTo fields require Form::model().'), $state)];
        }

        return ['attributes' => $attributes, 'relationships' => $relationships];
    }

    /** @param array<string, mixed> $relationships */
    public function saveRelationships(Model $record, array $relationships): void
    {
        $this->model($record);
        $this->configureRemoteSelects($this->components(), $relationships);
        $selects = $this->relationshipSelects($this->components());
        $repeaters = $this->relationshipRepeaters($this->components());
        $containers = $this->relationshipContainers($this->components());

        // Writes run in declared order so a relationship another one depends on
        // can be made to happen first.
        $relationships = $this->orderedRelationshipWrites($relationships, $selects, $repeaters);

        foreach ($relationships as $path => $state) {
            $field = $selects[$path] ?? $repeaters[$path] ?? null;
            if ($field instanceof Field && $field->hasRelationshipSaveCallback()) {
                $field->runRelationshipSaveCallback($record, $state);

                continue;
            }

            $container = $containers[$path] ?? null;
            if ($container !== null) {
                $container->save($record, $state);

                continue;
            }
            $select = $selects[$path] ?? null;
            if ($select !== null && $select->managesRelationshipPersistence()) {
                $select->saveRelationship($record, $state);

                continue;
            }
            $repeater = $repeaters[$path] ?? null;
            if ($repeater === null || ! $repeater->managesRelationshipPersistence()) {
                throw new \InvalidArgumentException("Unknown relationship field [{$path}].");
            }
            $repeater->saveRelationship($record, $state);
        }
    }

    /**
     * Sort relationship writes by their declared order, keeping the original
     * order for everything that shares one.
     *
     * @param  array<string, mixed>  $relationships
     * @param  array<string, Select>  $selects
     * @param  array<string, Repeater>  $repeaters
     * @return array<string, mixed>
     */
    private function orderedRelationshipWrites(array $relationships, array $selects, array $repeaters): array
    {
        $ordered = [];
        $position = 0;
        foreach ($relationships as $path => $state) {
            $field = $selects[$path] ?? $repeaters[$path] ?? null;
            $ordered[] = [
                'path' => $path,
                'state' => $state,
                'order' => $field instanceof Field ? $field->relationshipSaveOrder() : 0,
                'position' => $position++,
            ];
        }

        usort(
            $ordered,
            static fn (array $left, array $right): int => $left['order'] <=> $right['order']
                ?: $left['position'] <=> $right['position'],
        );

        $result = [];
        foreach ($ordered as $write) {
            $result[$write['path']] = $write['state'];
        }

        return $result;
    }

    /** @return list<array{value: string|int, label: string}> */
    public function searchSelectOptions(string $field, string $search, ?Request $request = null): array
    {
        $this->configureRemoteSelects($this->components(), $this->data);
        $select = $this->findSelect($this->components(), $field);
        if ($select === null) {
            throw new \InvalidArgumentException("Unknown remote select field [{$field}].");
        }

        return $select->searchOptions($search, $request);
    }

    /** @return list<array{value: string|int, label: string}> */
    public function searchMorphToOptions(string $field, string $type, string $search): array
    {
        $this->configureRemoteSelects($this->components(), $this->data);
        $morphTo = $this->findMorphToSelect($this->components(), $field);
        if ($morphTo === null) {
            throw new \InvalidArgumentException("Unknown MorphTo field [{$field}].");
        }

        return $morphTo->searchOptions($type, $search);
    }

    /** @return array<string, list<Closure>> */
    public function remoteOptionValidationRules(?Request $request = null): array
    {
        $this->configureRemoteSelects($this->components(), $this->data);

        return $this->collectRemoteOptionRules($this->components(), $request);
    }

    public function selectOptionActionForm(string $field, string $action, mixed $value = null, ?Request $request = null, ?ValidationFactory $validationFactory = null): self
    {
        $this->configureRemoteSelects($this->components(), $this->data);
        $select = $this->findSelect($this->components(), $field);
        if ($select === null) {
            throw new \InvalidArgumentException("Unknown select option action field [{$field}].");
        }

        return $select->optionActionForm($action, $value, $request, $validationFactory);
    }

    /** @param array<string, mixed> $data @return array{value: string|int, label: string} */
    public function processSelectOptionAction(
        string $field,
        string $action,
        array $data,
        mixed $value,
        Request $request,
        ValidationFactory $validationFactory,
    ): array {
        $this->configureRemoteSelects($this->components(), $this->data);
        $select = $this->findSelect($this->components(), $field);
        if ($select === null) {
            throw new \InvalidArgumentException("Unknown select option action field [{$field}].");
        }

        return $select->processOptionAction($action, $data, $value, $request, $validationFactory);
    }

    /** @return array{Wizard, WizardStep} */
    private function resolveWizardStep(string $wizardName, string $stepName): array
    {
        $wizards = [];
        $this->collectNamedWizards($this->components(), $wizardName, $wizards);
        if (count($wizards) !== 1) {
            $reason = $wizards === [] ? 'Unknown' : 'Ambiguous';

            throw new \InvalidArgumentException("{$reason} wizard [{$wizardName}].");
        }

        $wizard = $wizards[0];
        $steps = array_values(array_filter(
            $wizard->childComponents(),
            static fn (SchemaComponent $component): bool => $component instanceof WizardStep && $component->name() === $stepName,
        ));
        if (count($steps) !== 1) {
            throw new \InvalidArgumentException("Unknown wizard step [{$wizardName}.{$stepName}].");
        }

        return [$wizard, $steps[0]];
    }

    /** @param list<SchemaComponent> $components @param list<Wizard> $matches */
    private function collectNamedWizards(array $components, string $name, array &$matches): void
    {
        foreach ($components as $component) {
            if ($component instanceof Wizard && $component->name() === $name) {
                $matches[] = $component;
            }
            if ($component->childComponents() !== []) {
                $this->collectNamedWizards($component->childComponents(), $name, $matches);
            }
        }
    }

    /** @param list<SchemaComponent> $components @return list<string> */

    /**
     * Compose the state path prefix a component's children write under.
     *
     * Repeaters and Builders index their rows, a container bound to a state
     * path nests beneath it, and every other component stays transparent.
     */
    private function nestedPrefix(SchemaComponent $component, string $prefix, string $path): string
    {
        if ($component instanceof Repeater || $component instanceof Builder) {
            return $path.'.*.';
        }

        $segment = $component->getStatePathSegment();

        return $segment === null ? $prefix : $prefix.$segment.'.';
    }

    /**
     * Resolve the schemas selected by the current Builder state.
     *
     * Builder block schemas are intentionally not static children (different
     * rows may select different blocks), so every state-aware traversal must
     * expand the active `{ type, data }` items before walking their fields.
     * The returned prefix points at the block's `data` object and is concrete,
     * which keeps callbacks, computed values, and dehydrate rules scoped to
     * the row that owns them.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{components: list<SchemaComponent>, prefix: string, index: int}>
     */
    private function selectedBuilderSchemas(Builder $builder, array $data, string $path): array
    {
        $items = $this->getPathIfExists($data, explode('.', $path));
        if (! is_array($items)) {
            return [];
        }

        $definitions = $builder->blockDefinitions();
        $selected = [];
        foreach (array_keys($items) as $index) {
            $item = $items[$index] ?? null;
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            $block = is_string($type) ? ($definitions[$type] ?? null) : null;
            if ($block === null) {
                continue;
            }

            $selected[] = [
                'components' => $block->schemaComponents(),
                'prefix' => $path.'.'.$index.'.data.',
                'index' => (int) $index,
            ];
        }

        return $selected;
    }

    /**
     * Give a dynamic row's components the same relative state context that a
     * statically attached repeater schema receives. Field callbacks such as
     * `formatStateUsing(fn (Get $get) => $get('title'))` should read siblings
     * from the current block's `data` object, not from the form root.
     *
     * @param  list<SchemaComponent>  $components
     * @param  array<string, mixed>  $data
     */
    private function configureDynamicContext(array $components, array $data, string $prefix): void
    {
        $state = $this->getPathIfExists($data, explode('.', rtrim($prefix, '.')));
        $context = SchemaContext::make(
            is_array($state) ? $state : $data,
            $this->validationOperation,
            $this->model,
        );

        foreach ($components as $component) {
            $component->context($context);
            if ($component->childComponents() !== []) {
                $this->configureDynamicContext($component->childComponents(), $data, $prefix);
            }
        }
    }

    private function collectFieldPaths(array $components, string $prefix = ''): array
    {
        $paths = [];
        foreach ($components as $component) {
            $name = $prefix.$component->name();
            if ($component instanceof Field) {
                $paths[] = $name;
            }

            $children = $component->childComponents();
            if ($children !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $name);
                $paths = [...$paths, ...$this->collectFieldPaths($children, $nestedPrefix)];
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function rulesForFieldPaths(array $rules, array $paths): array
    {
        return array_filter(
            $rules,
            static function (mixed $_rules, string|int $key) use ($paths): bool {
                if (! is_string($key)) {
                    return false;
                }

                $normalized = preg_replace('/\.\d+(?=\.|$)/', '.*', $key) ?? $key;

                foreach ($paths as $path) {
                    if ($normalized === $path || str_starts_with($normalized, $path.'.')) {
                        return true;
                    }
                }

                return false;
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  list<SchemaComponent>  $components
     * @return array<string, list<string>>
     */
    private function collectRules(array $components, string $prefix = ''): array
    {
        $rules = [];

        foreach ($components as $component) {
            $name = $prefix.$component->name();

            if ($component instanceof Field) {
                // Model-aware rules resolve here: only the Form knows the record.
                $fieldRules = [
                    ...$component->validationRules(),
                    ...$component->resolveModelRules($this->model),
                ];
                if ($fieldRules !== []) {
                    $rules[$name] = $fieldRules;
                }
                // A field may also validate each item it holds, so Laravel
                // reports the failing entry rather than the whole field.
                if ($component instanceof TagsInput && $component->nestedValidationRules() !== []) {
                    $rules[$name.'.*'] = $component->nestedValidationRules();
                }
            }

            $nested = $component->childComponents();

            if ($nested !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $name);
                $rules = [...$rules, ...$this->collectRules($nested, $nestedPrefix)];
            }
        }

        return $rules;
    }

    /**
     * Builder rules depend on the submitted blocks, so they are resolved from
     * the payload rather than the static schema.
     *
     * @param  list<SchemaComponent>  $components
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    private function collectBuilderRules(array $components, array $data, string $prefix = ''): array
    {
        $rules = [];

        foreach ($components as $component) {
            $name = $prefix.$component->name();

            if ($component instanceof Builder) {
                $segments = explode('.', $name);
                $state = $this->pathExists($data, $segments) ? $this->getPath($data, $segments) : null;
                $rules = [...$rules, ...$component->stateRules($name, $state)];

                continue;
            }

            $nested = $component->childComponents();
            if ($nested !== []) {
                $rules = [...$rules, ...$this->collectBuilderRules($nested, $data, $component instanceof Repeater ? $name.'.*.' : $prefix)];
            }
        }

        return $rules;
    }

    /**
     * Reject payloads that use a block more often than it allows.
     *
     * @param  list<SchemaComponent>  $components
     * @param  array<string, mixed>  $data
     */
    private function assertBuilderBlockLimits(array $components, array $data, string $prefix = ''): void
    {
        foreach ($components as $component) {
            $name = $prefix.$component->name();

            if ($component instanceof Builder) {
                $segments = explode('.', $name);
                $exceeded = $component->exceededBlocks(
                    $this->pathExists($data, $segments) ? $this->getPath($data, $segments) : null,
                );
                if ($exceeded !== []) {
                    throw new \InvalidArgumentException(
                        "Builder [{$name}] exceeds the maximum items for block(s) [".implode(', ', $exceeded).'].',
                    );
                }

                // Selected block schemas may contain another Builder. Apply
                // its per-block limits using the concrete parent row path.
                foreach ($this->selectedBuilderSchemas($component, $data, $name) as $selected) {
                    $this->assertBuilderBlockLimits(
                        $selected['components'],
                        $data,
                        $selected['prefix'],
                    );
                }

                continue;
            }

            $nested = $component->childComponents();
            if ($nested !== []) {
                $this->assertBuilderBlockLimits($nested, $data, $component instanceof Repeater ? $name.'.*.' : $prefix);
            }
        }
    }

    /** @param list<SchemaComponent> $components @param array<string, mixed> $data */
    private function configureRemoteSelects(
        array $components,
        array $data,
        string $prefix = '',
        Model|string|null $modelContext = null,
        bool $configureTransport = true,
    ): void
    {
        $modelContext ??= $this->model;
        $base = $this->subRequestBase();
        $separator = $base === null ? '' : (str_contains($base, '?') ? '&' : '?');
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof Select) {
                if ($configureTransport) {
                    $component->configureOptionActions($base, $this->method, $path);
                }
            }
            if ($configureTransport && $component instanceof Field && $component->hasStateUpdateHooks()) {
                $component->configureStateUpdate(
                    $base === null ? null : $base.$separator.'_inlay_state_update=1',
                    $this->method,
                );
            }
            if ($configureTransport && $component instanceof FileUpload && $component->usesTemporaryUploads()) {
                $component->configureTemporaryUploadEndpoint(
                    $base === null ? null : $base.$separator.'_inlay_upload='.rawurlencode($path),
                );
            }
            if ($configureTransport && $component instanceof RichEditor && $component->usesFileAttachments()) {
                $component->configureFileAttachmentEndpoint(
                    $base === null ? null : $base.$separator.'_inlay_rich_attachment='.rawurlencode($path),
                );
            }
            if ($configureTransport && $component instanceof RichEditor && $component->usesCustomBlocks()) {
                $component->configureCustomBlockEndpoint(
                    $base === null ? null : $base.$separator.'_inlay_rich_block='.rawurlencode($path),
                    $this->method,
                );
            }
            if ($configureTransport && $component instanceof RichEditor && $component->usesMentions()) {
                $component->configureMentionEndpoint(
                    $base === null ? null : $base.$separator.'_inlay_rich_mention='.rawurlencode($path),
                    $this->method,
                );
            }
            if ($configureTransport && $component instanceof Wizard) {
                $component->configureValidationEndpoint(
                    $base === null ? null : $base.$separator.'_inlay_wizard='.rawurlencode($component->name()),
                    $this->method,
                );
            }
            if ($component instanceof Select && $component->hasRelationship()) {
                if ($modelContext === null) {
                    throw new \LogicException("Relationship select [{$path}] requires Form::model().");
                }
                $component->bindRelationship($modelContext);
            }
            if ($component instanceof MorphToSelect) {
                if ($modelContext === null) {
                    throw new \LogicException("MorphToSelect [{$path}] requires Form::model().");
                }
                $component->bindRelationship($modelContext);
                if ($configureTransport) {
                    $state = ! str_contains($path, '*') && $this->pathExists($data, explode('.', $path)) ? $this->getPath($data, explode('.', $path)) : null;
                    $component->configureRemoteOptions(
                        $base === null ? null : $base.$separator.'_inlay_morph_options='.rawurlencode($path),
                        $state,
                    );
                }
            }
            if ($component instanceof Select && $component->hasRemoteOptions()) {
                if ($configureTransport && $base !== null) {
                    $component->remoteOptionsEndpoint($base.$separator.'_inlay_options='.rawurlencode($path));
                }
                if (! str_contains($path, '*')) {
                    $component->resolveSelectedOptions(
                        $this->pathExists($data, explode('.', $path)) ? $this->getPath($data, explode('.', $path)) : null,
                    );
                }
            }

            // Builder block fields are selected by the current row type, not
            // exposed through childComponents(). Configure their active
            // transport metadata with wildcard paths. A block definition is
            // shared by every row (and may be reused by more than one Form),
            // so a concrete row path would make the last row win and leave
            // newly-added rows without a usable endpoint. Active rows are
            // traversed a second time only to resolve selected option labels;
            // their endpoint metadata remains the declared wildcard.
            if ($component instanceof Builder) {
                foreach ($component->blockDefinitions() as $block) {
                    $this->configureRemoteSelects(
                        $block->schemaComponents(),
                        $data,
                        $path.'.*.data.',
                        $modelContext,
                    );
                }
                foreach ($this->selectedBuilderSchemas($component, $data, $path) as $selected) {
                    $this->configureDynamicContext($selected['components'], $data, $selected['prefix']);
                    $this->configureRemoteSelects(
                        $selected['components'],
                        $data,
                        $selected['prefix'],
                        $modelContext,
                        false,
                    );
                }

                continue;
            }

            $nested = $component->childComponents();
            if ($nested !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $path);
                $nestedModel = $modelContext;
                if ($component instanceof Repeater && $component->managesRelationshipPersistence()) {
                    if ($modelContext === null) {
                        throw new \LogicException("Relationship repeater [{$path}] requires Form::model().");
                    }
                    $nestedModel = $component->relatedModel($modelContext);
                }
                $this->configureRemoteSelects($nested, $data, $nestedPrefix, $nestedModel);
            }
        }
    }

    /** @param list<SchemaComponent> $components */
    private function findSelect(array $components, string $field, string $prefix = '', ?array $data = null): ?Select
    {
        $data ??= $this->data;
        $matches = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof Select && $this->fieldPathMatches($path, $field)) {
                $matches[] = $component;
            }

            if ($component instanceof Builder) {
                // A remote search does not submit the row payload, so the
                // active-row expansion can be empty for a newly added block.
                // Fall back to the declared block schemas and their wildcard
                // path in that case. If state identifies an active row, prefer
                // that block so duplicate field names remain unambiguous.
                $selectedMatches = [];
                foreach ($this->selectedBuilderSchemas($component, $data, $path) as $selected) {
                    $match = $this->findSelect($selected['components'], $field, $selected['prefix'], $data);
                    if ($match !== null) {
                        $selectedMatches[] = $match;
                    }
                }
                $builderMatches = $selectedMatches;
                if ($builderMatches === []) {
                    foreach ($component->blockDefinitions() as $block) {
                        $match = $this->findSelect($block->schemaComponents(), $field, $path.'.*.data.', $data);
                        if ($match !== null) {
                            $builderMatches[] = $match;
                        }
                    }
                }
                $matches = [...$matches, ...$builderMatches];

                continue;
            }
            $nested = $component->childComponents();
            if ($nested !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $path);
                $match = $this->findSelect($nested, $field, $nestedPrefix, $data);
                if ($match !== null) {
                    $matches[] = $match;
                }
            }
        }

        $matches = $this->uniqueComponentMatches($matches);
        if (count($matches) > 1) {
            throw new \InvalidArgumentException("Ambiguous remote select field [{$field}].");
        }

        return $matches[0] ?? null;
    }

    /** @param list<SchemaComponent> $components */
    private function findStateUpdateField(
        array $components,
        string $path,
        string $prefix = '',
        ?array $data = null,
    ): ?Field
    {
        $matches = [];

        foreach ($components as $component) {
            $template = $prefix.$component->name();
            if ($component instanceof Field && $this->statePathMatches($template, $path)) {
                $matches[] = $component;
            }

            if ($component instanceof Builder) {
                $selectedSchemas = $this->selectedBuilderSchemas($component, $data ?? [], $template);
                foreach ($selectedSchemas as $selected) {
                    $nestedMatch = $this->findStateUpdateField(
                        $selected['components'],
                        $path,
                        $selected['prefix'],
                        $data,
                    );
                    if ($nestedMatch !== null) {
                        $matches[] = $nestedMatch;
                    }
                }

                // A state update can arrive immediately after a block is
                // added. When the server has not hydrated that row yet, use
                // the declared schema as a wildcard template. The endpoint
                // is still authorized by the block definition and the caller
                // must pass a concrete numeric row index.
                if ($selectedSchemas === []) {
                    foreach ($component->blockDefinitions() as $block) {
                        $nestedMatch = $this->findStateUpdateField(
                            $block->schemaComponents(),
                            $path,
                            $template.'.*.data.',
                            $data,
                        );
                        if ($nestedMatch !== null) {
                            $matches[] = $nestedMatch;
                        }
                    }
                }

                continue;
            }

            $nested = $component->childComponents();
            if ($nested === []) {
                continue;
            }
            $nestedPrefix = $this->nestedPrefix($component, $prefix, $template);
            $nestedMatch = $this->findStateUpdateField($nested, $path, $nestedPrefix, $data);
            if ($nestedMatch !== null) {
                $matches[] = $nestedMatch;
            }
        }

        if (count($matches) > 1) {
            throw new \InvalidArgumentException("Ambiguous reactive field [{$path}].");
        }

        return $matches[0] ?? null;
    }

    private function statePathMatches(string $template, string $path): bool
    {
        $expected = explode('.', $template);
        $actual = explode('.', $path);
        if (count($expected) !== count($actual)) {
            return false;
        }

        foreach ($expected as $index => $segment) {
            if ($segment === '*') {
                if (! ctype_digit($actual[$index])) {
                    return false;
                }
            } elseif (! hash_equals($segment, $actual[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match both concrete row paths and the wildcard path published for a
     * Builder block schema. The latter is useful when a transport request
     * preserves the endpoint's wildcard instead of substituting its index.
     */
    private function fieldPathMatches(string $template, string $path): bool
    {
        return hash_equals($template, $path)
            || $this->statePathMatches($template, $path)
            || $this->statePathMatches($path, $template);
    }

    private function assertStatePatchValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new \InvalidArgumentException("State patch [{$path}] exceeds the maximum nesting depth.");
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new \InvalidArgumentException("State patch [{$path}] contains a non-finite number.");
            }

            return;
        }
        if (! is_array($value)) {
            throw new \InvalidArgumentException("State patch [{$path}] must contain only JSON-compatible values.");
        }
        foreach ($value as $key => $item) {
            $this->assertStatePatchValue($item, $path.'.'.$key, $depth + 1);
        }
    }

    /** @param list<SchemaComponent> $components */
    private function findMorphToSelect(array $components, string $field, string $prefix = ''): ?MorphToSelect
    {
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof MorphToSelect && hash_equals($path, $field)) {
                return $component;
            }
            $nested = $component->childComponents();
            if ($nested !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $path);
                $match = $this->findMorphToSelect($nested, $field, $nestedPrefix);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    /** @param list<SchemaComponent> $components */
    private function findFileUpload(array $components, string $field, string $prefix = '', ?array $data = null): ?FileUpload
    {
        $data ??= $this->data;
        $matches = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof FileUpload && $this->fieldPathMatches($path, $field)) {
                $matches[] = $component;
            }
            if ($component instanceof Builder) {
                $selectedMatches = [];
                foreach ($this->selectedBuilderSchemas($component, $data, $path) as $selected) {
                    $match = $this->findFileUpload($selected['components'], $field, $selected['prefix'], $data);
                    if ($match !== null) {
                        $selectedMatches[] = $match;
                    }
                }
                $builderMatches = $selectedMatches;
                if ($builderMatches === []) {
                    foreach ($component->blockDefinitions() as $block) {
                        $match = $this->findFileUpload($block->schemaComponents(), $field, $path.'.*.data.', $data);
                        if ($match !== null) {
                            $builderMatches[] = $match;
                        }
                    }
                }
                $matches = [...$matches, ...$builderMatches];

                continue;
            }
            $nested = $component->childComponents();
            if ($nested !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $path);
                $match = $this->findFileUpload($nested, $field, $nestedPrefix, $data);
                if ($match !== null) {
                    $matches[] = $match;
                }
            }
        }

        $matches = $this->uniqueComponentMatches($matches);
        if (count($matches) > 1) {
            throw new \InvalidArgumentException("Ambiguous temporary upload field [{$field}].");
        }

        return $matches[0] ?? null;
    }

    /** @param list<SchemaComponent> $components */
    private function findRichEditor(array $components, string $field, string $prefix = '', ?array $data = null): ?RichEditor
    {
        $data ??= $this->data;
        $matches = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof RichEditor && $this->fieldPathMatches($path, $field)) {
                $matches[] = $component;
            }
            if ($component instanceof Builder) {
                $selectedMatches = [];
                foreach ($this->selectedBuilderSchemas($component, $data, $path) as $selected) {
                    $match = $this->findRichEditor($selected['components'], $field, $selected['prefix'], $data);
                    if ($match !== null) {
                        $selectedMatches[] = $match;
                    }
                }
                $builderMatches = $selectedMatches;
                if ($builderMatches === []) {
                    foreach ($component->blockDefinitions() as $block) {
                        $match = $this->findRichEditor($block->schemaComponents(), $field, $path.'.*.data.', $data);
                        if ($match !== null) {
                            $builderMatches[] = $match;
                        }
                    }
                }
                $matches = [...$matches, ...$builderMatches];

                continue;
            }
            $nested = $component->childComponents();
            if ($nested !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $path);
                $match = $this->findRichEditor($nested, $field, $nestedPrefix, $data);
                if ($match !== null) {
                    $matches[] = $match;
                }
            }
        }

        $matches = $this->uniqueComponentMatches($matches);
        if (count($matches) > 1) {
            throw new \InvalidArgumentException("Ambiguous rich editor field [{$field}].");
        }

        return $matches[0] ?? null;
    }

    /** @param list<SchemaComponent> $matches @return list<SchemaComponent> */
    private function uniqueComponentMatches(array $matches): array
    {
        $unique = [];
        $seen = [];
        foreach ($matches as $match) {
            $id = spl_object_id($match);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $match;
        }

        return $unique;
    }

    /** @param list<SchemaComponent> $components */
    private function containsTemporaryUpload(array $components): bool
    {
        foreach ($components as $component) {
            if ($component instanceof FileUpload && $component->usesTemporaryUploads()) {
                return true;
            }

            $nested = $component->childComponents();
            if ($nested !== [] && $this->containsTemporaryUpload($nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<SchemaComponent>  $components
     * @param  (Closure(array<string, mixed>, string): (Model|class-string<Model>))|null  $modelResolver
     * @return array<string, list<mixed>>
     */
    private function collectRemoteOptionRules(array $components, ?Request $request, string $prefix = '', Model|string|null $modelContext = null, ?Closure $modelResolver = null): array
    {
        $modelContext ??= $this->model;
        $modelResolver ??= fn (array $data, string $attribute): Model|string => $this->model ?? throw new \LogicException('Relationship rules require Form::model().');
        $rules = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof Repeater && $component->managesRelationshipPersistence()) {
                if ($modelContext === null) {
                    throw new \LogicException("Relationship repeater [{$path}] requires Form::model().");
                }
                $keyPath = $path.'.*.'.$component->relatedKeyName($modelContext);
                $rules[$keyPath] = ['nullable', function (string $attribute, mixed $value, Closure $fail, Validator $validator) use ($component, $modelResolver): void {
                    $owner = $modelResolver($validator->getData(), $attribute);
                    if (! $component->ownsRelatedKey($owner, $value)) {
                        $fail("The selected {$attribute} does not belong to this relationship.");
                    }
                }];
            }
            if ($component instanceof Select && $component->hasRemoteOptions()) {
                $rules[$path] = [function (string $attribute, mixed $value, Closure $fail) use ($component, $request): void {
                    if (! $component->hasValidSelection($value, $request)) {
                        $fail("The selected {$attribute} is invalid.");
                    }
                }];
            }
            if ($component instanceof MorphToSelect) {
                if ($modelContext === null) {
                    throw new \LogicException("MorphToSelect [{$path}] requires Form::model().");
                }
                $component->bindRelationship($modelContext);
                $rules[$path] = [function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                    if (! $component->hasValidSelection($value)) {
                        $fail("The selected {$attribute} type or record is invalid.");
                    }
                }];
            }
            $nested = $component->childComponents();
            if ($nested !== []) {
                $nestedPrefix = $this->nestedPrefix($component, $prefix, $path);
                $nestedModel = $modelContext;
                if ($component instanceof Repeater && $component->managesRelationshipPersistence()) {
                    if ($modelContext === null) {
                        throw new \LogicException("Relationship repeater [{$path}] requires Form::model().");
                    }
                    $nestedModel = $component->relatedModel($modelContext);
                }
                $nestedResolver = $modelResolver;
                if ($component instanceof Repeater && $component->managesRelationshipPersistence()) {
                    $parentResolver = $modelResolver;
                    $keyPattern = $path.'.*.'.$component->relatedKeyName($modelContext);
                    $nestedResolver = function (array $data, string $attribute) use ($parentResolver, $component, $modelContext, $keyPattern): Model|string {
                        $parent = $parentResolver($data, $attribute);
                        $concrete = $this->concreteWildcardPath($keyPattern, $attribute);
                        $segments = explode('.', $concrete);
                        $key = $this->pathExists($data, $segments) ? $this->getPath($data, $segments) : null;

                        return $component->relatedRecord($parent, $key) ?? $component->relatedModel($modelContext);
                    };
                }
                $rules = [...$rules, ...$this->collectRemoteOptionRules($nested, $request, $nestedPrefix, $nestedModel, $nestedResolver)];
            }
        }

        return $rules;
    }

    private function concreteWildcardPath(string $pattern, string $attribute): string
    {
        $patternSegments = explode('.', $pattern);
        $attributeSegments = explode('.', $attribute);
        foreach ($patternSegments as $index => $segment) {
            if ($segment === '*') {
                if (! isset($attributeSegments[$index]) || ! ctype_digit($attributeSegments[$index])) {
                    throw new \InvalidArgumentException("Cannot resolve relationship path [{$pattern}] from [{$attribute}].");
                }
                $patternSegments[$index] = $attributeSegments[$index];
            }
        }

        return implode('.', $patternSegments);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function hydrateRelationshipState(array $data): array
    {
        if ($this->model === null) {
            return $data;
        }
        foreach ($this->relationshipSelects($this->components()) as $path => $select) {
            $segments = explode('.', $path);
            if (! $select->managesRelationshipPersistence() && $select->hasRelationship()) {
                $select->bindRelationship($this->model);
            }
            if (! $select->managesRelationshipPersistence() || $this->pathExists($data, $segments)) {
                continue;
            }
            $this->setPath($data, $segments, $select->relationshipState($this->model));
        }
        foreach ($this->relationshipRepeaters($this->components()) as $path => $repeater) {
            $segments = explode('.', $path);
            if (! $this->pathExists($data, $segments)) {
                $this->setPath($data, $segments, $repeater->relationshipState($this->model));
            }
        }
        foreach ($this->morphToSelects($this->components()) as $path => $morphTo) {
            $segments = explode('.', $path);
            if (! $this->pathExists($data, $segments)) {
                $this->setPath($data, $segments, $morphTo->relationshipState($this->model));
            }
        }
        foreach ($this->relationshipContainers($this->components()) as $path => $container) {
            $segments = explode('.', $path);
            if (! $this->pathExists($data, $segments)) {
                $this->setPath($data, $segments, $container->state($this->model));
            }
        }

        return $data;
    }

    /**
     * Collect every layout container bound to a single-record relationship.
     *
     * @param  list<SchemaComponent>  $components
     * @return array<string, ContainerRelationship>
     */
    private function relationshipContainers(array $components, string $prefix = ''): array
    {
        $containers = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            $nestedPrefix = $this->nestedPrefix($component, $prefix, $path);
            if (! $component instanceof Field && $component->getRelationship() !== null) {
                if ($this->model === null) {
                    throw new \LogicException("Relationship container [{$path}] requires Form::model().");
                }
                $statePath = rtrim($nestedPrefix, '.');
                $container = ContainerRelationship::make($component, $statePath);
                $container->bind($this->model);
                $containers[$statePath] = $container;
            }
            $nested = $component->childComponents();
            if ($nested === [] || $component instanceof Repeater || $component instanceof Builder) {
                continue;
            }
            $containers = [...$containers, ...$this->relationshipContainers($nested, $nestedPrefix)];
        }

        return $containers;
    }

    /** @param list<SchemaComponent> $components @return array<string, Select> */
    private function relationshipSelects(array $components, string $prefix = ''): array
    {
        $selects = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof Select && $component->hasRelationship()) {
                if ($this->model === null) {
                    throw new \LogicException("Relationship select [{$path}] requires Form::model().");
                }
                $component->bindRelationship($this->model);
                if ($component->managesRelationshipPersistence()) {
                    $selects[$path] = $component;
                }
            }
            $nested = $component->childComponents();
            if ($nested === []) {
                continue;
            }
            if ($component instanceof Repeater || $component instanceof Builder) {
                continue;
            }
            $selects = [...$selects, ...$this->relationshipSelects($nested, $prefix)];
        }

        return $selects;
    }

    /** @param list<SchemaComponent> $components @return array<string, Repeater> */
    private function relationshipRepeaters(array $components, string $prefix = ''): array
    {
        $repeaters = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof Repeater && $component->managesRelationshipPersistence()) {
                if ($this->model === null) {
                    throw new \LogicException("Relationship repeater [{$path}] requires Form::model().");
                }
                $component->bindRelationship($this->model);
                $repeaters[$path] = $component;

                continue;
            }
            $nested = $component->childComponents();
            if ($nested !== []) {
                $repeaters = [...$repeaters, ...$this->relationshipRepeaters($nested, $prefix)];
            }
        }

        return $repeaters;
    }

    /** @param list<SchemaComponent> $components @return array<string, MorphToSelect> */
    private function morphToSelects(array $components, string $prefix = ''): array
    {
        $fields = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof MorphToSelect) {
                if ($this->model === null) {
                    throw new \LogicException("MorphToSelect [{$path}] requires Form::model().");
                }
                $component->bindRelationship($this->model);
                $fields[$path] = $component;
            }
            $nested = $component->childComponents();
            if ($nested === [] || $component instanceof Repeater || $component instanceof Builder) {
                continue;
            }
            $fields = [...$fields, ...$this->morphToSelects($nested, $prefix)];
        }

        return $fields;
    }

    /**
     * Restore server-owned values before validation or dehydration.
     *
     * Disabled and hidden controls cannot be trusted in a stateless Inertia
     * request, even when a forged payload includes them. Explicit saved()
     * configuration controls whether the trusted value is emitted later; it
     * never turns the submitted browser value into an authoritative value.
     *
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $evaluationState
     * @return array<string, mixed>
     */
    private function restoreProtectedState(array $submitted, array $evaluationState): array
    {
        $trusted = $this->hydrateRelationshipState($this->data);
        $this->restoreProtectedComponents(
            $this->components(),
            $submitted,
            $trusted,
            $evaluationState,
        );

        return $submitted;
    }

    /**
     * @param  list<SchemaComponent>  $components
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $trusted
     * @param  array<string, mixed>  $evaluationState
     */
    private function restoreProtectedComponents(
        array $components,
        array &$submitted,
        array $trusted,
        array $evaluationState,
        string $prefix = '',
        bool $hiddenByAncestor = false,
    ): void {
        $context = SchemaContext::make($evaluationState, $this->validationOperation, $this->model);

        foreach ($components as $component) {
            $path = $prefix.$component->name();
            $componentHidden = $hiddenByAncestor || $component->isHiddenForState(
                $context,
                $component instanceof Field ? $path : null,
            );

            if ($component instanceof Field && ($componentHidden || $component->isDisabledForState($context, $path))) {
                $segments = explode('.', $path);
                if ($this->pathExists($trusted, $segments)) {
                    $this->setPath($submitted, $segments, $this->getPath($trusted, $segments));
                } else {
                    $this->forgetPath($submitted, $segments);
                }
            }

            // Builder block schemas are dynamic: their components are chosen
            // by the submitted item type and therefore are not part of the
            // Builder component's static child list. They still need the same
            // protected-state restoration as ordinary repeater children so a
            // forged disabled/hidden value cannot reach validation.
            if ($component instanceof Builder) {
                $items = $this->getPathIfExists($submitted, explode('.', $path));
                if (is_array($items)) {
                    foreach ($items as $index => $item) {
                        $type = is_array($item) ? ($item['type'] ?? null) : null;
                        $block = is_string($type) ? ($component->blockDefinitions()[$type] ?? null) : null;
                        if ($block === null || ! is_array($item)) {
                            continue;
                        }

                        $this->restoreProtectedComponents(
                            $block->schemaComponents(),
                            $submitted,
                            $trusted,
                            $evaluationState,
                            $path.'.'.$index.'.data.',
                            $componentHidden,
                        );
                    }
                }

                continue;
            }

            $nested = $component->childComponents();
            if ($nested === []) {
                continue;
            }

            if ($component instanceof Repeater || $component instanceof Builder) {
                $items = $this->getPathIfExists($submitted, explode('.', $path));
                if (! is_array($items)) {
                    continue;
                }
                foreach (array_keys($items) as $index) {
                    $this->restoreProtectedComponents(
                        $nested,
                        $submitted,
                        $trusted,
                        $evaluationState,
                        $path.'.'.$index.'.',
                        $componentHidden,
                    );
                }
            } else {
                $this->restoreProtectedComponents(
                    $nested,
                    $submitted,
                    $trusted,
                    $evaluationState,
                    $this->nestedPrefix($component, $prefix, $path),
                    $componentHidden,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(Field, mixed, array<string, mixed>, string): mixed  $transform
     * @return array<string, mixed>
     */
    private function transformFields(
        array $data,
        callable $transform,
        bool $removeNonDehydrated = false,
        ?array $evaluationState = null,
    ): array {
        $result = $data;
        $this->walkFields(
            $this->components(),
            $result,
            $transform,
            $removeNonDehydrated,
            evaluationState: $evaluationState ?? $data,
        );

        return $result;
    }

    /**
     * @param  list<SchemaComponent>  $components
     * @param  array<string, mixed>  $root
     * @param  callable(Field, mixed, array<string, mixed>, string): mixed  $transform
     */
    private function walkFields(
        array $components,
        array &$root,
        callable $transform,
        bool $removeNonDehydrated,
        string $prefix = '',
        array $evaluationState = [],
        bool $hiddenByAncestor = false,
    ): void {
        $context = SchemaContext::make($evaluationState, $this->validationOperation, $this->model);

        foreach ($components as $component) {
            $path = $prefix.$component->name();
            $componentHidden = $hiddenByAncestor || $component->isHiddenForState(
                $context,
                $component instanceof Field ? $path : null,
            );

            if ($component instanceof Field) {
                if ($removeNonDehydrated && ! $component->isDehydrated($context, $path, $hiddenByAncestor)) {
                    $this->forgetPath($root, explode('.', $path));
                } elseif ($this->pathExists($root, explode('.', $path))) {
                    $state = $this->getPath($root, explode('.', $path));
                    $this->setPath($root, explode('.', $path), $transform($component, $state, $root, $path));
                }
            }

            if ($component instanceof Builder) {
                foreach ($this->selectedBuilderSchemas($component, $root, $path) as $selected) {
                    $this->configureDynamicContext($selected['components'], $root, $selected['prefix']);
                    $this->walkFields(
                        $selected['components'],
                        $root,
                        $transform,
                        $removeNonDehydrated,
                        $selected['prefix'],
                        $evaluationState,
                        $componentHidden,
                    );
                }

                continue;
            }

            $nested = $component->childComponents();
            if ($nested === []) {
                continue;
            }

            if ($component instanceof Repeater) {
                $segments = explode('.', $path);
                $items = $this->pathExists($root, $segments) ? $this->getPath($root, $segments) : null;
                if (is_array($items)) {
                    foreach (array_keys($items) as $index) {
                        $this->walkFields(
                            $nested,
                            $root,
                            $transform,
                            $removeNonDehydrated,
                            $path.'.'.$index.'.',
                            $evaluationState,
                            $componentHidden,
                        );
                    }
                }
            } else {
                $this->walkFields(
                    $nested,
                    $root,
                    $transform,
                    $removeNonDehydrated,
                    $this->nestedPrefix($component, $prefix, $path),
                    $evaluationState,
                    $componentHidden,
                );
            }
        }
    }

    /**
     * Recompute every computed field from the current state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyComputedState(array $data): array
    {
        $this->configureSchemaContext($this->components(), SchemaContext::make($data, $this->validationOperation, $this->model));
        foreach ($this->computedPaths($this->components(), $data) as $path) {
            $field = $this->computedFields[$path];
            $this->setPath($data, explode('.', $path), $field->computeState($data, $path));
        }

        return $data;
    }

    /**
     * Resolve every computed field path, expanding repeater and builder rows.
     *
     * @param  list<SchemaComponent>  $components
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function computedPaths(array $components, array $data, string $prefix = ''): array
    {
        $paths = [];
        foreach ($components as $component) {
            $path = $prefix.$component->name();
            if ($component instanceof Field && $component->isComputed()) {
                $this->computedFields[$path] = $component;
                $paths[] = $path;
            }

            if ($component instanceof Builder) {
                foreach ($this->selectedBuilderSchemas($component, $data, $path) as $selected) {
                    $paths = [
                        ...$paths,
                        ...$this->computedPaths($selected['components'], $data, $selected['prefix']),
                    ];
                }

                continue;
            }

            $nested = $component->childComponents();
            if ($nested === []) {
                continue;
            }

            if ($component instanceof Repeater || $component instanceof Builder) {
                $items = $this->getPathIfExists($data, explode('.', $path));
                if (! is_array($items)) {
                    continue;
                }
                foreach (array_keys($items) as $index) {
                    $paths = [...$paths, ...$this->computedPaths($nested, $data, $path.'.'.$index.'.')];
                }

                continue;
            }

            $paths = [...$paths, ...$this->computedPaths($nested, $data, $this->nestedPrefix($component, $prefix, $path))];
        }

        return $paths;
    }

    /** @param list<string> $segments */
    private function pathExists(array $data, array $segments): bool
    {
        foreach ($segments as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return false;
            }
            $data = $data[$segment];
        }

        return true;
    }

    /** @param list<string> $segments */
    private function getPath(array $data, array $segments): mixed
    {
        foreach ($segments as $segment) {
            $data = $data[$segment];
        }

        return $data;
    }

    /** @param list<string> $segments */
    private function getPathIfExists(array $data, array $segments): mixed
    {
        return $this->pathExists($data, $segments) ? $this->getPath($data, $segments) : null;
    }

    /** @param list<string> $segments */
    private function setPath(array &$data, array $segments, mixed $value): void
    {
        $target = &$data;
        foreach ($segments as $segment) {
            $target = &$target[$segment];
        }
        $target = $value;
    }

    /** @param list<string> $segments */
    private function forgetPath(array &$data, array $segments): void
    {
        $last = array_pop($segments);
        $target = &$data;
        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                return;
            }
            $target = &$target[$segment];
        }
        if ($last !== null) {
            unset($target[$last]);
        }
    }
}
