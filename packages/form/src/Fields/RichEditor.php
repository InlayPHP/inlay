<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Fields\RichEditor\MentionProvider;
use Inlay\Forms\Fields\RichEditor\RichContentCustomBlock;
use Inlay\Forms\Form;
use Inlay\Support\ClosureEvaluator;

final class RichEditor extends EditorField
{
    /** @var list<list<string>> */
    /** @var list<string> */
    private array $floatingToolbarButtons = [];

    private array $toolbarButtons = [
        ['bold', 'italic', 'underline', 'strike', 'link'],
        ['h2', 'h3'],
        ['alignStart', 'alignCenter', 'alignEnd'],
        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
        ['undo', 'redo'],
    ];

    private string $contentMode = 'html';

    private bool $fileAttachments = false;

    /** @var list<string> */
    private array $acceptedFileTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private int $maxFileSize = 5120;

    private string $fileAttachmentsDisk = 'public';

    private string $fileAttachmentsDirectory = 'rich-editor-attachments';

    private string $fileAttachmentsVisibility = 'public';

    private ?string $fileAttachmentEndpoint = null;

    private ?Closure $saveUploadedFileAttachmentUsing = null;

    /** @var list<array{class: class-string<RichContentCustomBlock>, group: string|null}> */
    private array $customBlocks = [];

    private ?string $customBlockEndpoint = null;

    private string $customBlockMethod = 'post';

    /** @var list<array{name: string, label: string}> */
    private array $mergeTags = [];

    /** @var list<MentionProvider> */
    private array $mentionProviders = [];

    protected function type(): string
    {
        return 'rich-editor';
    }

    public function json(bool $enabled = true): self
    {
        $this->contentMode = $enabled ? 'json' : 'html';

        return $this;
    }

    public function fileAttachments(bool $enabled = true): self
    {
        $this->fileAttachments = $enabled;

        return $this;
    }

    public function acceptedFileTypes(string ...$types): self
    {
        if ($types === [] || array_filter($types, static fn (string $type): bool => preg_match('#^(?:[a-z0-9.+-]+/[a-z0-9.*+-]+|\.[a-z0-9]+)$#i', $type) !== 1) !== []) {
            throw new \InvalidArgumentException('Rich editor attachment types must be MIME types, wildcards, or file extensions.');
        }
        $this->acceptedFileTypes = array_values(array_unique(array_map('strtolower', $types)));

        return $this;
    }

    public function maxFileSize(int $kilobytes): self
    {
        if ($kilobytes < 1) {
            throw new \InvalidArgumentException('Rich editor attachment size must be at least one kilobyte.');
        }
        $this->maxFileSize = $kilobytes;

        return $this;
    }

    public function fileAttachmentsDisk(string $disk): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $disk) !== 1) {
            throw new \InvalidArgumentException('Rich editor attachment disk names contain invalid characters.');
        }
        $this->fileAttachmentsDisk = $disk;

        return $this;
    }

    public function fileAttachmentsDirectory(string $directory): self
    {
        $directory = trim($directory, '/');
        if ($directory === '' || str_contains($directory, '..') || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $directory) !== 1) {
            throw new \InvalidArgumentException('Rich editor attachment directories must be safe relative paths.');
        }
        $this->fileAttachmentsDirectory = $directory;

        return $this;
    }

    public function fileAttachmentsVisibility(string $visibility): self
    {
        if (! in_array($visibility, ['private', 'public'], true)) {
            throw new \InvalidArgumentException('Rich editor attachment visibility must be private or public.');
        }
        $this->fileAttachmentsVisibility = $visibility;

        return $this;
    }

    public function saveUploadedFileAttachmentUsing(Closure $callback): self
    {
        $this->saveUploadedFileAttachmentUsing = $callback;

        return $this;
    }

    public function usesFileAttachments(): bool
    {
        return $this->fileAttachments;
    }

    /** @param array<int|string, class-string<RichContentCustomBlock>|list<class-string<RichContentCustomBlock>>> $blocks */
    public function customBlocks(array $blocks): self
    {
        $normalized = [];
        foreach ($blocks as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $group = trim($key);
                if ($group === '' || $value === []) {
                    throw new \InvalidArgumentException('Rich editor custom block groups require a label and at least one block.');
                }
                foreach ($value as $block) {
                    $normalized[] = ['class' => $this->assertCustomBlock($block), 'group' => $group];
                }

                continue;
            }
            if (! is_string($value)) {
                throw new \InvalidArgumentException('Rich editor custom blocks must be custom block class names.');
            }
            $normalized[] = ['class' => $this->assertCustomBlock($value), 'group' => null];
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('Rich editor custom blocks cannot be empty.');
        }
        $ids = array_map(static fn (array $block): string => $block['class']::getId(), $normalized);
        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Rich editor custom block IDs must be unique.');
        }
        $this->customBlocks = $normalized;

        return $this;
    }

    public function usesCustomBlocks(): bool
    {
        return $this->customBlocks !== [];
    }

    /** @param array<int|string, string> $tags */
    public function mergeTags(array $tags): self
    {
        if ($tags === []) {
            throw new \InvalidArgumentException('Rich editor merge tags cannot be empty.');
        }

        $normalized = [];
        foreach ($tags as $key => $value) {
            $name = is_int($key) ? $value : $key;
            $label = is_int($key)
                ? Str::headline(str_replace('.', ' ', $name))
                : trim($value);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name) !== 1 || $label === '') {
                throw new \InvalidArgumentException('Rich editor merge tags require stable names and non-empty labels.');
            }
            $normalized[] = ['name' => $name, 'label' => $label];
        }
        $names = array_column($normalized, 'name');
        if (count($names) !== count(array_unique($names))) {
            throw new \InvalidArgumentException('Rich editor merge tag names must be unique.');
        }
        $this->mergeTags = $normalized;

        return $this;
    }

    /** @param list<MentionProvider> $providers */
    public function mentions(array $providers): self
    {
        if ($providers === [] || array_filter($providers, static fn (mixed $provider): bool => ! $provider instanceof MentionProvider) !== []) {
            throw new \InvalidArgumentException('Rich editor mentions require one or more MentionProvider instances.');
        }
        $triggers = array_map(static fn (MentionProvider $provider): string => $provider->trigger(), $providers);
        if (count($triggers) !== count(array_unique($triggers))) {
            throw new \InvalidArgumentException('Rich editor mention triggers must be unique.');
        }
        $this->mentionProviders = array_values($providers);

        return $this;
    }

    public function usesMentions(): bool
    {
        return $this->mentionProviders !== [];
    }

    public function configureMentionEndpoint(?string $endpoint, string $method = 'post'): void
    {
        foreach ($this->mentionProviders as $provider) {
            if ($endpoint === null) {
                $provider->configureEndpoint(null, $method);

                continue;
            }
            $separator = str_contains($endpoint, '?') ? '&' : '?';
            $provider->configureEndpoint($endpoint.$separator.'trigger='.rawurlencode($provider->trigger()), $method);
        }
    }

    public function mentionProvider(string $trigger): MentionProvider
    {
        foreach ($this->mentionProviders as $provider) {
            if (hash_equals($provider->trigger(), $trigger)) {
                return $provider;
            }
        }

        throw new \InvalidArgumentException("Unknown rich editor mention provider [{$trigger}].");
    }

    public function configureCustomBlockEndpoint(?string $endpoint, string $method = 'post'): void
    {
        $this->customBlockEndpoint = $endpoint;
        $this->customBlockMethod = $method;
    }

    public function customBlockForm(string $id, array $data = []): Form
    {
        foreach ($this->customBlocks as $block) {
            if (hash_equals($block['class']::getId(), $id)) {
                return $block['class']::editorDefinition($this->blockEndpoint($id), $data, $this->customBlockMethod)['form'];
            }
        }

        throw new \InvalidArgumentException("Unknown rich editor custom block [{$id}].");
    }

    public function configureFileAttachmentEndpoint(?string $endpoint): void
    {
        $this->fileAttachmentEndpoint = $endpoint;
    }

    /** @return array{url: string, name: string, size: int, mimeType: string} */
    public function storeFileAttachment(UploadedFile $file, ?Request $request = null): array
    {
        $this->validateFileAttachment($file);
        $url = $this->saveUploadedFileAttachmentUsing === null
            ? $this->storeFileAttachmentByDefault($file)
            : ClosureEvaluator::evaluate($this->saveUploadedFileAttachmentUsing, [
                'component' => $this,
                'file' => $file,
                'request' => $request,
            ], [self::class => $this, UploadedFile::class => $file], [$file, $this, $request]);
        if (! is_string($url) || trim($url) === '') {
            throw new \RuntimeException('A rich editor attachment uploader must return a non-empty URL.');
        }

        return ['url' => $url, 'name' => $file->getClientOriginalName(), 'size' => (int) $file->getSize(), 'mimeType' => strtolower($file->getMimeType() ?: 'application/octet-stream')];
    }

    /** @param list<list<string>|string> $groups */
    public function toolbarButtons(array $groups): self
    {
        if ($groups === []) {
            throw new \InvalidArgumentException('Rich editor toolbar buttons cannot be empty.');
        }

        $normalized = [];
        foreach ($groups as $group) {
            $buttons = is_string($group) ? [$group] : $group;
            if ($buttons === []) {
                throw new \InvalidArgumentException('Rich editor toolbar groups cannot be empty.');
            }
            foreach ($buttons as $button) {
                $this->assertToolName($button);
            }
            $normalized[] = array_values(array_unique($buttons));
        }

        $this->toolbarButtons = $normalized;

        return $this;
    }

    /**
     * Offer a short toolbar next to the selection.
     *
     * This is a bubble toolbar. The buttons are validated the same
     * way the main toolbar's are, so the two cannot drift apart.
     *
     * @param  list<string>  $buttons
     */
    public function floatingToolbarButtons(array $buttons): self
    {
        if ($buttons === []) {
            throw new \InvalidArgumentException('Rich editor floating toolbar buttons cannot be empty.');
        }

        foreach ($buttons as $button) {
            $this->assertToolName($button);
        }

        $this->floatingToolbarButtons = array_values(array_unique($buttons));

        return $this;
    }

    /** @param list<string> $buttons */
    public function disableToolbarButtons(array $buttons): self
    {
        foreach ($buttons as $button) {
            $this->assertToolName($button);
        }

        $disabled = array_fill_keys($buttons, true);
        $this->toolbarButtons = array_values(array_filter(
            array_map(
                static fn (array $group): array => array_values(array_filter(
                    $group,
                    static fn (string $button): bool => ! isset($disabled[$button]),
                )),
                $this->toolbarButtons,
            ),
            static fn (array $group): bool => $group !== [],
        ));

        return $this;
    }

    public function jsonSerialize(): array
    {
        $toolbar = $this->toolbarButtons;
        if ($this->fileAttachments && ! in_array('attachFiles', array_merge(...$toolbar), true)) {
            $toolbar[] = ['attachFiles'];
        }
        if ($this->customBlocks !== [] && ! in_array('customBlocks', array_merge(...$toolbar), true)) {
            $toolbar[] = ['customBlocks'];
        }
        if ($this->mergeTags !== [] && ! in_array('mergeTags', array_merge(...$toolbar), true)) {
            $toolbar[] = ['mergeTags'];
        }

        $blocks = array_map(function (array $block): array {
            $definition = $block['class']::editorDefinition($this->blockEndpoint($block['class']::getId()), method: $this->customBlockMethod);

            return [
                'id' => $definition['id'],
                'label' => $definition['label'],
                'icon' => $definition['icon'],
                'group' => $block['group'],
                'modalHeading' => $definition['modalHeading'],
                'form' => $definition['form']->jsonSerialize(),
            ];
        }, $this->customBlocks);

        return [
            ...parent::jsonSerialize(),
            'contentMode' => $this->contentMode,
            'toolbarButtons' => $toolbar,
            'floatingToolbarButtons' => array_values(array_filter(
                $this->floatingToolbarButtons,
                // A button the main toolbar disabled is not offered here either.
                fn (string $button): bool => in_array($button, array_merge(...$toolbar), true),
            )),
            'fileAttachments' => $this->fileAttachments ? [
                'url' => $this->fileAttachmentEndpoint,
                'acceptedFileTypes' => $this->acceptedFileTypes,
                'maxSize' => $this->maxFileSize,
            ] : null,
            'customBlocks' => $blocks,
            'mergeTags' => $this->mergeTags,
            'mentions' => array_map(
                static fn (MentionProvider $provider): array => $provider->jsonSerialize(),
                $this->mentionProviders,
            ),
        ];
    }

    /** @return class-string<RichContentCustomBlock> */
    private function assertCustomBlock(mixed $block): string
    {
        if (! is_string($block) || ! is_subclass_of($block, RichContentCustomBlock::class)) {
            throw new \InvalidArgumentException('Rich editor custom blocks must extend '.RichContentCustomBlock::class.'.');
        }

        return $block;
    }

    private function blockEndpoint(string $id): ?string
    {
        if ($this->customBlockEndpoint === null) {
            return null;
        }
        $separator = str_contains($this->customBlockEndpoint, '?') ? '&' : '?';

        return $this->customBlockEndpoint.$separator.'block='.rawurlencode($id);
    }

    private function validateFileAttachment(UploadedFile $file): void
    {
        $mime = strtolower($file->getMimeType() ?: 'application/octet-stream');
        $extension = strtolower('.'.$file->getClientOriginalExtension());
        $accepted = false;
        foreach ($this->acceptedFileTypes as $type) {
            if (str_starts_with($type, '.') ? $extension === $type : (str_ends_with($type, '/*') ? str_starts_with($mime, substr($type, 0, -1)) : $mime === $type)) {
                $accepted = true;
                break;
            }
        }
        if (! $accepted || $file->getSize() > $this->maxFileSize * 1024) {
            throw new UploadRejected($this->name(), 'The rich editor attachment does not satisfy the upload requirements.');
        }
    }

    private function storeFileAttachmentByDefault(UploadedFile $file): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $filename = Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);
        $path = $file->storeAs($this->fileAttachmentsDirectory, $filename, ['disk' => $this->fileAttachmentsDisk, 'visibility' => $this->fileAttachmentsVisibility]);
        if (! is_string($path)) {
            throw new \RuntimeException('The rich editor attachment could not be stored.');
        }

        return Storage::disk($this->fileAttachmentsDisk)->url($path);
    }

    private function assertToolName(mixed $tool): void
    {
        if (! is_string($tool) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $tool) !== 1) {
            throw new \InvalidArgumentException('Rich editor toolbar button names must be stable identifiers.');
        }
    }
}
