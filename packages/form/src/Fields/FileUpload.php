<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Field;
use Inlay\Forms\Fields\FileUpload\FileUploadEntry;
use Inlay\Support\ClosureEvaluator;

final class FileUpload extends Field
{
    private bool $multiple = false;

    private bool $image = false;

    /** @var list<string> */
    private array $acceptedFileTypes = [];

    private ?int $minSize = null;

    private ?int $maxSize = null;

    private ?int $maxFiles = null;

    private bool $previewable = true;

    private bool $openable = false;

    private bool $downloadable = false;

    private bool $removable = true;

    private bool $reorderable = false;

    private bool $appendFiles = false;

    private bool $storeFiles = false;

    private ?string $disk = null;

    private ?string $directory = null;

    private string $visibility = 'private';

    private ?Closure $scanUploadedFileUsing = null;

    private ?Closure $saveUploadedFileUsing = null;

    private ?Closure $quarantineUploadedFileUsing = null;

    private ?Closure $deleteRemovedFilesUsing = null;

    private string $scanFailureMessage = 'The uploaded file did not pass the required security checks.';

    private bool $temporaryUploads = false;

    private string $temporaryUploadDisk = 'local';

    private int $temporaryUploadExpiryMinutes = 60;

    private ?string $temporaryUploadEndpoint = null;

    private bool $directToTemporaryStorage = false;

    private bool $avatar = false;

    private bool $imageEditor = false;

    /** @var list<string|null> */
    private array $imageEditorAspectRatioOptions = [null, '16:9', '4:3', '1:1'];

    private int $imageEditorMode = 1;

    private string $imageEditorEmptyFillColor = 'transparent';

    private ?int $imageEditorViewportWidth = null;

    private ?int $imageEditorViewportHeight = null;

    private bool $circleCropper = false;

    private ?string $imageAspectRatio = null;

    private bool $automaticallyOpenImageEditorForAspectRatio = false;

    /** @var list<FileUploadEntry> */
    private array $existingFiles = [];

    protected function type(): string
    {
        return 'file-upload';
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function image(bool $image = true): self
    {
        $this->image = $image;

        return $this;
    }

    public function avatar(bool $avatar = true): self
    {
        $this->avatar = $avatar;
        if ($avatar) {
            $this->image = true;
        }

        return $this;
    }

    public function imageEditor(bool $enabled = true): self
    {
        $this->imageEditor = $enabled;
        if ($enabled) {
            $this->image = true;
        }

        return $this;
    }

    /** @param list<string|null> $ratios */
    public function imageEditorAspectRatioOptions(array $ratios): self
    {
        if ($ratios === []) {
            throw new \InvalidArgumentException('Image editor aspect ratio options cannot be empty.');
        }
        foreach ($ratios as $ratio) {
            if ($ratio !== null) {
                $this->assertAspectRatio($ratio);
            }
        }
        $this->imageEditorAspectRatioOptions = array_values(array_unique($ratios, SORT_REGULAR));

        return $this;
    }

    public function imageEditorMode(int $mode): self
    {
        if (! in_array($mode, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Image editor mode must be 1, 2, or 3.');
        }
        $this->imageEditorMode = $mode;

        return $this;
    }

    public function imageEditorEmptyFillColor(string $color): self
    {
        if ($color !== 'transparent' && preg_match('/^#[0-9a-f]{6}(?:[0-9a-f]{2})?$/i', $color) !== 1) {
            throw new \InvalidArgumentException('Image editor fill colors must be transparent or a six/eight-digit hex color.');
        }
        $this->imageEditorEmptyFillColor = strtolower($color);

        return $this;
    }

    public function imageEditorViewportWidth(int|string $width): self
    {
        $this->imageEditorViewportWidth = $this->normalizeImageDimension($width, 'width');

        return $this;
    }

    public function imageEditorViewportHeight(int|string $height): self
    {
        $this->imageEditorViewportHeight = $this->normalizeImageDimension($height, 'height');

        return $this;
    }

    public function circleCropper(bool $enabled = true): self
    {
        $this->circleCropper = $enabled;

        return $this;
    }

    public function imageAspectRatio(string $ratio): self
    {
        $this->assertAspectRatio($ratio);
        $this->imageAspectRatio = $ratio;
        $this->image = true;

        return $this;
    }

    public function automaticallyOpenImageEditorForAspectRatio(bool $enabled = true): self
    {
        $this->automaticallyOpenImageEditorForAspectRatio = $enabled;

        return $this;
    }

    public function acceptedFileTypes(string ...$types): self
    {
        foreach ($types as $type) {
            if (! preg_match('#^(?:\.[a-z0-9]+|[a-z0-9][a-z0-9.+-]*/(?:\*|[a-z0-9][a-z0-9.+-]*))$#i', $type)) {
                throw new \InvalidArgumentException("Invalid accepted file type [{$type}].");
            }
        }

        $this->acceptedFileTypes = array_values(array_unique($types));

        return $this;
    }

    public function minSize(int $kilobytes): self
    {
        if ($kilobytes < 0) {
            throw new \InvalidArgumentException('Minimum upload size cannot be negative.');
        }

        $this->minSize = $kilobytes;

        return $this;
    }

    public function maxSize(int $kilobytes): self
    {
        if ($kilobytes < 1) {
            throw new \InvalidArgumentException('Maximum upload size must be at least one kilobyte.');
        }

        $this->maxSize = $kilobytes;

        return $this;
    }

    public function maxFiles(int $count): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Maximum upload file count must be at least one.');
        }

        $this->maxFiles = $count;

        return $this;
    }

    public function previewable(bool $previewable = true): self
    {
        $this->previewable = $previewable;

        return $this;
    }

    public function openable(bool $openable = true): self
    {
        $this->openable = $openable;

        return $this;
    }

    public function downloadable(bool $downloadable = true): self
    {
        $this->downloadable = $downloadable;

        return $this;
    }

    public function removable(bool $removable = true): self
    {
        $this->removable = $removable;

        return $this;
    }

    public function reorderable(bool $reorderable = true): self
    {
        $this->reorderable = $reorderable;

        return $this;
    }

    public function appendFiles(bool $append = true): self
    {
        $this->appendFiles = $append;

        return $this;
    }

    public function storeFiles(bool $store = true): self
    {
        $this->storeFiles = $store;

        return $this;
    }

    public function disk(?string $disk): self
    {
        if ($disk !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $disk) !== 1) {
            throw new \InvalidArgumentException('Upload disk names may contain only letters, numbers, dots, underscores, and hyphens.');
        }

        $this->disk = $disk;

        return $this;
    }

    public function directory(?string $directory): self
    {
        if ($directory !== null) {
            $directory = trim($directory, '/');
            if ($directory === '' || str_contains($directory, '..') || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $directory) !== 1) {
                throw new \InvalidArgumentException('Upload directories must be safe relative filesystem paths.');
            }
        }

        $this->directory = $directory;

        return $this;
    }

    public function visibility(string $visibility): self
    {
        if (! in_array($visibility, ['private', 'public'], true)) {
            throw new \InvalidArgumentException('Upload visibility must be private or public.');
        }

        $this->visibility = $visibility;

        return $this;
    }

    public function scanUploadedFileUsing(Closure $callback): self
    {
        $this->scanUploadedFileUsing = $callback;

        return $this;
    }

    public function saveUploadedFileUsing(Closure $callback): self
    {
        $this->saveUploadedFileUsing = $callback;

        return $this;
    }

    /**
     * Hand a stored file to a scanner that finishes later.
     *
     * `scanUploadedFileUsing()` must decide before the response; this runs
     * after storage, so the work can be queued. Return a replacement path to
     * move the file into quarantine, or null to leave it where it is.
     */
    public function quarantineUploadedFileUsing(Closure $callback): self
    {
        $this->quarantineUploadedFileUsing = $callback;

        return $this;
    }

    /**
     * Clean up files the visitor removed.
     *
     * The callback receives the paths that were attached to the record but are
     * absent from the submission, so deletion can be queued rather than
     * blocking the request.
     */
    public function deleteRemovedFilesUsing(Closure $callback): self
    {
        $this->deleteRemovedFilesUsing = $callback;

        return $this;
    }

    public function scanFailureMessage(string $message): self
    {
        if (trim($message) === '') {
            throw new \InvalidArgumentException('The upload scan failure message cannot be empty.');
        }

        $this->scanFailureMessage = trim($message);

        return $this;
    }

    public function temporaryUploads(
        bool $enabled = true,
        int $expiresAfterMinutes = 60,
        string $disk = 'local',
        bool $directToStorage = false,
    ): self {
        if ($expiresAfterMinutes < 1 || $expiresAfterMinutes > 1440) {
            throw new \InvalidArgumentException('Temporary upload expiry must be between 1 and 1440 minutes.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $disk) !== 1) {
            throw new \InvalidArgumentException('Temporary upload disk names may contain only letters, numbers, dots, underscores, and hyphens.');
        }
        $this->temporaryUploads = $enabled;
        $this->temporaryUploadExpiryMinutes = $expiresAfterMinutes;
        $this->temporaryUploadDisk = $disk;
        $this->directToTemporaryStorage = $enabled && $directToStorage;

        return $this;
    }

    public function directToTemporaryStorage(bool $enabled = true): self
    {
        $this->temporaryUploads = $enabled || $this->temporaryUploads;
        $this->directToTemporaryStorage = $enabled;

        return $this;
    }

    public function configureTemporaryUploadEndpoint(?string $endpoint): void
    {
        $this->temporaryUploadEndpoint = $endpoint;
    }

    public function usesTemporaryUploads(): bool
    {
        return $this->temporaryUploads;
    }

    public function temporaryUploadDisk(): string
    {
        return $this->temporaryUploadDisk;
    }

    public function temporaryUploadExpiryMinutes(): int
    {
        return $this->temporaryUploadExpiryMinutes;
    }

    public function usesDirectTemporaryUploads(): bool
    {
        return $this->temporaryUploads && $this->directToTemporaryStorage;
    }

    public function validateTemporaryUpload(UploadedFile $file): void
    {
        $this->validateTemporaryUploadMetadata(
            $file->getClientOriginalName(),
            $file->getSize(),
            $file->getMimeType() ?: 'application/octet-stream',
        );
    }

    public function validateTemporaryUploadMetadata(string $name, int $size, string $mimeType): void
    {
        $mime = strtolower(trim($mimeType));
        $extension = strtolower('.'.pathinfo($name, PATHINFO_EXTENSION));
        $accepted = $this->acceptedFileTypes === [];
        foreach ($this->acceptedFileTypes as $type) {
            $matches = str_starts_with($type, '.')
                ? $extension === strtolower($type)
                : (str_ends_with($type, '/*')
                    ? str_starts_with($mime, strtolower(substr($type, 0, -1)))
                    : $mime === strtolower($type));
            if ($matches) {
                $accepted = true;
                break;
            }
        }
        if (trim($name) === '' || $size < 0 || $mime === '' || ($this->minSize !== null && $size < $this->minSize * 1024) || ($this->maxSize !== null && $size > $this->maxSize * 1024) || ($this->image && ! str_starts_with($mime, 'image/')) || ! $accepted) {
            throw new UploadRejected($this->name(), 'The selected file does not satisfy the upload requirements.');
        }
    }

    /** @param array<string, mixed> $data */
    public function storeUploadedState(mixed $state, array $data, ?Request $request = null): mixed
    {
        if (! $this->storeFiles) {
            return $state;
        }

        $this->deleteRemovedFiles($state, $data, $request);

        if ($this->multiple) {
            if (! is_array($state)) {
                return $state;
            }

            foreach ($state as $file) {
                $this->scanUploadedFile($file, $data, $request);
            }

            return array_map(fn (mixed $file): mixed => $this->persistUploadedFile($file, $data, $request), $state);
        }

        $this->scanUploadedFile($state, $data, $request);

        return $this->persistUploadedFile($state, $data, $request);
    }

    public function existingFile(FileUploadEntry $file): self
    {
        $this->existingFiles[] = $file;

        return $this;
    }

    /** @param list<FileUploadEntry> $files */
    public function existingFiles(array $files): self
    {
        foreach ($files as $file) {
            if (! $file instanceof FileUploadEntry) {
                throw new \InvalidArgumentException('Existing uploads must be '.FileUploadEntry::class.' instances.');
            }
        }

        $this->existingFiles = array_values($files);

        return $this;
    }

    public function jsonSerialize(): array
    {
        if ($this->minSize !== null && $this->maxSize !== null && $this->minSize > $this->maxSize) {
            throw new \LogicException('Minimum upload size cannot exceed maximum upload size.');
        }

        if (! $this->multiple && $this->maxFiles !== null && $this->maxFiles > 1) {
            throw new \LogicException('Call multiple() before allowing more than one uploaded file.');
        }
        if ($this->circleCropper && ! $this->imageEditor) {
            throw new \LogicException('Circle cropping requires imageEditor().');
        }
        if ($this->automaticallyOpenImageEditorForAspectRatio && $this->imageAspectRatio === null) {
            throw new \LogicException('Automatic image editing requires imageAspectRatio().');
        }
        if ($this->automaticallyOpenImageEditorForAspectRatio && $this->multiple) {
            throw new \LogicException('Automatic aspect-ratio editing is only available for single uploads.');
        }

        return [
            ...parent::jsonSerialize(),
            'multiple' => $this->multiple,
            'image' => $this->image,
            'avatar' => $this->avatar,
            'imageEditor' => $this->imageEditor,
            'imageEditorAspectRatioOptions' => $this->imageEditorAspectRatioOptions,
            'imageEditorMode' => $this->imageEditorMode,
            'imageEditorEmptyFillColor' => $this->imageEditorEmptyFillColor,
            'imageEditorViewportWidth' => $this->imageEditorViewportWidth,
            'imageEditorViewportHeight' => $this->imageEditorViewportHeight,
            'circleCropper' => $this->circleCropper,
            'imageAspectRatio' => $this->imageAspectRatio,
            'automaticallyOpenImageEditorForAspectRatio' => $this->automaticallyOpenImageEditorForAspectRatio,
            'acceptedFileTypes' => $this->acceptedFileTypes,
            'minSize' => $this->minSize,
            'maxSize' => $this->maxSize,
            'maxFiles' => $this->maxFiles,
            'previewable' => $this->previewable,
            'openable' => $this->openable,
            'downloadable' => $this->downloadable,
            'removable' => $this->removable,
            'reorderable' => $this->reorderable,
            'appendFiles' => $this->appendFiles,
            'storesFiles' => $this->storeFiles,
            'temporaryUpload' => $this->temporaryUploads ? [
                'url' => $this->temporaryUploadEndpoint,
                'expiresAfterMinutes' => $this->temporaryUploadExpiryMinutes,
                'directToStorage' => $this->directToTemporaryStorage,
            ] : null,
            'existingFiles' => $this->existingFiles,
        ];
    }

    /** @param array<string, mixed> $data */
    private function scanUploadedFile(mixed $file, array $data, ?Request $request): void
    {
        if ($file instanceof UploadedFile && $this->scanUploadedFileUsing !== null) {
            $accepted = ClosureEvaluator::evaluate($this->scanUploadedFileUsing, [
                'component' => $this,
                'data' => $data,
                'file' => $file,
                'request' => $request,
            ], [self::class => $this, UploadedFile::class => $file], [$file, $this, $data, $request]);
            if ($accepted === false) {
                throw new UploadRejected($this->name(), $this->scanFailureMessage);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function persistUploadedFile(mixed $file, array $data, ?Request $request): mixed
    {
        if (! $file instanceof UploadedFile) {
            return $file;
        }

        if ($this->saveUploadedFileUsing !== null) {
            $stored = ClosureEvaluator::evaluate($this->saveUploadedFileUsing, [
                'component' => $this,
                'data' => $data,
                'disk' => $this->disk,
                'directory' => $this->directory,
                'file' => $file,
                'request' => $request,
                'visibility' => $this->visibility,
            ], [self::class => $this, UploadedFile::class => $file], [$file, $this, $data, $request]);
            if (! is_string($stored) || trim($stored) === '') {
                throw new \UnexpectedValueException('Custom upload storage callbacks must return a non-empty opaque identifier or path.');
            }

            return $this->quarantineStoredFile($stored, $data, $request);
        }

        $extension = $file->extension();
        $filename = Str::random(40).($extension === '' ? '' : '.'.$extension);
        $path = $file->storeAs($this->directory ?? '', $filename, [
            'disk' => $this->disk,
            'visibility' => $this->visibility,
        ]);
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException("The uploaded file for [{$this->name()}] could not be stored.");
        }

        return $this->quarantineStoredFile($path, $data, $request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function quarantineStoredFile(string $path, array $data, ?Request $request): string
    {
        if ($this->quarantineUploadedFileUsing === null) {
            return $path;
        }

        $moved = ClosureEvaluator::evaluate($this->quarantineUploadedFileUsing, [
            'component' => $this,
            'data' => $data,
            'disk' => $this->disk,
            'path' => $path,
            'request' => $request,
        ], [self::class => $this], [$path, $this, $data, $request]);

        if ($moved === null) {
            return $path;
        }
        if (! is_string($moved) || trim($moved) === '') {
            throw new \UnexpectedValueException('Quarantine callbacks must return a non-empty path or null.');
        }

        return $moved;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deleteRemovedFiles(mixed $state, array $data, ?Request $request): void
    {
        if ($this->deleteRemovedFilesUsing === null || $this->existingFiles === []) {
            return;
        }

        $kept = array_map('strval', array_filter(
            is_array($state) ? $state : [$state],
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));

        $removed = array_values(array_filter(
            array_map(static fn (FileUploadEntry $entry): string => $entry->id(), $this->existingFiles),
            static fn (string $path): bool => ! in_array($path, $kept, true),
        ));

        if ($removed === []) {
            return;
        }

        ClosureEvaluator::evaluate($this->deleteRemovedFilesUsing, [
            'component' => $this,
            'data' => $data,
            'disk' => $this->disk,
            'paths' => $removed,
            'request' => $request,
        ], [self::class => $this], [$removed, $this, $data, $request]);
    }

    private function assertAspectRatio(string $ratio): void
    {
        if (preg_match('/^(?:[1-9][0-9]*)(?:\.[0-9]+)?:[1-9][0-9]*(?:\.[0-9]+)?$/', $ratio) !== 1) {
            throw new \InvalidArgumentException("Invalid image aspect ratio [{$ratio}].");
        }
    }

    private function normalizeImageDimension(int|string $dimension, string $label): int
    {
        if (is_string($dimension) && preg_match('/^[0-9]+$/', $dimension) !== 1) {
            throw new \InvalidArgumentException("Image editor viewport {$label} must be a positive integer.");
        }
        $dimension = (int) $dimension;
        if ($dimension < 1 || $dimension > 10000) {
            throw new \InvalidArgumentException("Image editor viewport {$label} must be between 1 and 10000 pixels.");
        }

        return $dimension;
    }
}
