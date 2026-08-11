<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields\RichEditor;

use Illuminate\Contracts\Support\Htmlable;
use Inlay\Forms\Form;

abstract class RichContentCustomBlock
{
    abstract public static function getId(): string;

    abstract public static function getLabel(): string;

    public static function getIcon(): ?string
    {
        return null;
    }

    public static function getModalHeading(): string
    {
        return 'Configure '.static::getLabel();
    }

    public static function getSubmitLabel(): string
    {
        return 'Save block';
    }

    public static function configureEditorForm(Form $form): Form
    {
        return $form;
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $data */
    public static function toHtml(array $config, array $data = []): Htmlable|string
    {
        return '';
    }

    /** @return array{id: string, label: string, icon: string|null, modalHeading: string, form: Form} */
    final public static function editorDefinition(?string $endpoint = null, array $data = [], string $method = 'post'): array
    {
        $id = static::getId();
        $label = trim(static::getLabel());
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) !== 1 || $label === '') {
            throw new \LogicException('Rich content custom blocks require a stable ID and non-empty label.');
        }
        $form = Form::make('rich-content-block-'.$id)
            ->method($method)
            ->submitLabel(static::getSubmitLabel())
            ->data($data);
        if ($endpoint !== null) {
            $form->action($endpoint);
        }
        $form = static::configureEditorForm($form);

        return [
            'id' => $id,
            'label' => $label,
            'icon' => static::getIcon(),
            'modalHeading' => static::getModalHeading(),
            'form' => $form,
        ];
    }
}
