<?php

declare(strict_types=1);

namespace Inlay\Actions;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inlay\Actions\Contracts\ActionFormResolver;
use Inlay\Support\ClosureEvaluator;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

final readonly class ActionRunner
{
    public function __construct(
        private Container $container,
        private ValidationFactory $validation,
        private ConnectionResolverInterface $connections,
        private ?ActionFormResolver $forms = null,
    ) {}

    /**
     * @param iterable<array-key, mixed> $records
     * @return array{contract: string, form: array<string, mixed>}
     */
    public function mountForm(Action $action, Request $request, array $data = [], iterable $records = []): array
    {
        if (! $action->requiresMount()) {
            throw new \LogicException("Action [{$action->name()}] does not define a form or dynamic modal.");
        }

        $records = $records instanceof Collection ? $records->values() : collect($records)->values();
        $utilities = $this->utilities($action, $request, $records, $data);
        $this->authorize($action, $utilities);

        if (! $action->hasForm()) {
            return [
                'contract' => 'inlay.actions.form.v1',
                'modal' => $this->resolveModal($action, $utilities),
                'form' => null,
            ];
        }

        $data = $this->runDataHooks($action->beforeFormFilledHooks(), $data, $utilities);
        $fill = $action->formDataDefinition();
        if ($fill instanceof Closure) {
            $fill = $this->evaluate($fill, $this->utilities($action, $request, $records, $data));
        }
        if (! is_array($fill) || ($fill !== [] && array_is_list($fill))) {
            throw new \UnexpectedValueException('Action form data must resolve to an associative array.');
        }
        $data = [...$data, ...$fill];
        $data = $this->runDataHooks(
            $action->afterFormFilledHooks(),
            $data,
            $this->utilities($action, $request, $records, $data),
        );

        return [
            'contract' => 'inlay.actions.form.v1',
            'modal' => $this->resolveModal($action, $this->utilities($action, $request, $records, $data)),
            'form' => $this->formResolver()->mount(
                $action,
                $this->resolveFormSchema($action, $request, $records, $data),
                $data,
                $request,
                $records,
            ),
        ];
    }

    /**
     * Authorize an action without executing a lifecycle handler.
     *
     * Table exports and other streamed responses use the same callback
     * boundary as ordinary actions, while deliberately avoiding a fake
     * lifecycle action just to get authorization.
     *
     * @param  iterable<array-key, mixed>  $records
     * @param  array<string, mixed>  $data
     */
    public function authorizeOnly(Action $action, Request $request, iterable $records = [], array $data = []): void
    {
        $records = $records instanceof Collection ? $records->values() : collect($records)->values();
        $this->authorize($action, $this->utilities($action, $request, $records, $data));
    }

    /**
     * Resolve record- and selection-aware modal content, so a bulk action can
     * describe the exact selection the visitor is about to act on.
     *
     * @param  array<string, mixed>  $utilities
     * @return array<string, mixed>|null
     */
    private function resolveModal(Action $action, array $utilities): ?array
    {
        $modal = $action->modalDefinition();

        return $modal?->resolve(fn (Closure $callback): mixed => $this->evaluate($callback, $utilities));
    }

    /** Whether the request is a sub-transport of an already mounted action form. */
    public function handlesFormSubRequest(Request $request): bool
    {
        return $this->formResolver()->handlesSubRequest($request);
    }

    /**
     * Serve a sub-transport request made by an open action form. The action is
     * authorized again before its form is rebuilt, so an open modal can never
     * widen access.
     *
     * @param  array<string, mixed>  $data
     * @param  iterable<array-key, mixed>  $records
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function formSubRequest(Action $action, Request $request, array $data = [], iterable $records = []): array
    {
        if (! $action->hasForm()) {
            throw new \LogicException("Action [{$action->name()}] does not define a form.");
        }

        $records = $records instanceof Collection ? $records->values() : collect($records)->values();
        $this->authorize($action, $this->utilities($action, $request, $records, $data));

        return $this->formResolver()->subRequest(
            $action,
            $this->resolveFormSchema($action, $request, $records, $data),
            $data,
            $request,
            $records,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param iterable<array-key, mixed> $records
     */
    public function run(Action $action, Request $request, array $data = [], iterable $records = []): ActionResult
    {
        $queuedJob = $action instanceof BulkAction ? $action->queuedJob() : null;
        if ($queuedJob === null && ! $action->hasLifecycleHandler()) {
            throw new \LogicException("Action [{$action->name()}] does not define a lifecycle handler.");
        }
        if (strtolower($request->getMethod()) !== $action->methodValue()) {
            throw new \UnexpectedValueException("Action [{$action->name()}] requires a ".strtoupper($action->methodValue()).' request.');
        }

        $arguments = $data['_inlay_action_arguments'] ?? [];
        unset($data['_inlay_action_arguments']);
        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new \UnexpectedValueException('Action arguments must be an associative array.');
        }

        $selected = $records instanceof Collection ? $records->values() : collect($records)->values();
        $records = $selected;
        $action->beginLifecycleExecution();

        try {
            // Bound by reference so hooks and the handler see the records that
            // survived individual authorization, not the raw selection.
            $utilities = function (array $state, mixed $result = null) use ($action, $arguments, $request, &$records): array {
                return $this->utilities($action, $request, $records, $state, $result, $arguments);
            };

            $this->authorize($action, $utilities($data));

            $skipped = [];
            $individual = $action instanceof BulkAction ? $action->individualRecordAuthorization() : null;
            if ($individual !== null) {
                [$records, $skipped] = $this->partitionAuthorizedRecords($action, $individual, $request, $selected, $data);
            }

            $this->runHooks($action->beforeValidationHooks(), $utilities($data));
            if ($result = $this->interruptedResult($action)) {
                return $result;
            }

            if ($action->hasForm()) {
                $data = $this->formResolver()->validate(
                    $action,
                    $this->resolveFormSchema($action, $request, $records, $data),
                    $data,
                    $request,
                    $records,
                );
            }

            $rules = $action->validationRules();
            if ($rules instanceof Closure) {
                $rules = $this->evaluate($rules, $utilities($data));
            }
            if (! is_array($rules)) {
                throw new \UnexpectedValueException('Action validation rules must resolve to an array.');
            }
            if ($rules !== []) {
                $validated = $this->validation->make(
                    $data,
                    $rules,
                    $action->validationMessages(),
                    $action->validationAttributeLabels(),
                )->validate();
                $data = $action->hasForm() ? [...$data, ...$validated] : $validated;
            }

            $this->runHooks($action->afterValidationHooks(), $utilities($data));
            if ($result = $this->interruptedResult($action)) {
                return $result;
            }

            if ($action->dataMutationCallback() !== null) {
                $data = $this->evaluate($action->dataMutationCallback(), $utilities($data));
                if (! is_array($data)) {
                    throw new \UnexpectedValueException('Action data mutation callbacks must return an array.');
                }
            }

            $execute = function () use ($action, $request, $queuedJob, $utilities, &$records, &$data): mixed {
                $this->runHooks($action->beforeHooks(), $utilities($data));
                if ($this->interruptedResult($action)) {
                    return null;
                }

                $result = $queuedJob !== null
                    ? $this->dispatchChunks($action, $queuedJob, $records, $data)
                    : $this->runHandler($action, $request, $utilities, $records, $data);
                if ($this->interruptedResult($action)) {
                    return $result;
                }

                $this->runHooks($action->afterHooks(), $utilities($data, $result));

                return $result;
            };

            $report = $individual === null && $action->reportedRecordFailures() === []
                ? null
                : $this->buildRecordReport($selected, $records, $skipped, $action->reportedRecordFailures());

            if ($report !== null && $report['processed'] === 0 && $report['total'] > 0) {
                $result = ActionResult::cancelled(
                    $this->resolveMessage($action->failureMessageValue(), $utilities($data)) ?? $this->skippedMessage($report),
                    $report,
                );

                return $this->notifyResult($result, 'warning');
            }

            $value = $action->usesDatabaseTransaction()
                ? $this->connections->connection()->transaction($execute)
                : $execute();

            if ($result = $this->interruptedResult($action)) {
                return $result;
            }

            $report = $report === null && $action->reportedRecordFailures() === []
                ? null
                : $this->buildRecordReport($selected, $records, $skipped, $action->reportedRecordFailures());
            $partial = $report !== null && ($report['skipped'] > 0 || $report['failed'] > 0);
            $message = $partial
                ? $this->resolveMessage($action->failureMessageValue(), $utilities($data, $value))
                : null;

            return $this->notifyResult(ActionResult::succeeded(
                $value,
                $message ?? $this->resolveMessage($action->successMessageValue(), $utilities($data, $value)),
                $report,
            ), $partial ? 'warning' : 'success');
        } catch (Throwable $exception) {
            foreach ($action->failureHooks() as $hook) {
                $this->evaluate($hook, [
                    'action' => $action,
                    'exception' => $exception,
                    'request' => $request,
                    'user' => $request->user(),
                    'records' => $records,
                    'record' => $records->first(),
                    'data' => $data,
                    'arguments' => $arguments,
                ]);
            }

            throw $exception;
        } finally {
            $action->finishLifecycleExecution();
        }
    }

    /**
     * Run the handler once, or once per chunk when the action asks for it.
     *
     * @param  Collection<int, mixed>  $records
     * @param  array<string, mixed>  $data
     */
    private function runHandler(Action $action, Request $request, Closure $utilities, Collection $records, array $data): mixed
    {
        $chunkSize = $action instanceof BulkAction ? $action->chunkSize() : null;
        if ($chunkSize === null) {
            return $this->evaluate($action->lifecycleHandler(), $utilities($data));
        }

        $results = [];
        foreach ($records->chunk($chunkSize) as $chunk) {
            $chunk = $chunk->values();
            $results[] = $this->evaluate($action->lifecycleHandler(), $this->utilities($action, $request, $chunk, $data));
        }

        return ['chunks' => count($results), 'records' => $records->count()];
    }

    /**
     * Hand every chunk of record keys to the application's own job.
     *
     * @param  class-string  $job
     * @param  Collection<int, mixed>  $records
     * @param  array<string, mixed>  $data
     * @return array{queued: true, job: class-string, batches: int, records: int}
     */
    private function dispatchChunks(BulkAction $action, string $job, Collection $records, array $data): array
    {
        $dispatcher = $this->container->make(BusDispatcher::class);
        $keys = $records->map(static fn (mixed $record): mixed => $record instanceof Model ? $record->getKey() : $record)->all();
        $batches = 0;

        foreach (array_chunk($keys, $action->chunkSize() ?? 100) as $chunk) {
            $pending = new $job($chunk, $data);
            if ($action->queueConnection() !== null && method_exists($pending, 'onConnection')) {
                $pending->onConnection($action->queueConnection());
            }
            if ($action->queueName() !== null && method_exists($pending, 'onQueue')) {
                $pending->onQueue($action->queueName());
            }
            $dispatcher->dispatch($pending);
            $batches++;
        }

        return ['queued' => true, 'job' => $job, 'batches' => $batches, 'records' => count($keys)];
    }

    /**
     * Split a bulk selection into the records the visitor may act on and the
     * ones that must be skipped, without failing the whole run.
     *
     * @param  Collection<int, mixed>  $records
     * @param  array<string, mixed>  $data
     * @return array{Collection<int, mixed>, list<string|int|null>}
     */
    private function partitionAuthorizedRecords(Action $action, Closure $callback, Request $request, Collection $records, array $data): array
    {
        $skipped = [];
        $authorized = $records->filter(function (mixed $record) use ($action, $callback, $request, $records, $data, &$skipped): bool {
            $allowed = $this->evaluate($callback, [
                ...$this->utilities($action, $request, $records, $data),
                'record' => $record,
            ]);

            if (! is_bool($allowed)) {
                throw new \UnexpectedValueException('Individual record authorization callbacks must return a boolean.');
            }
            if (! $allowed) {
                $skipped[] = Action::recordKey($record);
            }

            return $allowed;
        })->values();

        return [$authorized, $skipped];
    }

    /**
     * @param  Collection<int, mixed>  $selected
     * @param  Collection<int, mixed>  $processed
     * @param  list<string|int|null>  $skipped
     * @param  list<array{record: string|int|null, reason: string|null}>  $failures
     * @return array{total: int, processed: int, skipped: int, failed: int, skippedRecords: list<string|int|null>, failures: list<array{record: string|int|null, reason: string|null}>}
     */
    private function buildRecordReport(Collection $selected, Collection $processed, array $skipped, array $failures): array
    {
        return [
            'total' => $selected->count(),
            'processed' => max(0, $processed->count() - count($failures)),
            'skipped' => count($skipped),
            'failed' => count($failures),
            'skippedRecords' => array_values($skipped),
            'failures' => array_values($failures),
        ];
    }

    /** @param array<string, mixed> $report */
    private function skippedMessage(array $report): string
    {
        return $report['total'] === 1
            ? 'The selected record could not be processed.'
            : "None of the {$report['total']} selected records could be processed.";
    }

    /** @param list<Closure> $hooks @param array<string, mixed> $utilities */
    private function runHooks(array $hooks, array $utilities): void
    {
        foreach ($hooks as $hook) {
            $this->evaluate($hook, $utilities);
            $action = $utilities['action'];
            if ($action instanceof Action && $action->isLifecycleInterrupted()) {
                return;
            }
        }
    }

    /** @param list<Closure> $hooks @param array<string, mixed> $data @param array<string, mixed> $utilities @return array<string, mixed> */
    private function runDataHooks(array $hooks, array $data, array $utilities): array
    {
        foreach ($hooks as $hook) {
            $result = $this->evaluate($hook, [...$utilities, 'data' => $data]);
            if ($result === null) {
                continue;
            }
            if (! is_array($result) || ($result !== [] && array_is_list($result))) {
                throw new \UnexpectedValueException('Action form fill hooks must return an associative array or null.');
            }
            $data = $result;
        }

        return $data;
    }

    /** @param Collection<int, mixed> $records @param array<string, mixed> $data @return list<mixed> */
    private function resolveFormSchema(Action $action, Request $request, Collection $records, array $data): array
    {
        $schema = $action->formSchemaDefinition();
        if ($schema instanceof Closure) {
            $schema = $this->evaluate($schema, $this->utilities($action, $request, $records, $data));
        }
        if (! is_array($schema) || ! array_is_list($schema)) {
            throw new \UnexpectedValueException('Action form schemas must resolve to a list of components.');
        }

        return $schema;
    }

    /** @param array<string, mixed> $utilities */
    private function authorize(Action $action, array $utilities): void
    {
        $authorized = $action->authorizationCallback() === null
            ? false
            : $this->evaluate($action->authorizationCallback(), $utilities);

        if (! is_bool($authorized)) {
            throw new \UnexpectedValueException('Action authorization callbacks must return a boolean.');
        }
        if (! $authorized) {
            throw new AuthorizationException("You are not authorized to run action [{$action->name()}].");
        }
    }

    /** @param Collection<int, mixed> $records @param array<string, mixed> $data @param array<string, mixed> $arguments @return array<string, mixed> */
    private function utilities(Action $action, Request $request, Collection $records, array $data, mixed $result = null, array $arguments = []): array
    {
        return [
            'action' => $action,
            'arguments' => $arguments,
            'data' => $data,
            'record' => $records->first(),
            'records' => $records,
            'request' => $request,
            'result' => $result,
            'user' => $request->user(),
        ];
    }

    private function formResolver(): ActionFormResolver
    {
        return $this->forms ?? new UnavailableActionFormResolver;
    }

    private function interruptedResult(Action $action): ?ActionResult
    {
        $result = match ($action->lifecycleStatus()) {
            'halted' => ActionResult::halted($action->lifecycleMessage()),
            'cancelled' => ActionResult::cancelled($action->lifecycleMessage()),
            default => null,
        };

        return $result === null ? null : $this->notifyResult($result, 'warning');
    }

    /**
     * Actions keep their existing result contract while optionally delivering
     * the configured message through the notifications package. The optional
     * class check keeps `inlayphp/actions` installable without notifications.
     */
    private function notifyResult(ActionResult $result, string $status): ActionResult
    {
        if ($result->message === null || ! class_exists(\Inlay\Notifications\Notification::class)) {
            return $result;
        }

        $manager = \Inlay\Notifications\NotificationManager::class;
        if (! $this->container->bound($manager)) {
            return $result;
        }

        $this->container->make($manager)->send(
            \Inlay\Notifications\Notification::make($result->message)->status($status),
        );

        return $result;
    }

    /** @param array<string, mixed> $utilities */
    private function evaluate(Closure $callback, array $utilities): mixed
    {
        $typed = [];
        foreach ($utilities as $value) {
            if (is_object($value)) {
                $typed[$value::class] = $value;
            }
        }

        foreach ((new ReflectionFunction($callback))->getParameters() as $parameter) {
            if (array_key_exists($parameter->getName(), $utilities)) {
                continue;
            }
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $typeName = $type->getName();
            foreach ($typed as $value) {
                if ($value instanceof $typeName) {
                    $utilities[$parameter->getName()] = $value;
                    continue 2;
                }
            }
            if ($this->container->bound($typeName)) {
                $utilities[$parameter->getName()] = $this->container->make($typeName);
            }
        }

        return ClosureEvaluator::evaluate($callback, $utilities, $typed);
    }

    /** @param string|Closure|null $message @param array<string, mixed> $utilities */
    private function resolveMessage(string|Closure|null $message, array $utilities): ?string
    {
        if ($message === null) {
            return null;
        }

        $resolved = $message instanceof Closure ? $this->evaluate($message, $utilities) : $message;
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new \UnexpectedValueException('Action messages must resolve to a non-empty string.');
        }

        return trim($resolved);
    }
}
