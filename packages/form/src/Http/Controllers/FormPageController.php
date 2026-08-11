<?php

declare(strict_types=1);

namespace Inlay\Forms\Http\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Form;
use Inlay\Forms\FormPage;
use Inlay\Forms\Uploads\TemporaryUploadManager;
use Inlay\Validation\ValidationRunner;

final class FormPageController
{
    public function __invoke(
        Request $request,
        Container $container,
        ValidationRunner $validationRunner,
        ValidationFactory $validationFactory,
        ?TemporaryUploadManager $temporaryUploads = null,
    ): mixed {
        $pageClass = $request->route()?->getAction('inlayFormPage');

        if (! is_string($pageClass) || ! is_subclass_of($pageClass, FormPage::class)) {
            throw new \LogicException('The route does not contain a valid standalone form page.');
        }

        /** @var FormPage $page */
        $page = $container->make($pageClass);
        if (($request->isMethod('get') || $request->isMethod('head')) && $request->query->has('_inlay_view')) {
            $view = $request->query('_inlay_view');
            if (! is_string($view) || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $view) !== 1) {
                throw new \InvalidArgumentException('The deferred schema view name is invalid.');
            }

            return new JsonResponse($page->resolveForm($request)->resolveDeferredView($view, $request));
        }
        if (! $request->isMethod('get') && ! $request->isMethod('head') && $request->query->has('_inlay_state_update')) {
            return $this->stateUpdateResponse($request, $page->resolveForm($request));
        }
        if (! $request->isMethod('get') && ! $request->isMethod('head') && $request->query->has('_inlay_wizard')) {
            $form = $page->resolveForm($request);
            [$wizard, $step] = $this->wizardStepInput($request);
            $haltMessage = $form->validateWizardStep(
                $validationRunner,
                $validationFactory,
                $wizard,
                $step,
                $request->all(),
                user: $request->user(),
                options: ['request' => $request],
            );
            if ($haltMessage !== null) {
                return new JsonResponse([
                    'contract' => 'inlay.forms.wizard-step-validation.v1',
                    'valid' => false,
                    'halted' => true,
                    'message' => $haltMessage,
                ], 409);
            }

            return new JsonResponse(['contract' => 'inlay.forms.wizard-step-validation.v1', 'valid' => true]);
        }
        if ($request->isMethod('post') && $request->query->has('_inlay_rich_mention')) {
            return $this->mentionResponse($request, $page->resolveForm($request));
        }
        if ($request->isMethod('post') && $request->query->has('_inlay_rich_block')) {
            $fieldPath = $request->query('_inlay_rich_block');
            $block = $request->query('block');
            if (! is_string($fieldPath) || preg_match('/^[A-Za-z0-9_.*-]+$/', $fieldPath) !== 1 || ! is_string($block) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $block) !== 1) {
                throw new \InvalidArgumentException('The rich editor custom block request is invalid.');
            }
            $form = $page->resolveForm($request)->richEditorField($fieldPath)->customBlockForm($block);

            return new JsonResponse([
                'contract' => 'inlay.forms.rich-editor-block.v1',
                'config' => $form->validateWithFactory($validationFactory, $request->all(), $request),
            ]);
        }
        if ($request->isMethod('post') && $request->query->has('_inlay_rich_attachment')) {
            $fieldPath = $request->query('_inlay_rich_attachment');
            $file = $request->file('file');
            if (! is_string($fieldPath) || preg_match('/^[A-Za-z0-9_.*-]+$/', $fieldPath) !== 1 || ! $file instanceof UploadedFile) {
                throw new \InvalidArgumentException('The rich editor attachment request is invalid.');
            }
            try {
                $attachment = $page->resolveForm($request)->richEditorAttachmentField($fieldPath)->storeFileAttachment($file, $request);
            } catch (UploadRejected $exception) {
                return new JsonResponse(['message' => $exception->validationMessage, 'errors' => [$exception->field => [$exception->validationMessage]]], 422);
            }

            return new JsonResponse(['contract' => 'inlay.forms.rich-editor-attachment.v1', 'attachment' => $attachment], 201);
        }
        if ($request->isMethod('post') && $request->query->has('_inlay_upload')) {
            $temporaryUploads ??= $container->make(TemporaryUploadManager::class);
            $fieldPath = $request->query('_inlay_upload');
            if (! is_string($fieldPath) || preg_match('/^[A-Za-z0-9_.*-]+$/', $fieldPath) !== 1) {
                throw new \InvalidArgumentException('The temporary upload field is invalid.');
            }
            $form = $page->resolveForm($request);

            try {
                $payload = $temporaryUploads->receiveRequest(
                    $form->temporaryUploadField($fieldPath),
                    $fieldPath,
                    $request,
                );
            } catch (UploadRejected $exception) {
                return new JsonResponse([
                    'message' => $exception->validationMessage,
                    'errors' => [$exception->field => [$exception->validationMessage]],
                ], 422);
            }

            return new JsonResponse($payload, 201);
        }
        if ($request->query->has('_inlay_select_action')) {
            [$field, $action, $value] = $this->optionActionInput($request);
            $form = $page->resolveForm($request);
            if ($request->isMethod('get') || $request->isMethod('head')) {
                return new JsonResponse([
                    'contract' => 'inlay.forms.select-option-form.v1',
                    'form' => $form->selectOptionActionForm($field, $action, $value, $request, $validationFactory),
                ]);
            }

            return new JsonResponse([
                'contract' => 'inlay.forms.select-option-result.v1',
                'option' => $form->processSelectOptionAction($field, $action, $request->all(), $value, $request, $validationFactory),
            ]);
        }
        if ($request->isMethod('get') && $request->query->has('_inlay_options')) {
            $field = $request->query('_inlay_options');
            $search = $request->query('search', '');
            if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1) {
                throw new \InvalidArgumentException('The remote select field is invalid.');
            }
            if (! is_string($search) || mb_strlen($search) > 200) {
                throw new \InvalidArgumentException('The remote select search must be a string of at most 200 characters.');
            }

            return new JsonResponse([
                'options' => $page->resolveForm($request)->searchSelectOptions($field, $search, $request),
            ]);
        }
        if ($request->isMethod('get') && $request->query->has('_inlay_morph_options')) {
            $field = $request->query('_inlay_morph_options');
            $type = $request->query('type');
            $search = $request->query('search', '');
            if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1 || ! is_string($type) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $type) !== 1 || ! is_string($search) || mb_strlen($search) > 200) {
                throw new \InvalidArgumentException('The remote MorphTo search is invalid.');
            }

            return new JsonResponse(['options' => $page->resolveForm($request)->searchMorphToOptions($field, $type, $search)]);
        }
        if ($request->isMethod('get') || $request->isMethod('head')) {
            $forms = $page->resolveForms($request);

            return Inertia::render($pageClass::component(), [
                ...$page->resolveProps($request),
                'form' => array_values($forms)[0],
                'forms' => $forms,
            ]);
        }

        $form = $page->resolveForm($request);
        if ($temporaryUploads === null && $form->hasTemporaryUploads()) {
            $temporaryUploads = $container->make(TemporaryUploadManager::class);
        }

        return $page->processForm($request, $validationRunner, $validationFactory, $temporaryUploads);
    }

    /** @return array{string, 'create'|'edit', string|int|null} */
    private function optionActionInput(Request $request): array
    {
        $field = $request->query('_inlay_field');
        $action = $request->query('_inlay_select_action');
        $value = $request->query('value');
        if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1) {
            throw new \InvalidArgumentException('The select option action field is invalid.');
        }
        if (! is_string($action) || ! in_array($action, ['create', 'edit'], true)) {
            throw new \InvalidArgumentException('The select option action is invalid.');
        }
        if ($action === 'edit' && (! is_string($value) || $value === '')) {
            throw new \InvalidArgumentException('Edit option actions require a selected value.');
        }

        return [$field, $action, $value];
    }

    private function stateUpdateResponse(Request $request, Form $form): JsonResponse
    {
        $path = $request->input('path');
        $data = $request->input('data');
        $revision = $request->input('revision');
        if (! is_string($path) || ! is_array($data) || ! is_int($revision)) {
            throw new \InvalidArgumentException('The state update request is invalid.');
        }

        return new JsonResponse($form->processStateUpdate(
            $path,
            $request->input('value'),
            $request->input('old'),
            $data,
            $revision,
            $request,
        ));
    }

    private function mentionResponse(Request $request, Form $form): JsonResponse
    {
        $field = $request->query('_inlay_rich_mention');
        $trigger = $request->query('trigger');
        if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1 || ! is_string($trigger) || mb_strlen($trigger) !== 1) {
            throw new \InvalidArgumentException('The rich editor mention request is invalid.');
        }
        $provider = $form->richEditorField($field)->mentionProvider($trigger);
        $ids = $request->input('ids');
        if ($ids !== null) {
            if (! is_array($ids) || array_filter($ids, static fn (mixed $id): bool => ! is_string($id) && ! is_int($id)) !== []) {
                throw new \InvalidArgumentException('Mention label IDs must be an array of strings or integers.');
            }

            return new JsonResponse(['contract' => 'inlay.forms.rich-editor-mentions.v1', 'labels' => $provider->labels($ids, $request)]);
        }
        $search = $request->input('search', '');
        if (! is_string($search)) {
            throw new \InvalidArgumentException('Mention search must be a string.');
        }

        return new JsonResponse(['contract' => 'inlay.forms.rich-editor-mentions.v1', 'options' => $provider->search($search, $request)]);
    }

    /** @return array{string, string} */
    private function wizardStepInput(Request $request): array
    {
        $wizard = $request->query('_inlay_wizard');
        $step = $request->query('step');
        if (! is_string($wizard) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $wizard) !== 1 || ! is_string($step) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $step) !== 1) {
            throw new \InvalidArgumentException('The wizard step validation request is invalid.');
        }

        return [$wizard, $step];
    }
}
