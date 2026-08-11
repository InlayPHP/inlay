<?php

declare(strict_types=1);

namespace Inlay\Forms\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Form;
use Inlay\Forms\Uploads\TemporaryUploadManager;
use Inlay\Validation\ValidationRunner;
use InvalidArgumentException;

/**
 * Dispatches the sub-transport requests a hosted action form makes while the
 * modal is open: live state updates, temporary uploads, select option actions,
 * remote option searches, and deferred schema views.
 *
 * The host controller has already authorized the action and resolved its
 * records, so every branch runs against the same mounted form the visitor sees.
 */
final readonly class ActionFormSubRequest
{
    public function __construct(
        private ValidationFactory $validation,
        private Container $container,
    ) {}

    public static function applies(Request $request): bool
    {
        foreach (self::keys() as $key) {
            if ($request->query->has($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return [
            '_inlay_view',
            '_inlay_state_update',
            '_inlay_upload',
            '_inlay_select_action',
            '_inlay_options',
            '_inlay_morph_options',
            '_inlay_wizard',
            '_inlay_rich_attachment',
            '_inlay_rich_block',
            '_inlay_rich_mention',
        ];
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    public function handle(Form $form, Request $request): array
    {
        if ($request->query->has('_inlay_view')) {
            return $this->ok($form->resolveDeferredView($this->viewName($request), $request));
        }

        if ($request->query->has('_inlay_state_update')) {
            return $this->ok($this->stateUpdate($form, $request));
        }

        if ($request->query->has('_inlay_upload')) {
            return $this->upload($form, $request);
        }

        if ($request->query->has('_inlay_select_action')) {
            return $this->selectOptionAction($form, $request);
        }

        if ($request->query->has('_inlay_options')) {
            return $this->ok(['options' => $form->searchSelectOptions(
                $this->fieldPath($request, '_inlay_options', 'remote select field'),
                $this->search($request),
                $request,
            )]);
        }

        if ($request->query->has('_inlay_morph_options')) {
            $type = $request->query('type');
            if (! is_string($type) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $type) !== 1) {
                throw new InvalidArgumentException('The action form MorphTo search is invalid.');
            }

            return $this->ok(['options' => $form->searchMorphToOptions(
                $this->fieldPath($request, '_inlay_morph_options', 'MorphTo field'),
                $type,
                $this->search($request),
            )]);
        }

        if ($request->query->has('_inlay_wizard')) {
            return $this->wizardStep($form, $request);
        }

        if ($request->query->has('_inlay_rich_attachment')) {
            return $this->richEditorAttachment($form, $request);
        }

        if ($request->query->has('_inlay_rich_block')) {
            $block = $request->query('block');
            if (! is_string($block) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $block) !== 1) {
                throw new InvalidArgumentException('The action form rich editor custom block is invalid.');
            }

            return $this->ok([
                'contract' => 'inlay.forms.rich-editor-block.v1',
                'config' => $form
                    ->richEditorField($this->fieldPath($request, '_inlay_rich_block', 'rich editor field'))
                    ->customBlockForm($block)
                    ->validateWithFactory($this->validation, $request->all(), $request),
            ]);
        }

        if ($request->query->has('_inlay_rich_mention')) {
            return $this->richEditorMention($form, $request);
        }

        throw new InvalidArgumentException('The action form request is not a supported sub-transport.');
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    private function wizardStep(Form $form, Request $request): array
    {
        $wizard = $request->query('_inlay_wizard');
        $step = $request->query('step');
        if (
            ! is_string($wizard) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $wizard) !== 1
            || ! is_string($step) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $step) !== 1
        ) {
            throw new InvalidArgumentException('The action form wizard step validation request is invalid.');
        }

        $haltMessage = $form->validateWizardStep(
            $this->container->make(ValidationRunner::class),
            $this->validation,
            $wizard,
            $step,
            $request->all(),
            user: $request->user(),
            options: ['request' => $request],
        );

        if ($haltMessage !== null) {
            return [
                'status' => 409,
                'payload' => [
                    'contract' => 'inlay.forms.wizard-step-validation.v1',
                    'valid' => false,
                    'halted' => true,
                    'message' => $haltMessage,
                ],
            ];
        }

        return $this->ok(['contract' => 'inlay.forms.wizard-step-validation.v1', 'valid' => true]);
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    private function richEditorAttachment(Form $form, Request $request): array
    {
        $path = $this->fieldPath($request, '_inlay_rich_attachment', 'rich editor attachment field');
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw new InvalidArgumentException('An action form attachment request must contain one file.');
        }

        try {
            $attachment = $form->richEditorAttachmentField($path)->storeFileAttachment($file, $request);
        } catch (UploadRejected $exception) {
            return [
                'status' => 422,
                'payload' => [
                    'message' => $exception->validationMessage,
                    'errors' => [$exception->field => [$exception->validationMessage]],
                ],
            ];
        }

        return ['status' => 201, 'payload' => ['contract' => 'inlay.forms.rich-editor-attachment.v1', 'attachment' => $attachment]];
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    private function richEditorMention(Form $form, Request $request): array
    {
        $trigger = $request->query('trigger');
        if (! is_string($trigger) || mb_strlen($trigger) !== 1) {
            throw new InvalidArgumentException('The action form mention request is invalid.');
        }

        $provider = $form
            ->richEditorField($this->fieldPath($request, '_inlay_rich_mention', 'rich editor field'))
            ->mentionProvider($trigger);
        $ids = $request->input('ids');

        if ($ids !== null) {
            if (! is_array($ids) || array_filter($ids, static fn (mixed $id): bool => ! is_string($id) && ! is_int($id)) !== []) {
                throw new InvalidArgumentException('Mention label IDs must be an array of strings or integers.');
            }

            return $this->ok(['contract' => 'inlay.forms.rich-editor-mentions.v1', 'labels' => $provider->labels($ids, $request)]);
        }

        $search = $request->input('search', '');
        if (! is_string($search)) {
            throw new InvalidArgumentException('Mention search must be a string.');
        }

        return $this->ok(['contract' => 'inlay.forms.rich-editor-mentions.v1', 'options' => $provider->search($search, $request)]);
    }

    /** @return array<string, mixed> */
    private function stateUpdate(Form $form, Request $request): array
    {
        $path = $request->input('path');
        $data = $request->input('data');
        $revision = $request->input('revision');
        if (! is_string($path) || ! is_array($data) || ! is_int($revision)) {
            throw new InvalidArgumentException('The action form state update request is invalid.');
        }

        return $form->processStateUpdate(
            $path,
            $request->input('value'),
            $request->input('old'),
            $data,
            $revision,
            $request,
        );
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    private function upload(Form $form, Request $request): array
    {
        $path = $this->fieldPath($request, '_inlay_upload', 'temporary upload field');
        $manager = $this->container->make(TemporaryUploadManager::class);

        try {
            $payload = $manager->receiveRequest($form->temporaryUploadField($path), $path, $request);
        } catch (UploadRejected $exception) {
            return [
                'status' => 422,
                'payload' => [
                    'message' => $exception->validationMessage,
                    'errors' => [$exception->field => [$exception->validationMessage]],
                ],
            ];
        }

        return ['status' => 201, 'payload' => $payload];
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    private function selectOptionAction(Form $form, Request $request): array
    {
        $field = $this->fieldPath($request, '_inlay_field', 'select option action field');
        $action = $request->query('_inlay_select_action');
        $value = $request->query('value');
        if (! is_string($action) || ! in_array($action, ['create', 'edit'], true)) {
            throw new InvalidArgumentException('The select option action is invalid.');
        }
        if ($action === 'edit' && (! is_string($value) || $value === '')) {
            throw new InvalidArgumentException('Edit option actions require a selected value.');
        }

        if ($request->isMethod('get') || $request->isMethod('head')) {
            return $this->ok([
                'contract' => 'inlay.forms.select-option-form.v1',
                'form' => $form->selectOptionActionForm($field, $action, $value, $request, $this->validation),
            ]);
        }

        return $this->ok([
            'contract' => 'inlay.forms.select-option-result.v1',
            'option' => $form->processSelectOptionAction($field, $action, $request->all(), $value, $request, $this->validation),
        ]);
    }

    private function viewName(Request $request): string
    {
        $view = $request->query('_inlay_view');
        if (! is_string($view) || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $view) !== 1) {
            throw new InvalidArgumentException('The deferred schema view name is invalid.');
        }

        return $view;
    }

    private function fieldPath(Request $request, string $key, string $context): string
    {
        $path = $request->query($key);
        if (! is_string($path) || preg_match('/^[A-Za-z0-9_.*-]+$/', $path) !== 1) {
            throw new InvalidArgumentException("The action form {$context} is invalid.");
        }

        return $path;
    }

    private function search(Request $request): string
    {
        $search = $request->query('search', '');
        if (! is_string($search) || mb_strlen($search) > 200) {
            throw new InvalidArgumentException('The action form search must be a string of at most 200 characters.');
        }

        return $search;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function ok(array $payload): array
    {
        return ['status' => 200, 'payload' => $payload];
    }
}
