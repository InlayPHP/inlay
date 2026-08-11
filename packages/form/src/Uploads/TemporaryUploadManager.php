<?php

declare(strict_types=1);

namespace Inlay\Forms\Uploads;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Fields\FileUpload;
use RuntimeException;

final class TemporaryUploadManager
{
    private const SESSION_KEY = '_inlay_temporary_uploads';

    private const MATERIALIZED_ATTRIBUTE = '_inlay_materialized_temporary_uploads';

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    /**
     * Receive either the existing multipart transport or one phase of the
     * cloud-native direct-upload transport.
     *
     * @return array<string, mixed>
     */
    public function receiveRequest(FileUpload $field, string $path, Request $request): array
    {
        $file = $request->file('file');
        if ($file instanceof UploadedFile) {
            return [
                'contract' => 'inlay.forms.temporary-upload.v1',
                'upload' => $this->receive($field, $path, $file, $request),
            ];
        }

        if (! $field->usesDirectTemporaryUploads()) {
            throw new \InvalidArgumentException('A temporary upload request must contain one file.');
        }

        return match ($request->input('phase')) {
            'prepare' => $this->prepareDirectUpload($field, $path, $request),
            'confirm' => $this->confirmDirectUpload($field, $path, $request),
            default => throw new \InvalidArgumentException('A direct temporary upload request has an invalid phase.'),
        };
    }

    /** @return array{temporaryToken: string, name: string, size: int, mimeType: string} */
    public function receive(FileUpload $field, string $path, UploadedFile $file, Request $request): array
    {
        $this->assertSession($request);
        $this->prune($request);
        $field->validateTemporaryUpload($file);

        $token = Str::random(64);
        $extension = $file->extension();
        $storedPath = $file->storeAs(
            'inlay-temporary',
            Str::random(40).($extension === '' ? '' : '.'.$extension),
            ['disk' => $field->temporaryUploadDisk(), 'visibility' => 'private'],
        );
        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('The temporary upload could not be stored.');
        }

        $uploads = $request->session()->get(self::SESSION_KEY, []);
        $uploads[$token] = [
            'disk' => $field->temporaryUploadDisk(),
            'expiresAt' => time() + ($field->temporaryUploadExpiryMinutes() * 60),
            'field' => $path,
            'mimeType' => $file->getMimeType() ?: 'application/octet-stream',
            'name' => $file->getClientOriginalName(),
            'path' => $storedPath,
            'size' => $file->getSize(),
            'confirmed' => true,
            'direct' => false,
        ];
        $request->session()->put(self::SESSION_KEY, $uploads);

        return $this->publicMetadata($token, $uploads[$token]);
    }

    public function resolve(mixed $value, string $field, Request $request, FileUpload $component): mixed
    {
        if (! is_array($value) || ! isset($value['temporaryToken']) || ! is_string($value['temporaryToken'])) {
            return $value;
        }

        $this->assertSession($request);
        $this->prune($request);
        $token = $value['temporaryToken'];
        $metadata = $request->session()->get(self::SESSION_KEY.'.'.$token);
        $isUnconfirmedDirectUpload = is_array($metadata)
            && ($metadata['direct'] ?? false) === true
            && ($metadata['confirmed'] ?? false) !== true;
        if (! is_array($metadata) || ! isset($metadata['field'], $metadata['disk'], $metadata['path'], $metadata['name'], $metadata['mimeType']) || $isUnconfirmedDirectUpload || ! $this->fieldMatches((string) $metadata['field'], $field)) {
            throw new UploadRejected($field, 'The temporary upload is invalid, expired, or belongs to another field.');
        }

        $disk = $this->filesystems->disk((string) $metadata['disk']);
        if (! $disk->exists((string) $metadata['path'])) {
            throw new UploadRejected($field, 'The temporary upload is no longer available.');
        }
        $materializedPath = $this->materialize($disk, (string) $metadata['path'], $request);
        $request->attributes->set('_inlay_temporary_upload_tokens', [
            ...$request->attributes->get('_inlay_temporary_upload_tokens', []),
            $token,
        ]);

        $upload = new UploadedFile(
            $materializedPath,
            (string) $metadata['name'],
            (string) $metadata['mimeType'],
            null,
            true,
        );
        $component->validateTemporaryUpload($upload);

        return $upload;
    }

    public function consumeResolved(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $uploads = $request->session()->get(self::SESSION_KEY, []);
        foreach ($request->attributes->get('_inlay_temporary_upload_tokens', []) as $token) {
            if (! is_string($token) || ! isset($uploads[$token])) {
                continue;
            }
            $this->filesystems->disk($uploads[$token]['disk'])->delete($uploads[$token]['path']);
            unset($uploads[$token]);
        }
        $request->session()->put(self::SESSION_KEY, $uploads);
        $this->cleanupMaterialized($request);
    }

    public function cleanupMaterialized(Request $request): void
    {
        foreach ($request->attributes->get(self::MATERIALIZED_ATTRIBUTE, []) as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
        $request->attributes->remove(self::MATERIALIZED_ATTRIBUTE);
    }

    public function prune(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $uploads = $request->session()->get(self::SESSION_KEY, []);
        foreach ($uploads as $token => $metadata) {
            if (is_array($metadata) && ($metadata['expiresAt'] ?? 0) > time()) {
                continue;
            }
            if (isset($metadata['disk'], $metadata['path'])) {
                $this->filesystems->disk($metadata['disk'])->delete($metadata['path']);
            }
            unset($uploads[$token]);
        }
        $request->session()->put(self::SESSION_KEY, $uploads);
    }

    private function assertSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new \LogicException('Temporary uploads require session middleware on the form route.');
        }
    }

    private function fieldMatches(string $pattern, string $field): bool
    {
        $quoted = preg_quote($pattern, '#');
        $expression = '#^'.str_replace('\\*', '[0-9]+', $quoted).'$#';

        return preg_match($expression, $field) === 1;
    }

    /** @return array<string, mixed> */
    private function prepareDirectUpload(FileUpload $field, string $path, Request $request): array
    {
        $this->assertSession($request);
        $this->prune($request);

        $file = $request->input('file');
        if (! is_array($file) || ! is_string($file['name'] ?? null) || ! is_int($file['size'] ?? null) || ! is_string($file['mimeType'] ?? null)) {
            throw new \InvalidArgumentException('Direct temporary upload metadata is invalid.');
        }

        $name = trim($file['name']);
        $size = $file['size'];
        $mimeType = trim($file['mimeType']) ?: 'application/octet-stream';
        $field->validateTemporaryUploadMetadata($name, $size, $mimeType);

        $token = Str::random(64);
        $storedPath = $this->temporaryPath($name);
        $expiresAt = time() + ($field->temporaryUploadExpiryMinutes() * 60);
        $disk = $this->filesystems->disk($field->temporaryUploadDisk());

        try {
            $intent = $disk->temporaryUploadUrl(
                $storedPath,
                now()->addMinutes($field->temporaryUploadExpiryMinutes()),
                ['ContentType' => $mimeType],
            );
        } catch (\Throwable $exception) {
            throw new \LogicException("Temporary disk [{$field->temporaryUploadDisk()}] could not create a direct upload URL.", previous: $exception);
        }

        if (! is_array($intent) || ! is_string($intent['url'] ?? null) || trim($intent['url']) === '' || ! is_array($intent['headers'] ?? null)) {
            throw new RuntimeException('The temporary disk returned an invalid direct upload intent.');
        }

        $uploads = $request->session()->get(self::SESSION_KEY, []);
        $uploads[$token] = [
            'disk' => $field->temporaryUploadDisk(),
            'expiresAt' => $expiresAt,
            'field' => $path,
            'mimeType' => $mimeType,
            'name' => $name,
            'path' => $storedPath,
            'size' => $size,
            'confirmed' => false,
            'direct' => true,
        ];
        $request->session()->put(self::SESSION_KEY, $uploads);

        return [
            'contract' => 'inlay.forms.direct-temporary-upload.v1',
            'upload' => $this->publicMetadata($token, $uploads[$token]),
            'directUpload' => [
                'url' => $intent['url'],
                'method' => 'PUT',
                'headers' => $this->normalizeHeaders($intent['headers']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function confirmDirectUpload(FileUpload $field, string $path, Request $request): array
    {
        $this->assertSession($request);
        $this->prune($request);

        $token = $request->input('temporaryToken');
        $uploads = $request->session()->get(self::SESSION_KEY, []);
        $metadata = is_string($token) ? ($uploads[$token] ?? null) : null;
        if (! is_string($token) || ! is_array($metadata) || ($metadata['direct'] ?? false) !== true || ! isset($metadata['field'], $metadata['disk'], $metadata['path'], $metadata['name'], $metadata['mimeType'], $metadata['size']) || ! $this->fieldMatches((string) $metadata['field'], $path) || ! hash_equals($field->temporaryUploadDisk(), (string) $metadata['disk'])) {
            throw new UploadRejected($field, 'The direct temporary upload is invalid, expired, or belongs to another field.');
        }

        $disk = $this->filesystems->disk((string) $metadata['disk']);
        if (! $disk->exists((string) $metadata['path'])) {
            throw new UploadRejected($field, 'The direct temporary upload has not reached storage.');
        }

        $actualSize = $disk->size((string) $metadata['path']);
        if (! is_int($actualSize) || $actualSize !== (int) $metadata['size']) {
            $disk->delete((string) $metadata['path']);
            unset($uploads[$token]);
            $request->session()->put(self::SESSION_KEY, $uploads);

            throw new UploadRejected($field, 'The direct temporary upload size does not match the prepared file.');
        }

        $actualMimeType = $disk->mimeType((string) $metadata['path']);
        if (is_string($actualMimeType) && $actualMimeType !== '') {
            $metadata['mimeType'] = $actualMimeType;
        }
        $field->validateTemporaryUploadMetadata(
            (string) $metadata['name'],
            $actualSize,
            (string) $metadata['mimeType'],
        );

        $metadata['confirmed'] = true;
        $uploads[$token] = $metadata;
        $request->session()->put(self::SESSION_KEY, $uploads);

        return [
            'contract' => 'inlay.forms.temporary-upload.v1',
            'upload' => $this->publicMetadata($token, $metadata),
        ];
    }

    /** @return array{temporaryToken: string, name: string, size: int, mimeType: string} */
    private function publicMetadata(string $token, array $metadata): array
    {
        return [
            'temporaryToken' => $token,
            'name' => (string) $metadata['name'],
            'size' => (int) $metadata['size'],
            'mimeType' => (string) $metadata['mimeType'],
        ];
    }

    private function temporaryPath(string $name): string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,16}$/', $extension) === 1 ? '.'.$extension : '';

        return 'inlay-temporary/'.Str::random(40).$extension;
    }

    /** @param array<array-key, mixed> $headers @return array<string, string> */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                throw new RuntimeException('The temporary disk returned an invalid direct upload header.');
            }
            $values = is_array($value) ? $value : [$value];
            if ($values === [] || array_filter($values, static fn (mixed $item): bool => ! is_string($item) || str_contains($item, "\r") || str_contains($item, "\n")) !== []) {
                throw new RuntimeException('The temporary disk returned an invalid direct upload header value.');
            }
            $normalized[$name] = implode(', ', $values);
        }

        return $normalized;
    }

    private function materialize(object $disk, string $path, Request $request): string
    {
        if (method_exists($disk, 'path')) {
            try {
                $localPath = $disk->path($path);
                if (is_string($localPath) && is_file($localPath)) {
                    return $localPath;
                }
            } catch (\Throwable) {
                // Cloud adapters intentionally do not expose a local path.
            }
        }

        $source = $disk->readStream($path);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'inlay-upload-');
        if (! is_resource($source) || ! is_string($temporaryPath)) {
            if (is_resource($source)) {
                fclose($source);
            }
            throw new RuntimeException('The temporary upload could not be materialized for validation.');
        }

        $destination = fopen($temporaryPath, 'wb');
        if (! is_resource($destination)) {
            fclose($source);
            @unlink($temporaryPath);
            throw new RuntimeException('The temporary upload could not be materialized for validation.');
        }

        $copied = stream_copy_to_stream($source, $destination);
        fclose($source);
        fclose($destination);
        if ($copied === false) {
            @unlink($temporaryPath);
            throw new RuntimeException('The temporary upload could not be materialized for validation.');
        }

        $request->attributes->set(self::MATERIALIZED_ATTRIBUTE, [
            ...$request->attributes->get(self::MATERIALIZED_ATTRIBUTE, []),
            $temporaryPath,
        ]);

        return $temporaryPath;
    }
}
