<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

final class CodeEditor extends EditorField
{
    protected function type(): string
    {
        return 'code-editor';
    }
}
