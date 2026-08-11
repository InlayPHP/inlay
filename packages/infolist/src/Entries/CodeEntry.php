<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries;

use BackedEnum;
use Inlay\Infolists\Entry;
use InvalidArgumentException;
use JsonException;
use Stringable;
use Throwable;
use UnexpectedValueException;

final class CodeEntry extends Entry
{
    private const DEFAULT_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private string $grammar = 'txt';

    private bool $grammarWasExplicitlySet = false;

    private string $lightTheme = 'github-light';

    private string $darkTheme = 'github-dark';

    private int $jsonFlags = self::DEFAULT_JSON_FLAGS;

    private bool $copyable = false;

    private ?string $copyMessage = null;

    private int $copyMessageDuration = 2000;

    protected function type(): string
    {
        return 'code-entry';
    }

    public function grammar(string|BackedEnum $grammar): self
    {
        $this->grammar = $this->normalizeIdentifier($grammar, 'grammar');
        $this->grammarWasExplicitlySet = true;

        return $this;
    }

    public function lightTheme(string|BackedEnum $theme): self
    {
        $this->lightTheme = $this->normalizeIdentifier($theme, 'light theme');

        return $this;
    }

    public function darkTheme(string|BackedEnum $theme): self
    {
        $this->darkTheme = $this->normalizeIdentifier($theme, 'dark theme');

        return $this;
    }

    public function jsonFlags(int $flags): self
    {
        if ($flags < 0) {
            throw new InvalidArgumentException('Code entry JSON flags cannot be negative.');
        }

        $this->jsonFlags = $flags;

        return $this;
    }

    public function copyable(bool $enabled = true): self
    {
        $this->copyable = $enabled;

        return $this;
    }

    public function copyMessage(?string $message): self
    {
        $this->copyMessage = $message;

        return $this;
    }

    public function copyMessageDuration(int $duration): self
    {
        if ($duration < 0) {
            throw new InvalidArgumentException('Code entry copy message duration cannot be negative.');
        }

        $this->copyMessageDuration = $duration;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $state = $this->getStatePath() === ''
            ? $this->default
            : $this->getState($this->default);
        [$source, $structured] = $this->sourceFrom($state);
        $grammar = $structured && ! $this->grammarWasExplicitlySet ? 'json' : $this->grammar;

        return [
            ...parent::jsonSerialize(),
            'grammar' => $grammar,
            'lightTheme' => $this->lightTheme,
            'darkTheme' => $this->darkTheme,
            'jsonFlags' => $this->jsonFlags,
            'copyable' => $this->copyable,
            'copyMessage' => $this->copyMessage,
            'copyMessageDuration' => $this->copyMessageDuration,
            'highlightedSource' => $source,
            'highlightedHtml' => $this->highlight($source, $grammar),
        ];
    }

    /** @return array{string, bool} */
    private function sourceFrom(mixed $state): array
    {
        if (is_array($state) || is_object($state) && ! $state instanceof Stringable) {
            try {
                return [json_encode($state, $this->jsonFlags | JSON_THROW_ON_ERROR), true];
            } catch (JsonException $exception) {
                throw new UnexpectedValueException('Code entry state could not be encoded as JSON.', previous: $exception);
            }
        }

        if ($state === null || is_scalar($state) || $state instanceof Stringable) {
            return [(string) ($state ?? ''), false];
        }

        throw new UnexpectedValueException('Code entry state must be scalar, stringable, an array, or an object.');
    }

    private function highlight(string $source, string $grammar): ?string
    {
        if ($source === '' || ! class_exists(\Phiki\Phiki::class)) {
            return null;
        }

        try {
            return (new \Phiki\Phiki())
                ->codeToHtml($source, $grammar, [
                    'light' => $this->lightTheme,
                    'dark' => $this->darkTheme,
                ])
                ->toString();
        } catch (Throwable $exception) {
            throw new UnexpectedValueException(
                "Code entry highlighting failed for grammar [{$grammar}].",
                previous: $exception,
            );
        }
    }

    private function normalizeIdentifier(string|BackedEnum $identifier, string $description): string
    {
        $identifier = $identifier instanceof BackedEnum ? (string) $identifier->value : trim($identifier);

        if ($identifier === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+:-]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("A code entry {$description} must be a non-empty package identifier.");
        }

        return $identifier;
    }
}
