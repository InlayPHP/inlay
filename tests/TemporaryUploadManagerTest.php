<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Fields\FileUpload;
use Inlay\Forms\Uploads\TemporaryUploadManager;

final class CloudTemporaryUploadFilesystem implements Filesystem
{
    /** @var array<string, string> */
    public array $objects = [];

    public ?string $preparedPath = null;

    public function temporaryUploadUrl($path, $expiration, array $options = []): array
    {
        $this->preparedPath = $path;

        return [
            'url' => 'https://uploads.example.test/signed',
            'headers' => ['X-Upload-Signature' => ['signed-value']],
        ];
    }

    public function mimeType($path): string
    {
        return 'text/plain';
    }

    public function path($path): string
    {
        throw new RuntimeException('Cloud files do not have local paths.');
    }

    public function exists($path): bool
    {
        return isset($this->objects[$path]);
    }

    public function get($path): ?string
    {
        return $this->objects[$path] ?? null;
    }

    public function readStream($path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->objects[$path]);
        rewind($stream);

        return $stream;
    }

    public function put($path, $contents, $options = []): bool
    {
        $this->objects[$path] = is_resource($contents) ? stream_get_contents($contents) : (string) $contents;

        return true;
    }

    public function putFile($path, $file = null, $options = []): string|false
    {
        return false;
    }

    public function putFileAs($path, $file, $name = null, $options = []): string|false
    {
        return false;
    }

    public function writeStream($path, $resource, array $options = []): bool
    {
        return $this->put($path, $resource, $options);
    }

    public function getVisibility($path): string
    {
        return self::VISIBILITY_PRIVATE;
    }

    public function setVisibility($path, $visibility): bool
    {
        return true;
    }

    public function prepend($path, $data): bool
    {
        $this->objects[$path] = $data.($this->objects[$path] ?? '');

        return true;
    }

    public function append($path, $data): bool
    {
        $this->objects[$path] = ($this->objects[$path] ?? '').$data;

        return true;
    }

    public function delete($paths): bool
    {
        foreach ((array) $paths as $path) {
            unset($this->objects[$path]);
        }

        return true;
    }

    public function copy($from, $to): bool
    {
        $this->objects[$to] = $this->objects[$from];

        return true;
    }

    public function move($from, $to): bool
    {
        $this->copy($from, $to);
        unset($this->objects[$from]);

        return true;
    }

    public function size($path): int
    {
        return strlen($this->objects[$path]);
    }

    public function lastModified($path): int
    {
        return time();
    }

    public function files($directory = null, $recursive = false): array
    {
        return array_keys($this->objects);
    }

    public function allFiles($directory = null): array
    {
        return $this->files($directory, true);
    }

    public function directories($directory = null, $recursive = false): array
    {
        return [];
    }

    public function allDirectories($directory = null): array
    {
        return [];
    }

    public function makeDirectory($path): bool
    {
        return true;
    }

    public function deleteDirectory($directory): bool
    {
        return true;
    }
}

final readonly class TemporaryUploadFilesystemFactory implements FilesystemFactory
{
    public function __construct(private Filesystem $filesystem) {}

    public function disk($name = null): Filesystem
    {
        return $this->filesystem;
    }
}

it('prepares confirms materializes and consumes a cloud temporary upload', function (): void {
    $contents = 'cloud upload bytes';
    $disk = new CloudTemporaryUploadFilesystem;
    $manager = new TemporaryUploadManager(new TemporaryUploadFilesystemFactory($disk));
    $field = FileUpload::make('document')
        ->acceptedFileTypes('text/plain')
        ->maxSize(10)
        ->temporaryUploads(expiresAfterMinutes: 15, disk: 'cloud', directToStorage: true);
    $session = new Store('inlay-test', new ArraySessionHandler(120));
    $prepare = Request::create('/documents?_inlay_upload=document', 'POST', [
        'phase' => 'prepare',
        'file' => [
            'name' => 'notes.txt',
            'size' => strlen($contents),
            'mimeType' => 'text/plain',
        ],
    ]);
    $prepare->setLaravelSession($session);

    $intent = $manager->receiveRequest($field, 'document', $prepare);
    expect($intent)
        ->toMatchArray([
            'contract' => 'inlay.forms.direct-temporary-upload.v1',
            'directUpload' => [
                'url' => 'https://uploads.example.test/signed',
                'method' => 'PUT',
                'headers' => ['X-Upload-Signature' => 'signed-value'],
            ],
        ])
        ->and($intent['upload'])->not->toHaveKeys(['path', 'disk'])
        ->and($disk->preparedPath)->toStartWith('inlay-temporary/');

    expect(fn () => $manager->resolve($intent['upload'], 'document', $prepare, $field))
        ->toThrow(UploadRejected::class, 'invalid, expired');

    $disk->objects[$disk->preparedPath] = $contents;
    $confirm = Request::create('/documents?_inlay_upload=document', 'POST', [
        'phase' => 'confirm',
        'temporaryToken' => $intent['upload']['temporaryToken'],
    ]);
    $confirm->setLaravelSession($session);
    $confirmed = $manager->receiveRequest($field, 'document', $confirm);

    expect($confirmed)
        ->toMatchArray([
            'contract' => 'inlay.forms.temporary-upload.v1',
            'upload' => [
                'temporaryToken' => $intent['upload']['temporaryToken'],
                'name' => 'notes.txt',
                'size' => strlen($contents),
                'mimeType' => 'text/plain',
            ],
        ]);

    $resolved = $manager->resolve($confirmed['upload'], 'document', $confirm, $field);
    expect($resolved)->toBeInstanceOf(UploadedFile::class)
        ->and($resolved->getContent())->toBe($contents);
    $materializedPath = $resolved->getPathname();
    expect(is_file($materializedPath))->toBeTrue();

    $manager->consumeResolved($confirm);
    expect($disk->objects)->toBe([])
        ->and(is_file($materializedPath))->toBeFalse();
});
