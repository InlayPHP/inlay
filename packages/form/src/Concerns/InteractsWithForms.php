<?php

declare(strict_types=1);

namespace Inlay\Forms\Concerns;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Form;
use Inlay\Forms\Uploads\TemporaryUploadManager;
use Inlay\Validation\ValidationRunner;

trait InteractsWithForms
{
    /**
     * @return array<string, Form>
     */
    final public function resolveForms(Request $request): array
    {
        $definitions = $this->forms($request);

        if ($definitions === []) {
            throw new \LogicException('A form consumer must define at least one form.');
        }

        $multiple = count($definitions) > 1;
        $forms = [];

        foreach ($definitions as $name => $configure) {
            $this->assertFormDefinition($name, $configure);

            $form = Form::make($name)
                ->action($this->formAction($request, $name, $multiple))
                ->data($this->formData($name, $request));
            $resolved = $configure($form);

            if (! $resolved instanceof Form) {
                throw new \LogicException("Form definition [{$name}] must return ".Form::class.'.');
            }

            $forms[$name] = $resolved;
        }

        return $forms;
    }

    final public function resolveForm(Request $request, ?string $name = null): Form
    {
        $forms = $this->resolveForms($request);
        $selected = $name ?? $this->selectedFormName($request, $forms);

        if (! isset($forms[$selected])) {
            throw new \InvalidArgumentException("Unknown form [{$selected}].");
        }

        return $forms[$selected];
    }

    final public function processForm(
        Request $request,
        ValidationRunner $validationRunner,
        ValidationFactory $validationFactory,
        ?TemporaryUploadManager $temporaryUploads = null,
    ): mixed {
        $forms = $this->resolveForms($request);
        $name = $this->selectedFormName($request, $forms);

        return $this->validateAndSubmit(
            $name,
            $request,
            $forms[$name],
            $validationRunner,
            $validationFactory,
            count($forms) > 1,
            $temporaryUploads,
        );
    }

    /**
     * Retained for callers that already resolved a single form explicitly.
     */
    final public function process(
        Request $request,
        Form $form,
        ValidationRunner $validationRunner,
        ValidationFactory $validationFactory,
        ?TemporaryUploadManager $temporaryUploads = null,
    ): mixed {
        return $this->validateAndSubmit(
            $this->name(),
            $request,
            $form,
            $validationRunner,
            $validationFactory,
            false,
            $temporaryUploads,
        );
    }

    /**
     * Override this method to expose multiple named forms.
     *
     * @return array<string, Closure(Form): Form>
     */
    protected function forms(Request $request): array
    {
        return [
            $this->name() => fn (Form $form): Form => $this->form($form),
        ];
    }

    abstract protected function form(Form $form): Form;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function submit(array $data, Request $request): mixed;

    protected function name(): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::snake(class_basename(static::class)));

        return trim((string) $name, '_') ?: 'form';
    }

    /** @return array<string, mixed> */
    protected function data(Request $request): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function formData(string $name, Request $request): array
    {
        return $this->data($request);
    }

    /** @return array<string, mixed> */
    protected function rules(Form $form, Request $request): array
    {
        return $form->validationRules();
    }

    /** @return array<string, string> */
    protected function messages(Request $request): array
    {
        return [];
    }

    /** @return array<string, string> */
    protected function attributes(Request $request): array
    {
        return [];
    }

    /**
     * Override this method when named forms need separate submit handlers.
     *
     * @param  array<string, mixed>  $data
     */
    protected function submitForm(string $name, array $data, Request $request): mixed
    {
        return $this->submit($data, $request);
    }

    /**
     * @param  array<string, Form>  $forms
     */
    private function selectedFormName(Request $request, array $forms): string
    {
        $requested = $request->query('_inlay_form');

        if ($requested === null || $requested === '') {
            return (string) array_key_first($forms);
        }

        if (! is_string($requested) || ! isset($forms[$requested])) {
            throw new \InvalidArgumentException('The requested form is not registered on this page.');
        }

        return $requested;
    }

    private function formAction(Request $request, string $name, bool $multiple): string
    {
        $path = $request->getPathInfo();

        return $multiple ? $path.'?_inlay_form='.rawurlencode($name) : $path;
    }

    private function assertFormDefinition(mixed $name, mixed $configure): void
    {
        if (! is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new \LogicException('Form names may contain only letters, numbers, underscores, and hyphens.');
        }

        if (! $configure instanceof Closure) {
            throw new \LogicException("Form definition [{$name}] must be a closure.");
        }
    }

    private function validateAndSubmit(
        string $name,
        Request $request,
        Form $form,
        ValidationRunner $validationRunner,
        ValidationFactory $validationFactory,
        bool $useNamedErrorBag,
        ?TemporaryUploadManager $temporaryUploads,
    ): mixed {
        try {
            $input = $request->all();
            if ($temporaryUploads !== null) {
                $input = $form->resolveTemporaryUploads($input, $request, $temporaryUploads);
            }
            if ($form->hasValidation()) {
                $data = $form->validate($validationRunner, $input, user: $request->user(), options: ['request' => $request]);
            } else {
                $validator = $validationFactory->make(
                    $form->mutateStateForValidation($input),
                    $this->rules($form, $request),
                    $this->messages($request),
                    $this->attributes($request),
                );
                $validator->addRules($form->remoteOptionValidationRules($request));
                $data = $form->dehydrateState($validator->validate());
            }

            $data = $form->storeUploadedFiles($data, $request);
        } catch (UploadRejected $exception) {
            $temporaryUploads?->cleanupMaterialized($request);
            $validator = $validationFactory->make([], []);
            $validator->errors()->add($exception->field, $exception->validationMessage);
            $validationException = new ValidationException($validator);
            if ($useNamedErrorBag) {
                $validationException->errorBag = $name;
            }

            throw $validationException;
        } catch (ValidationException $exception) {
            $temporaryUploads?->cleanupMaterialized($request);
            if ($useNamedErrorBag) {
                $exception->errorBag = $name;
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $temporaryUploads?->cleanupMaterialized($request);

            throw $exception;
        }

        try {
            $result = $this->submitForm($name, $data, $request);
        } catch (\Throwable $exception) {
            $temporaryUploads?->cleanupMaterialized($request);

            throw $exception;
        }
        $temporaryUploads?->consumeResolved($request);

        return $result;
    }
}
