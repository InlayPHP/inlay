<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use Inlay\Forms\Form;

final class FormWidget extends Widget
{
    private ?Form $form = null;

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function form(Form $form): self
    {
        $this->form = $form;

        return $this;
    }

    protected function type(): string
    {
        return 'form';
    }

    protected function payload(): array
    {
        return ['form' => $this->form];
    }
}
