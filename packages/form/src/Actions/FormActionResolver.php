<?php

declare(strict_types=1);

namespace Inlay\Forms\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Actions\Contracts\ActionFormResolver;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Form;
use Inlay\Forms\Uploads\TemporaryUploadManager;

final readonly class FormActionResolver implements ActionFormResolver
{
    public function __construct(
        private ValidationFactory $validation,
        private Container $container,
    ) {}

    public function mount(Action $action, array $schema, array $data, Request $request, Collection $records): array
    {
        return $this->form($action, $schema, $data, $records)->jsonSerialize();
    }

    public function validate(Action $action, array $schema, array $data, Request $request, Collection $records): array
    {
        $form = $this->form($action, $schema, $data, $records);
        $manager = $form->hasTemporaryUploads() ? $this->container->make(TemporaryUploadManager::class) : null;

        if ($manager !== null) {
            try {
                $data = $form->resolveTemporaryUploads($data, $request, $manager);
            } catch (UploadRejected $exception) {
                $validator = $this->validation->make([], []);
                $validator->errors()->add($exception->field, $exception->validationMessage);

                throw new ValidationException($validator);
            }
        }

        try {
            $validated = $form->validateWithFactory($this->validation, $data, $request);
            $validated = $form->storeUploadedFiles($validated, $request);
        } catch (\Throwable $exception) {
            $manager?->cleanupMaterialized($request);

            throw $exception;
        }
        $manager?->consumeResolved($request);

        return $validated;
    }

    public function subRequest(Action $action, array $schema, array $data, Request $request, Collection $records): array
    {
        return (new ActionFormSubRequest($this->validation, $this->container))
            ->handle($this->form($action, $schema, $data, $records), $request);
    }

    public function handlesSubRequest(Request $request): bool
    {
        return ActionFormSubRequest::applies($request);
    }

    /** @param list<mixed> $schema @param array<string, mixed> $data */
    private function form(Action $action, array $schema, array $data, Collection $records): Form
    {
        $form = Form::make("action.{$action->name()}")
            ->schema($schema)
            ->data($data)
            ->method($action->methodValue());

        $record = $records->first();

        if ($action->urlValue() !== null) {
            $url = self::bindRecordTokens($action->urlValue(), $record);
            $form->action($url)->subRequestEndpoint(Action::formEndpointUrl($url));
        }

        if ($record instanceof Model) {
            $form->model($record);
        }

        return $form;
    }

    /**
     * Resolve the `{key}` placeholders a row action URL carries, so an open
     * form and its sub-transports address one concrete record.
     */
    private static function bindRecordTokens(string $url, mixed $record): string
    {
        if ($record === null || ! str_contains($url, '{')) {
            return $url;
        }

        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use ($record): string {
                $value = match (true) {
                    $record instanceof Model => $record->getAttribute($matches[1]),
                    is_array($record) => $record[$matches[1]] ?? null,
                    is_object($record) => $record->{$matches[1]} ?? null,
                    default => null,
                };

                return is_string($value) || is_int($value) ? rawurlencode((string) $value) : $matches[0];
            },
            $url,
        );
    }
}
