<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Inlay\Infolists\Concerns\HasTextPresentation;
use Inlay\Infolists\Entry;
use Inlay\Schemas\Support\RichContent;
use Inlay\Support\SafeUrl;
use InvalidArgumentException;

final class TextEntry extends Entry
{
    use HasTextPresentation;

    private bool|Closure $badge = false;

    private bool|Closure $prose = false;

    private bool|Closure $list = false;

    private bool|Closure $bulleted = false;

    private int|Closure|null $listLimit = null;

    private bool|Closure $expandableLimitedList = false;

    private string|Closure $separator = ',';

    private int|Closure|null $lineClamp = null;

    private bool $html = false;

    private bool $markdown = false;

    private string|\Closure|null $icon = null;

    private string|\Closure|null $iconColor = null;

    private string $iconPosition = 'before';

    private string|Closure|null $timezone = null;

    private int|Closure|null $limit = null;

    private string|Closure|null $limitEnd = '…';

    private string|Closure|null $prefix = null;

    private string|Closure|null $suffix = null;

    /** @var array<string, mixed>|null */
    private ?array $format = null;

    private bool $url = false;

    private string|Closure|null $urlValue = null;

    private bool|Closure $openUrlInNewTab = false;

    private bool|Closure $copyable = false;

    private string|Closure|null $copyableState = null;

    private bool $since = false;

    private string|Closure|null $sinceTimezone = null;

    private bool|\Closure $wrap = true;

    private int|Closure|null $words = null;

    private string|Closure|null $wordsEnd = '…';

    private string|Closure|null $copyMessage = null;

    private int|Closure $copyMessageDuration = 2000;

    private ?Closure $formatStateUsing = null;

    /** @var array{function: 'avg'|'max'|'min'|'sum', relationship: string|array|Closure|null, column: string|Expression|Closure|null}|null */
    private ?array $relationshipAggregate = null;

    /** @var string|array<string, \Closure>|list<string>|Closure|null */
    private string|array|Closure|null $relationshipCounts = null;

    protected function type(): string
    {
        return 'text-entry';
    }

    public function badge(bool|Closure $enabled = true): self
    {
        $this->badge = $enabled;

        return $this;
    }

    /** Apply readable prose styling to rich text content. */
    public function prose(bool|Closure $enabled = true): self
    {
        $this->prose = $enabled;

        return $this;
    }

    public function list(bool|Closure $enabled = true): self
    {
        $this->list = $enabled;

        return $this;
    }

    public function listWithLineBreaks(bool|Closure $enabled = true): self
    {
        return $this->list($enabled);
    }

    public function bulleted(bool|Closure $enabled = true): self
    {
        $this->bulleted = $enabled;
        if ($enabled === true) {
            $this->list = true;
        }

        return $this;
    }

    public function separator(string|Closure $separator): self
    {
        if (is_string($separator) && $separator === '') {
            throw new InvalidArgumentException('A text entry separator cannot be empty.');
        }

        $this->separator = $separator;

        return $this;
    }

    public function limitList(int|Closure|null $items = 3): self
    {
        if (is_int($items) && $items < 1) {
            throw new InvalidArgumentException('A text entry list limit must be at least 1.');
        }

        $this->listLimit = $items;

        return $this;
    }

    public function expandableLimitedList(bool|Closure $enabled = true): self
    {
        $this->expandableLimitedList = $enabled;

        return $this;
    }

    public function lineClamp(int|Closure|null $lines): self
    {
        if (is_int($lines) && ($lines < 1 || $lines > 6)) {
            throw new InvalidArgumentException('A text entry line clamp must be between 1 and 6.');
        }

        $this->lineClamp = $lines;

        return $this;
    }

    public function html(bool $enabled = true): self
    {
        $this->html = $enabled;
        if ($enabled) {
            $this->markdown = false;
        }

        return $this;
    }

    public function markdown(bool $enabled = true): self
    {
        $this->markdown = $enabled;
        if ($enabled) {
            $this->html = false;
        }

        return $this;
    }

    public function limit(int|Closure|null $characters, string|Closure|null $end = '…'): self
    {
        if (is_int($characters) && $characters < 1) {
            throw new InvalidArgumentException('A text entry limit must be at least one character.');
        }

        $this->limit = $characters;
        $this->limitEnd = $end;

        return $this;
    }

    public function prefix(string|Closure|null $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string|Closure|null $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function date(string|Closure|null $format = 'Y-m-d', string|Closure|null $timezone = null): self
    {
        $this->formatStateUsing = null;
        $this->since = false;
        $this->sinceTimezone = null;
        $this->format = ['type' => 'date', 'format' => $format, 'timezone' => $timezone ?? $this->timezone];

        return $this;
    }

    public function dateTime(string|Closure|null $format = 'Y-m-d H:i', string|Closure|null $timezone = null): self
    {
        return $this->date($format, $timezone);
    }

    public function time(string|Closure|null $format = 'H:i:s', string|Closure|null $timezone = null): self
    {
        return $this->date($format, $timezone);
    }

    /** compatible ISO date convenience method. */
    public function isoDate(string|Closure|null $format = null, string|Closure|null $timezone = null): self
    {
        return $this->date($format ?? 'Y-m-d', $timezone);
    }

    /** compatible ISO date-time convenience method. */
    public function isoDateTime(string|Closure|null $format = null, string|Closure|null $timezone = null): self
    {
        return $this->date($format ?? 'Y-m-d H:i:s', $timezone);
    }

    /** compatible ISO time convenience method. */
    public function isoTime(string|Closure|null $format = null, string|Closure|null $timezone = null): self
    {
        return $this->date($format ?? 'H:i:s', $timezone);
    }

    /**
     * Render the raw date value as the entry tooltip.
     *
     * The callback receives the entry state through the normal schema
     * evaluator, so date tooltips stay useful inside repeatable records too.
     */
    public function dateTooltip(string|Closure|null $format = 'Y-m-d', string|Closure|null $timezone = null): self
    {
        $this->tooltip(function (TextEntry $component, mixed $state) use ($format, $timezone): ?string {
            return $component->formatDateTooltip($state, $format, $timezone, 'Y-m-d');
        });

        return $this;
    }

    public function dateTimeTooltip(string|Closure|null $format = 'Y-m-d H:i', string|Closure|null $timezone = null): self
    {
        $this->tooltip(function (TextEntry $component, mixed $state) use ($format, $timezone): ?string {
            return $component->formatDateTooltip($state, $format, $timezone, 'Y-m-d H:i');
        });

        return $this;
    }

    public function timeTooltip(string|Closure|null $format = 'H:i:s', string|Closure|null $timezone = null): self
    {
        $this->tooltip(function (TextEntry $component, mixed $state) use ($format, $timezone): ?string {
            return $component->formatDateTooltip($state, $format, $timezone, 'H:i:s');
        });

        return $this;
    }

    public function isoDateTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): self
    {
        return $this->dateTooltip($format ?? 'Y-m-d', $timezone);
    }

    public function isoDateTimeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): self
    {
        return $this->dateTooltip($format ?? 'Y-m-d H:i:s', $timezone);
    }

    public function isoTimeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): self
    {
        return $this->dateTooltip($format ?? 'H:i:s', $timezone);
    }

    public function timezone(string|Closure|null $timezone): self
    {
        if (is_string($timezone)) {
            $this->assertTimezone($timezone);
        }

        $this->timezone = $timezone;
        if (($this->format['type'] ?? null) === 'date') {
            $this->format['timezone'] = $timezone;
        }

        return $this;
    }

    /**
     * Show how long ago the value was, rather than when it happened.
     *
     * Table columns already do this; an infolist showing the same timestamp
     * should read the same way.
     */
    public function since(bool|string|Closure|null $timezone = true): self
    {
        if ($timezone === false) {
            $this->since = false;
            $this->sinceTimezone = null;

            return $this;
        }

        $this->formatStateUsing = null;
        $this->format = null;
        $this->since = true;
        $this->sinceTimezone = $timezone === true ? null : $timezone;

        return $this;
    }

    /** Render the relative timestamp as the entry tooltip. */
    public function sinceTooltip(string|Closure|null $timezone = null): self
    {
        $this->tooltip(function (TextEntry $component, mixed $state) use ($timezone): ?string {
            return $component->formatSince($state, $timezone);
        });

        return $this;
    }

    /** Allow long text to wrap within the available entry width. */
    public function wrap(bool|\Closure $enabled = true): self
    {
        $this->wrap = $enabled;

        return $this;
    }

    /** Truncate to whole words instead of characters. */
    public function words(int|Closure|null $words, string|Closure|null $end = '…'): self
    {
        if (is_int($words) && ($words < 1 || $words > 200)) {
            throw new \InvalidArgumentException('A text entry word limit must be between 1 and 200.');
        }

        $this->words = $words;
        $this->wordsEnd = $end;

        return $this;
    }

    public function number(int|Closure $decimalPlaces = 0, string|Closure|null $locale = null): self
    {
        if (is_int($decimalPlaces) && $decimalPlaces < 0) {
            throw new InvalidArgumentException('Number decimal places cannot be negative.');
        }

        $this->formatStateUsing = null;
        $this->since = false;
        $this->format = ['type' => 'number', 'decimalPlaces' => $decimalPlaces, 'locale' => $locale];

        return $this;
    }

    public function numeric(int|Closure $decimalPlaces = 0, string|Closure|null $locale = null): self
    {
        return $this->number($decimalPlaces, $locale);
    }

    public function money(string|Closure $currency, int|Closure $decimalPlaces = 2, string|Closure|null $locale = null, int|float|Closure $divideBy = 1): self
    {
        if (is_string($currency)) {
            $currency = strtoupper(trim($currency));

            if ($currency === '') {
                throw new InvalidArgumentException('A money currency cannot be empty.');
            }
        }

        if (is_int($decimalPlaces) && $decimalPlaces < 0) {
            throw new InvalidArgumentException('Money decimal places cannot be negative.');
        }

        if ($divideBy instanceof Closure) {
            // The resolved value is validated when the schema is serialized.
        } elseif ($divideBy <= 0) {
            throw new InvalidArgumentException('Money divideBy must be greater than zero.');
        }

        $this->formatStateUsing = null;
        $this->since = false;
        $this->sinceTimezone = null;
        $this->format = [
            'type' => 'money',
            'currency' => $currency,
            'decimalPlaces' => $decimalPlaces,
            'locale' => $locale,
            ...($divideBy instanceof Closure || $divideBy !== 1 ? ['divideBy' => $divideBy] : []),
        ];

        return $this;
    }

    public function icon(string|\Closure|null $icon): self
    {
        if (is_string($icon) && trim($icon) === '') {
            throw new InvalidArgumentException('A text entry icon cannot be empty.');
        }

        $this->icon = $icon;

        return $this;
    }

    public function iconColor(string|\Closure|null $color): self
    {
        if (is_string($color) && trim($color) === '') {
            throw new InvalidArgumentException('A text entry icon color cannot be empty.');
        }

        $this->iconColor = $color;

        return $this;
    }

    public function iconPosition(string $position): self
    {
        if (! in_array($position, ['before', 'after'], true)) {
            throw new InvalidArgumentException("Unsupported text entry icon position [{$position}].");
        }

        $this->iconPosition = $position;

        return $this;
    }

    public function url(string|Closure|null $url = null, bool|Closure $newTab = false): self
    {
        $this->url = true;
        if (is_string($url)) {
            $url = SafeUrl::from($url)->value();
        }
        $this->urlValue = $url;
        $this->openUrlInNewTab = $newTab;

        return $this;
    }

    /** Configure whether a URL should open in a new browser tab. */
    public function openUrlInNewTab(bool|Closure $enabled = true): self
    {
        $this->openUrlInNewTab = $enabled;

        return $this;
    }

    public function copyable(bool|Closure $enabled = true, string|Closure|null $message = null, int|Closure $duration = 2000): self
    {
        if (is_int($duration) && $duration < 0) {
            throw new InvalidArgumentException('Copy message duration cannot be negative.');
        }

        $this->copyable = $enabled;
        $this->copyMessage = $message;
        $this->copyMessageDuration = $duration;

        return $this;
    }

    /** Configure the message shown after copying the current entry value. */
    public function copyMessage(string|Closure|null $message): self
    {
        $this->copyMessage = $message;

        return $this;
    }

    /** Configure how long the copy message remains visible, in milliseconds. */
    public function copyMessageDuration(int|Closure $duration): self
    {
        if (is_int($duration) && $duration < 0) {
            throw new InvalidArgumentException('Copy message duration cannot be negative.');
        }

        $this->copyMessageDuration = $duration;

        return $this;
    }

    /** Override the value sent to the clipboard without changing display text. */
    public function copyableState(string|Closure|null $state): self
    {
        $this->copyableState = $state;

        return $this;
    }

    /**
     * Replace the entry's built-in date/number formatter with application-owned
     * server formatting. The callback receives the concrete state value, even
     * when the entry is nested inside one or more repeatables.
     */
    public function formatStateUsing(?Closure $callback): self
    {
        $this->formatStateUsing = $callback;
        if ($callback !== null) {
            $this->format = null;
            $this->since = false;
            $this->sinceTimezone = null;
        }

        return $this;
    }

    public function hasCustomStateFormatter(): bool
    {
        return $this->formatStateUsing !== null;
    }

    public function shouldTransformStateForDisplay(): bool
    {
        return $this->formatStateUsing !== null || $this->html || $this->markdown || $this->since;
    }

    public function formatState(mixed $state): mixed
    {
        $formatted = $this->formatStateUsing === null
            ? ($this->since ? $this->formatSince($state, $this->sinceTimezone) : $state)
            : $this->evaluate(
                $this->formatStateUsing,
                ['state' => $state],
                positional: [$state],
            );

        if ($formatted instanceof BackedEnum) {
            $formatted = $formatted->value;
        }
        if ($formatted instanceof \Stringable) {
            $formatted = (string) $formatted;
        }
        if (is_array($formatted) && $this->formatStateUsing !== null) {
            $formatted = json_encode($formatted, JSON_THROW_ON_ERROR);
        }
        if (! is_scalar($formatted) && $formatted !== null) {
            throw new \UnexpectedValueException('Text entry formatStateUsing callbacks must return a scalar, array, stringable value, backed enum, or null.');
        }

        if ($this->html || $this->markdown) {
            if (! is_scalar($formatted) && $formatted !== null) {
                throw new \UnexpectedValueException('Rich text entry formatters must resolve to a scalar, stringable value, backed enum, or null.');
            }

            $content = (string) ($formatted ?? '');

            return $this->markdown
                ? RichContent::markdownToHtml($content)
                : RichContent::sanitizeHtml($content);
        }

        return $formatted;
    }

    /** @param string|array|Closure|null $relationship */
    public function avg(string|array|Closure|null $relationship, string|Expression|Closure|null $column): self
    {
        return $this->relationshipAggregate('avg', $relationship, $column);
    }

    /** @param string|array|Closure|null $relationship */
    public function max(string|array|Closure|null $relationship, string|Expression|Closure|null $column): self
    {
        return $this->relationshipAggregate('max', $relationship, $column);
    }

    /** @param string|array|Closure|null $relationship */
    public function min(string|array|Closure|null $relationship, string|Expression|Closure|null $column): self
    {
        return $this->relationshipAggregate('min', $relationship, $column);
    }

    /** @param string|array|Closure|null $relationship */
    public function sum(string|array|Closure|null $relationship, string|Expression|Closure|null $column): self
    {
        return $this->relationshipAggregate('sum', $relationship, $column);
    }

    /** @return array{function: 'avg'|'max'|'min'|'sum', relationship: string|array|null, column: string|Expression|null}|null */
    public function relationshipAggregateDefinition(): ?array
    {
        if ($this->relationshipAggregate === null) {
            return null;
        }

        return [
            'function' => $this->relationshipAggregate['function'],
            'relationship' => $this->normalizeAggregateRelationships(
                $this->evaluate($this->relationshipAggregate['relationship']),
            ),
            'column' => $this->normalizeAggregateColumn(
                $this->evaluate($this->relationshipAggregate['column']),
            ),
        ];
    }

    /**
     * Load one or more Eloquent relationship counts into this entry's state.
     * Scoped relationships use the same named-array syntax as aggregates.
     *
     * @param string|list<string>|array<string, Closure>|Closure|null $relationships
     */
    public function counts(string|array|Closure|null $relationships): self
    {
        if ($relationships !== null && ! $relationships instanceof Closure) {
            $this->normalizeRelationshipCounts($relationships);
        }

        $this->relationshipCounts = $relationships;

        return $this;
    }

    /** @return string|list<string>|array<string, Closure>|null */
    public function relationshipCountDefinition(): string|array|null
    {
        $relationships = $this->evaluate($this->relationshipCounts);

        if ($relationships === null) {
            return null;
        }

        return $this->normalizeRelationshipCounts($relationships);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        [$content, $contentType, $plainContent] = $this->resolvedContent();
        $icon = $this->resolvePresentationString($this->icon, 'text entry icon');
        $iconColor = $this->resolvePresentationString($this->iconColor, 'text entry icon color');
        if ($icon !== null && trim($icon) === '') {
            throw new \UnexpectedValueException('Resolved text entry icon cannot be empty.');
        }
        if ($iconColor !== null && trim($iconColor) === '') {
            throw new \UnexpectedValueException('Resolved text entry icon color cannot be empty.');
        }

        return [
            ...parent::jsonSerialize(),
            ...$this->textPresentation(),
            'since' => $this->since,
            'sinceTimezone' => $this->resolvedSinceTimezone(),
            'wrap' => $this->resolvePresentationBoolean($this->wrap, 'text entry wrap'),
            'words' => $this->resolvedLimit($this->words, 'word limit', 200),
            'wordsEnd' => $this->resolvePresentationString($this->wordsEnd, 'text entry word limit ending'),
            'badge' => $this->resolvePresentationBoolean($this->badge, 'text entry badge'),
            'prose' => $this->resolvePresentationBoolean($this->prose, 'text entry prose'),
            'list' => $this->resolvedList(),
            'listWithLineBreaks' => $this->resolvedList(),
            'bulleted' => $this->resolvePresentationBoolean($this->bulleted, 'text entry bulleted'),
            'listLimit' => $this->resolvedListLimit(),
            'expandableLimitedList' => $this->resolvePresentationBoolean($this->expandableLimitedList, 'text entry expandable list'),
            'separator' => $this->resolvedSeparator(),
            'lineClamp' => $this->resolvedLimit($this->lineClamp, 'line clamp', 6),
            'html' => $this->html,
            'markdown' => $this->markdown,
            'content' => $content,
            'contentType' => $contentType,
            'contentFromState' => $this->html || $this->markdown,
            'plainContent' => $plainContent,
            'icon' => $icon,
            'iconColor' => $iconColor,
            'iconPosition' => $this->iconPosition,
            'limit' => $this->resolvedLimit($this->limit, 'character limit', null),
            'limitEnd' => $this->resolvePresentationString($this->limitEnd, 'text entry character limit ending'),
            'prefix' => $this->resolvePresentationString($this->prefix, 'text entry prefix'),
            'suffix' => $this->resolvePresentationString($this->suffix, 'text entry suffix'),
            'format' => $this->resolvedFormat(),
            'url' => $this->url,
            'urlValue' => $this->resolvedUrlValue(),
            'openUrlInNewTab' => $this->resolvePresentationBoolean($this->openUrlInNewTab, 'text entry open URL in new tab'),
            'copyable' => $this->resolvePresentationBoolean($this->copyable, 'text entry copyable'),
            'copyableState' => $this->resolvePresentationString($this->copyableState, 'text entry copyable state'),
            'copyMessage' => $this->resolvePresentationString($this->copyMessage, 'text entry copy message'),
            'copyMessageDuration' => $this->resolvedCopyMessageDuration(),
        ];
    }

    private function resolvedList(): bool
    {
        return $this->resolvePresentationBoolean($this->list, 'text entry list')
            || $this->resolvePresentationBoolean($this->bulleted, 'text entry bulleted');
    }

    private function resolvedUrlValue(): ?string
    {
        $url = $this->evaluate($this->urlValue);
        if ($url === null) {
            return null;
        }
        if (! is_string($url)) {
            throw new \UnexpectedValueException('Text entry URL callbacks must return a string or null.');
        }

        return SafeUrl::from($url)->value();
    }

    private function resolvedSinceTimezone(): ?string
    {
        $timezone = $this->resolvePresentationString($this->sinceTimezone, 'text entry since timezone');
        if ($timezone !== null) {
            $this->assertTimezone($timezone);
        }

        return $timezone;
    }

    private function resolvedCopyMessageDuration(): int
    {
        $duration = $this->evaluate($this->copyMessageDuration);
        if (! is_int($duration) || $duration < 0) {
            throw new \UnexpectedValueException('Text entry copy message duration callbacks must return a non-negative integer.');
        }

        return $duration;
    }

    private function resolvedListLimit(): ?int
    {
        $limit = $this->evaluate($this->listLimit);
        if ($limit === null) {
            return null;
        }
        if (! is_int($limit) || $limit < 1) {
            throw new \UnexpectedValueException('Text entry list limit callbacks must return a positive integer or null.');
        }

        return $limit;
    }

    private function resolvedSeparator(): string
    {
        $separator = $this->evaluate($this->separator);
        if (! is_string($separator) || $separator === '') {
            throw new \UnexpectedValueException('Text entry separator callbacks must return a non-empty string.');
        }

        return $separator;
    }

    /** @return array{type: string, format: string, timezone: string|null}|null */
    private function resolvedFormat(): ?array
    {
        if ($this->format === null) {
            return null;
        }

        $type = (string) ($this->format['type'] ?? 'date');
        if ($type === 'number') {
            return [
                'type' => $type,
                'decimalPlaces' => $this->resolvedDecimalPlaces($this->format['decimalPlaces'] ?? 0, 'number'),
                'locale' => $this->resolvePresentationString($this->format['locale'] ?? null, 'text entry number locale'),
            ];
        }
        if ($type === 'money') {
            $currency = $this->resolvePresentationString($this->format['currency'] ?? null, 'text entry money currency');
            if ($currency === null || trim($currency) === '') {
                throw new \UnexpectedValueException('Text entry money currency callbacks must return a non-empty string.');
            }
            $divideBy = $this->resolvedPositiveNumber($this->format['divideBy'] ?? 1, 'money divideBy');

            return [
                'type' => $type,
                'currency' => strtoupper(trim($currency)),
                'decimalPlaces' => $this->resolvedDecimalPlaces($this->format['decimalPlaces'] ?? 2, 'money'),
                'locale' => $this->resolvePresentationString($this->format['locale'] ?? null, 'text entry money locale'),
                ...($divideBy !== 1 ? ['divideBy' => $divideBy] : []),
            ];
        }

        $format = $this->resolvePresentationString($this->format['format'] ?? null, 'text entry date format');
        $timezone = $this->resolvePresentationString($this->format['timezone'] ?? null, 'text entry timezone');
        if ($format === null || $format === '') {
            throw new \UnexpectedValueException('Text entry date format callbacks must return a non-empty string.');
        }
        if ($timezone !== null) {
            $this->assertTimezone($timezone);
        }

        return [
            'type' => $type,
            'format' => $format,
            'timezone' => $timezone,
        ];
    }

    private function assertTimezone(string $timezone): void
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidArgumentException("Invalid text entry timezone [{$timezone}].");
        }
    }

    private function formatDateTooltip(
        mixed $state,
        string|Closure|null $format,
        string|Closure|null $timezone,
        string $defaultFormat,
    ): ?string {
        if (($state === null) || ($state === '') || (! is_scalar($state) && ! $state instanceof \Stringable)) {
            return null;
        }

        $resolvedFormat = $this->evaluate($format) ?? $defaultFormat;
        if (! is_string($resolvedFormat) || trim($resolvedFormat) === '') {
            throw new \UnexpectedValueException('Text entry date tooltip format callbacks must return a non-empty string.');
        }

        $resolvedTimezone = $this->evaluate(
            $timezone ?? $this->timezone,
            ['state' => $state],
            positional: [$state],
        );
        if ($resolvedTimezone !== null && ! is_string($resolvedTimezone)) {
            throw new \UnexpectedValueException('Text entry date tooltip timezone callbacks must return a string or null.');
        }
        if ($resolvedTimezone !== null) {
            $this->assertTimezone($resolvedTimezone);
        }

        try {
            $date = new \DateTimeImmutable((string) $state);
        } catch (\Exception) {
            return null;
        }

        return $date
            ->setTimezone($resolvedTimezone === null ? new \DateTimeZone(date_default_timezone_get()) : new \DateTimeZone($resolvedTimezone))
            ->format($resolvedFormat);
    }

    private function formatSince(mixed $state, string|Closure|null $timezone): ?string
    {
        if (($state === null) || ($state === '') || (! is_scalar($state) && ! $state instanceof \Stringable)) {
            return null;
        }

        $resolvedTimezone = $this->evaluate(
            $timezone ?? $this->timezone,
            ['state' => $state],
            positional: [$state],
        );
        if ($resolvedTimezone !== null && ! is_string($resolvedTimezone)) {
            throw new \UnexpectedValueException('Text entry since timezone callbacks must return a string or null.');
        }
        if ($resolvedTimezone !== null) {
            $this->assertTimezone($resolvedTimezone);
        }

        $dateTimezone = $resolvedTimezone === null ? null : new \DateTimeZone($resolvedTimezone);
        try {
            $date = new \DateTimeImmutable((string) $state, $dateTimezone);
        } catch (\Exception) {
            return null;
        }

        $now = new \DateTimeImmutable('now', $dateTimezone);
        $seconds = $now->getTimestamp() - $date->getTimestamp();
        $future = $seconds < 0;
        $remaining = abs($seconds);
        $units = [
            'year' => 31_536_000,
            'month' => 2_592_000,
            'week' => 604_800,
            'day' => 86_400,
            'hour' => 3_600,
            'minute' => 60,
            'second' => 1,
        ];

        foreach ($units as $unit => $size) {
            if ($remaining < $size && $unit !== 'second') {
                continue;
            }

            $value = max(1, (int) round($remaining / $size));
            $label = $value === 1 ? $unit : $unit.'s';

            return $future ? "in {$value} {$label}" : "{$value} {$label} ago";
        }

        return null;
    }

    private function resolvedDecimalPlaces(int|Closure|null $value, string $kind): int
    {
        $resolved = $this->resolvePresentationInteger($value, "text entry {$kind} decimal places");
        if ($resolved === null || $resolved < 0) {
            throw new \UnexpectedValueException("Text entry {$kind} decimal places callbacks must return a non-negative integer.");
        }

        return $resolved;
    }

    private function resolvedPositiveNumber(int|float|Closure $value, string $property): int|float
    {
        $resolved = $this->evaluate($value);
        if ((! is_int($resolved) && ! is_float($resolved)) || $resolved <= 0) {
            throw new \UnexpectedValueException("Text entry {$property} callbacks must return a number greater than zero.");
        }

        return $resolved;
    }

    private function resolvedLimit(int|Closure|null $value, string $property, ?int $maximum): ?int
    {
        $resolved = $this->evaluate($value);
        if ($resolved === null) {
            return null;
        }
        if (! is_int($resolved) || $resolved < 1 || ($maximum !== null && $resolved > $maximum)) {
            $range = $maximum === null ? 'a positive integer' : "an integer between 1 and {$maximum}";
            throw new \UnexpectedValueException("Text entry {$property} callbacks must return {$range} or null.");
        }

        return $resolved;
    }

    /**
     * @param  'avg'|'max'|'min'|'sum'  $function
     * @param  string|array|Closure|null  $relationship
     * @param  string|Expression|Closure|null  $column
     */
    private function relationshipAggregate(string $function, string|array|Closure|null $relationship, string|Expression|Closure|null $column): self
    {
        if ($relationship !== null && ! $relationship instanceof Closure) {
            $this->normalizeAggregateRelationships($relationship);
        }
        if ($column !== null && ! $column instanceof Closure && ! $column instanceof Expression) {
            $this->normalizeAggregateColumn($column);
        }

        $this->relationshipAggregate = [
            'function' => $function,
            'relationship' => $relationship,
            'column' => $column,
        ];

        return $this;
    }

    private function validateAggregateRelationship(string $relationship): string
    {
        $relationship = trim($relationship);
        if ($relationship === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $relationship) !== 1) {
            throw new InvalidArgumentException("Invalid text entry aggregate relationship [{$relationship}].");
        }

        return $relationship;
    }

    private function validateAggregateIdentifier(string $identifier, string $label): string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid text entry aggregate {$label} [{$identifier}].");
        }

        return $identifier;
    }

    /** @param string|array|null $relationship @return string|array|null */
    private function normalizeAggregateRelationships(string|array|null $relationship): string|array|null
    {
        if ($relationship === null) {
            return $relationship;
        }
        if (is_string($relationship)) {
            return $this->validateAggregateRelationship($relationship);
        }
        if ($relationship === []) {
            throw new InvalidArgumentException('A text entry aggregate must contain at least one relationship.');
        }

        if (array_is_list($relationship)) {
            $normalized = [];
            foreach ($relationship as $name) {
                if (! is_string($name)) {
                    throw new InvalidArgumentException('A text entry aggregate relationship list must contain strings.');
                }
                $normalized[] = $this->validateAggregateRelationship($name);
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($relationship as $name => $scope) {
            if (! is_string($name) || ! $scope instanceof Closure) {
                throw new InvalidArgumentException('A scoped text entry aggregate relationship must use a Closure callback.');
            }
            $normalized[$this->validateAggregateRelationship($name)] = $scope;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('A text entry aggregate must contain at least one relationship.');
        }

        return $normalized;
    }

    private function normalizeAggregateColumn(string|Expression|null $column): string|Expression|null
    {
        if ($column === null || $column instanceof Expression) {
            return $column;
        }

        return $this->validateAggregateIdentifier($column, 'column');
    }

    /** @param string|array<mixed> $relationships @return string|list<string>|array<string, Closure> */
    private function normalizeRelationshipCounts(string|array $relationships): string|array
    {
        if (is_string($relationships)) {
            return $this->validateAggregateRelationship($relationships);
        }

        if ($relationships === []) {
            throw new InvalidArgumentException('A text entry count must contain at least one relationship.');
        }

        if (array_is_list($relationships)) {
            $normalized = [];
            foreach ($relationships as $relationship) {
                if (! is_string($relationship)) {
                    throw new InvalidArgumentException('A text entry count relationship list must contain strings.');
                }

                $normalized[] = $this->validateAggregateRelationship($relationship);
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($relationships as $relationship => $scope) {
            if (! is_string($relationship) || ! $scope instanceof Closure) {
                throw new InvalidArgumentException('A scoped text entry count relationship must use a Closure callback.');
            }

            $normalized[$this->validateAggregateRelationship($relationship)] = $scope;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('A text entry count must contain at least one relationship.');
        }

        return $normalized;
    }

    /** @return array{?string, 'html'|'text', ?string} */
    private function resolvedContent(): array
    {
        if (! $this->html && ! $this->markdown) {
            return [null, 'text', null];
        }

        $state = $this->getStatePath() === ''
            ? $this->default
            : $this->getState($this->default);
        if (! is_scalar($state) && $state !== null && ! $state instanceof \Stringable) {
            throw new \UnexpectedValueException('Rich text entry state must be a scalar or stringable value.');
        }

        $content = (string) ($state ?? '');
        $content = $this->markdown
            ? RichContent::markdownToHtml($content)
            : RichContent::sanitizeHtml($content);

        return [$content, 'html', RichContent::plainText($content)];
    }
}
