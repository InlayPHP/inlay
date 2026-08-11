<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

final class MarkdownEditor extends EditorField
{
    protected function type(): string
    {
        return 'markdown-editor';
    }
}
