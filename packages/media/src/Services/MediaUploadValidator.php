<?php

declare(strict_types=1);

namespace Inlay\Media\Services;

use Illuminate\Http\UploadedFile;
use Inlay\Media\Exceptions\MediaValidationException;

final class MediaUploadValidator
{
    /**
     * @param  list<string>  $mimeTypes
     * @param  list<string>  $extensions
     */
    public function validate(UploadedFile $file, int $maxSizeKb, array $mimeTypes, array $extensions): void
    {
        $errors = [];
        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        if (! $file->isValid()) {
            $errors['file'][] = 'The upload did not complete successfully.';
        }

        if ($file->getSize() === false || $file->getSize() > ($maxSizeKb * 1024)) {
            $errors['size'][] = "The file must not exceed {$maxSizeKb} KB.";
        }

        if (! in_array($mimeType, array_map('strtolower', $mimeTypes), true)) {
            $errors['mime_type'][] = "The detected MIME type [{$mimeType}] is not allowed.";
        }

        if ($extension === '' || ! in_array($extension, array_map('strtolower', $extensions), true)) {
            $errors['extension'][] = "The detected extension [{$extension}] is not allowed.";
        }

        if ($errors !== []) {
            throw new MediaValidationException($errors);
        }
    }
}
