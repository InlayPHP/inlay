<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use BackedEnum;
use Closure;
use Inlay\Support\ClosureEvaluator;
use Inlay\Schemas\Support\RichContent;
use Inlay\Tables\Column;

final class TextColumn extends Column
{
    private bool|Closure $wrap = false;

    private bool|Closure $html = false;

    private bool|Closure $markdown = false;

    private int|Closure|null $limit = null;

    private string|Closure|null $limitEnd = '…';

    private string|Closure|null $dateFormat = null;

    private string|Closure|null $dateTimezone = null;

    private bool $numeric = false;

    private bool $rowIndex = false;

    private bool $rowIndexFromZero = false;

    private int|Closure|null $numericDecimalPlaces = null;

    private string|BackedEnum|Closure|null $numericLocale = null;

    private string|Closure|null $numericDecimalSeparator = null;

    private string|Closure|null $numericThousandsSeparator = null;

    private int|Closure|null $numericMaxDecimalPlaces = null;

    private bool $since = false;

    private string|Closure|null $sinceTimezone = null;


    private int|Closure|null $words = null;

    private string|Closure|null $wordsEnd = '…';


    private string|Closure|null $prefix = null;


    private string|Closure|null $suffix = null;

    private bool $money = false;

    private string|BackedEnum|Closure|null $currency = null;

    private int|Closure|null $moneyDecimalPlaces = null;

    private string|BackedEnum|Closure|null $moneyLocale = null;

    private int|Closure $moneyDivideBy = 0;

    private bool|Closure $listWithLineBreaks = false;

    private bool|Closure $bulleted = false;

    private int|Closure|null $listLimit = null;

    private bool|Closure $expandableLimitedList = false;

    private string|Closure|null $color = null;

    private string|Closure|null $icon = null;

    private string|Closure|null $iconColor = null;

    private string $iconPosition = 'before';

    private string|Closure $textSize = 'medium';

    private string $fontWeight = 'normal';

    private string $fontFamily = 'sans';

    private int|Closure|null $lineClamp = null;

    private bool|Closure $badge = false;

    protected function type(): string
    {
        return 'text-column';
    }

    public function wrap(bool|Closure|null $wrap = true): self
    {
        $this->wrap = $wrap;

        return $this;
    }

    public function rowIndex(bool $isFromZero = false): self
    {
        $this->rowIndex = true;
        $this->rowIndexFromZero = $isFromZero;
        $this->state(fn (array $row): string => (string) (($row['__inlay_row_index'] ?? 0) + ($isFromZero ? 0 : 1)));

        return $this;
    }

    public function limit(int|Closure|null $characters = 100, string|Closure|null $end = '…'): self
    {
        if (is_int($characters) && $characters < 1) {
            throw new \InvalidArgumentException('Text limit must be at least 1.');
        }

        $this->limit = $characters;
        $this->limitEnd = $end;

        return $this;
    }

    public function date(string|Closure|null $format = 'Y-m-d', string|Closure|null $timezone = null): self
    {
        $timezone ??= $this->dateTimezone;
        $this->setDateFormatter($format ?? 'Y-m-d', $timezone);

        return $this;
    }

    public function dateTime(string|Closure|null $format = 'Y-m-d H:i', string|Closure|null $timezone = null): self
    {
        $timezone ??= $this->dateTimezone;
        $this->setDateFormatter($format ?? 'Y-m-d H:i', $timezone);

        return $this;
    }

    public function time(string|Closure|null $format = 'H:i', string|Closure|null $timezone = null): self
    {
        $timezone ??= $this->dateTimezone;
        $this->setDateFormatter($format ?? 'H:i', $timezone);

        return $this;
    }

    public function isoDate(string|Closure|null $format = 'Y-m-d', string|Closure|null $timezone = null): self
    {
        return $this->date($format ?? 'Y-m-d', $timezone);
    }

    public function isoDateTime(string|Closure|null $format = 'Y-m-d\TH:i:sP', string|Closure|null $timezone = null): self
    {
        return $this->dateTime($format ?? 'Y-m-d\TH:i:sP', $timezone);
    }

    public function isoTime(string|Closure|null $format = 'H:i:s', string|Closure|null $timezone = null): self
    {
        return $this->time($format ?? 'H:i:s', $timezone);
    }

    public function dateTooltip(string|Closure|null $format = 'Y-m-d', string|Closure|null $timezone = null): self
    {
        $this->setDateTooltip($format ?? 'Y-m-d', $timezone);

        return $this;
    }

    public function dateTimeTooltip(string|Closure|null $format = 'Y-m-d H:i', string|Closure|null $timezone = null): self
    {
        $this->setDateTooltip($format ?? 'Y-m-d H:i', $timezone);

        return $this;
    }

    public function timeTooltip(string|Closure|null $format = 'H:i', string|Closure|null $timezone = null): self
    {
        $this->setDateTooltip($format ?? 'H:i', $timezone);

        return $this;
    }

    public function isoDateTooltip(string|Closure|null $format = 'Y-m-d', string|Closure|null $timezone = null): self
    {
        return $this->dateTooltip($format ?? 'Y-m-d', $timezone);
    }

    public function isoDateTimeTooltip(string|Closure|null $format = 'Y-m-d\TH:i:sP', string|Closure|null $timezone = null): self
    {
        return $this->dateTimeTooltip($format ?? 'Y-m-d\TH:i:sP', $timezone);
    }

    public function isoTimeTooltip(string|Closure|null $format = 'H:i:s', string|Closure|null $timezone = null): self
    {
        return $this->timeTooltip($format ?? 'H:i:s', $timezone);
    }

    public function timezone(string|Closure|null $timezone): self
    {
        if (is_string($timezone)) {
            $this->assertDateTimezone($timezone);
        }
        $this->dateTimezone = $timezone;

        return $this;
    }

    public function html(bool|Closure $enabled = true): self
    {
        $this->html = $enabled;
        if ($enabled === true) {
            $this->markdown = false;
        }
        $this->setRichFormatter();

        return $this;
    }

    public function markdown(bool|Closure $enabled = true): self
    {
        $this->markdown = $enabled;
        if ($enabled === true) {
            $this->html = false;
        }
        $this->setRichFormatter();

        return $this;
    }

    public function sinceTooltip(string|Closure|null $timezone = null): self
    {
        $this->tooltip(function (TextColumn $column, mixed $state, array $record) use ($timezone): ?string {
            if ($state === null || $state === '') {
                return null;
            }

            $resolvedTimezone = $column->resolveDateTimezone($timezone, $record, $state);
            try {
                $date = $state instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($state)
                    : new \DateTimeImmutable((string) $state);
                if ($resolvedTimezone !== null) {
                    $date = $date->setTimezone(new \DateTimeZone($resolvedTimezone));
                }
            } catch (\Throwable) {
                return is_scalar($state) ? (string) $state : null;
            }

            $seconds = $date->getTimestamp() - (new \DateTimeImmutable('now', $date->getTimezone()))->getTimestamp();
            $future = $seconds > 0;
            $remaining = abs($seconds);
            foreach ([['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]] as [$unit, $size]) {
                if ($remaining < $size && $unit !== 'second') {
                    continue;
                }
                $value = max(1, (int) round($remaining / $size));

                return $future ? "in {$value} {$unit}".($value === 1 ? '' : 's') : "{$value} {$unit}".($value === 1 ? '' : 's').' ago';
            }

            return null;
        });

        return $this;
    }

    /** Show how long ago the value was, optionally using a named timezone. */
    public function since(bool|string|Closure|null $timezone = true): self
    {
        $this->formatStateUsing = null;
        if ($timezone === false) {
            $this->since = false;
            $this->sinceTimezone = null;

            return $this;
        }

        $this->since = true;
        $this->sinceTimezone = $timezone === true ? null : $timezone;

        return $this;
    }

    /** Truncate to whole words instead of characters. */
    public function words(int|Closure|null $words = 100, string|Closure|null $end = '…'): self
    {
        if (is_int($words) && ($words < 1 || $words > 200)) {
            throw new \InvalidArgumentException('A text column word limit must be between 1 and 200.');
        }

        $this->words = $words;
        $this->wordsEnd = $end;

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

    public function numeric(
        bool|int|Closure|null $decimalPlaces = null,
        string|Closure|null $decimalSeparator = null,
        string|Closure|null $thousandsSeparator = null,
        int|Closure|null $maxDecimalPlaces = null,
        string|BackedEnum|Closure|null $locale = null,
    ): self
    {
        if (is_bool($decimalPlaces)) {
            if (! $decimalPlaces) {
                $this->numeric = false;
                $this->numericDecimalPlaces = null;
                $this->numericLocale = null;
                $this->numericDecimalSeparator = null;
                $this->numericThousandsSeparator = null;
                $this->numericMaxDecimalPlaces = null;
                if ($this->money === false) {
                    $this->formatStateUsing = null;
                }

                return $this;
            }

            $decimalPlaces = null;
        }

        if (is_int($decimalPlaces) && $decimalPlaces < 0) {
            throw new \InvalidArgumentException('Number decimal places cannot be negative.');
        }
        if (is_int($maxDecimalPlaces) && $maxDecimalPlaces < 0) {
            throw new \InvalidArgumentException('Maximum number decimal places cannot be negative.');
        }

        $this->numeric = true;
        $this->numericDecimalPlaces = $decimalPlaces;
        $this->numericLocale = $locale;
        $this->numericDecimalSeparator = $decimalSeparator;
        $this->numericThousandsSeparator = $thousandsSeparator;
        $this->numericMaxDecimalPlaces = $maxDecimalPlaces;
        $this->money = false;
        $this->currency = null;
        $this->moneyDecimalPlaces = null;
        $this->moneyLocale = null;
        $this->moneyDivideBy = 0;
        $this->formatStateUsing = function (TextColumn $column, mixed $state, array $record): mixed {
            if ($state === null || $state === '' || ! is_numeric($state)) {
                return $state;
            }

            return $column->formatNumericValue(
                $state,
                $column->evaluate($column->numericDecimalPlaces, $record, $state),
                $column->evaluate($column->numericLocale, $record, $state),
                $column->evaluate($column->numericDecimalSeparator, $record, $state),
                $column->evaluate($column->numericThousandsSeparator, $record, $state),
                $column->evaluate($column->numericMaxDecimalPlaces, $record, $state),
            );
        };

        return $this;
    }

    public function money(
        string|BackedEnum|Closure|null $currency = 'USD',
        int|Closure $divideBy = 0,
        string|BackedEnum|Closure|null $locale = null,
        int|Closure|null $decimalPlaces = null,
    ): self
    {
        if (is_string($currency)) {
            $currency = $this->validateCurrency($currency);
        }
        if (is_int($decimalPlaces) && $decimalPlaces < 0) {
            throw new \InvalidArgumentException('Money decimal places cannot be negative.');
        }
        if (is_int($divideBy) && $divideBy < 0) {
            throw new \InvalidArgumentException('Money divideBy cannot be negative.');
        }

        $this->money = true;
        $this->currency = $currency;
        $this->moneyDecimalPlaces = $decimalPlaces;
        $this->moneyLocale = $locale;
        $this->moneyDivideBy = $divideBy;
        $this->numeric = false;
        $this->numericDecimalPlaces = null;
        $this->numericLocale = null;
        $this->numericDecimalSeparator = null;
        $this->numericThousandsSeparator = null;
        $this->numericMaxDecimalPlaces = null;
        $this->formatStateUsing = function (TextColumn $column, mixed $state, array $record): mixed {
            if ($state === null || $state === '' || ! is_numeric($state)) {
                return $state;
            }

            $currency = $column->evaluate($column->currency, $record, $state);
            if ($currency instanceof BackedEnum) {
                $currency = $currency->value;
            }
            $currency = $column->validateCurrency((string) ($currency ?: 'USD'));
            $locale = $column->evaluate($column->moneyLocale, $record, $state);
            if ($locale instanceof BackedEnum) {
                $locale = $locale->value;
            }
            $divideBy = $column->evaluate($column->moneyDivideBy, $record, $state);
            if (! is_int($divideBy) && ! is_float($divideBy)) {
                throw new \UnexpectedValueException('Money divideBy callbacks must return a number.');
            }
            if ($divideBy < 0) {
                throw new \UnexpectedValueException('Money divideBy callbacks must return zero or a positive number.');
            }
            if ($divideBy > 0) {
                $state /= $divideBy;
            }

            return $column->formatMoneyValue(
                $state,
                $currency,
                $column->evaluate($column->moneyDecimalPlaces, $record, $state),
                $locale,
            );
        };

        return $this;
    }

    public function listWithLineBreaks(bool|Closure $enabled = true): self
    {
        $this->listWithLineBreaks = $enabled;

        return $this;
    }

    public function bulleted(bool|Closure $enabled = true): self
    {
        $this->bulleted = $enabled;
        if ($enabled === true) {
            $this->listWithLineBreaks = true;
        }

        return $this;
    }

    public function limitList(int|Closure|null $items = 3): self
    {
        if (is_int($items) && $items < 1) {
            throw new \InvalidArgumentException('Text list limits must be at least 1.');
        }
        $this->listLimit = $items;

        return $this;
    }

    public function expandableLimitedList(bool|Closure $enabled = true): self
    {
        $this->expandableLimitedList = $enabled;

        return $this;
    }

    public function color(string|Closure|null $color): self
    {
        if (is_string($color)) {
            $color = $this->validateSemanticToken($color, 'text color');
        }
        $this->color = $color;

        return $this;
    }

    public function icon(string|Closure|null $icon): self
    {
        if (is_string($icon)) {
            $icon = $this->validateIconName($icon, 'text icon');
        }
        $this->icon = $icon;

        return $this;
    }

    public function iconColor(string|Closure|null $color): self
    {
        if (is_string($color)) {
            $color = $this->validateSemanticToken($color, 'icon color');
        }
        $this->iconColor = $color;

        return $this;
    }

    public function iconPosition(string $position): self
    {
        if (! in_array($position, ['before', 'after'], true)) {
            throw new \InvalidArgumentException("Unsupported text icon position [{$position}].");
        }
        $this->iconPosition = $position;

        return $this;
    }

    public function size(string|Closure $size): self
    {
        if (is_string($size) && ! in_array($size, ['small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException("Unsupported text size [{$size}].");
        }
        $this->textSize = $size;

        return $this;
    }

    public function weight(string $weight): self
    {
        if (! in_array($weight, ['light', 'normal', 'medium', 'semibold', 'bold'], true)) {
            throw new \InvalidArgumentException("Unsupported text weight [{$weight}].");
        }
        $this->fontWeight = $weight;

        return $this;
    }

    public function fontFamily(string $family): self
    {
        if (! in_array($family, ['sans', 'serif', 'mono'], true)) {
            throw new \InvalidArgumentException("Unsupported text font family [{$family}].");
        }
        $this->fontFamily = $family;

        return $this;
    }

    public function lineClamp(int|Closure|null $lines): self
    {
        if (is_int($lines) && ($lines < 1 || $lines > 6)) {
            throw new \InvalidArgumentException('Text line clamp must be between 1 and 6.');
        }
        $this->lineClamp = $lines;
        if (is_int($lines)) {
            $this->wrap = true;
        }

        return $this;
    }

    public function badge(bool|Closure $enabled = true): self
    {
        $this->badge = $enabled;

        return $this;
    }

    public function hasRowPresentation(): bool
    {
        return parent::hasRowPresentation()
            || $this->badge instanceof Closure
            || $this->bulleted instanceof Closure
            || $this->color instanceof Closure
            || $this->icon instanceof Closure
            || $this->iconColor instanceof Closure
            || $this->wrap instanceof Closure
            || $this->limit instanceof Closure
            || $this->limitEnd instanceof Closure
            || $this->words instanceof Closure
            || $this->wordsEnd instanceof Closure
            || $this->html instanceof Closure
            || $this->markdown instanceof Closure
            || $this->prefix instanceof Closure
            || $this->suffix instanceof Closure
            || $this->textSize instanceof Closure
            || $this->lineClamp instanceof Closure
            || $this->listWithLineBreaks instanceof Closure
            || $this->listLimit instanceof Closure
            || $this->expandableLimitedList instanceof Closure;
    }

    public function resolveRowPresentation(array $row): array
    {
        $presentation = parent::resolveRowPresentation($row);
        $state = $presentation['state'];
        if ($this->color instanceof Closure) {
            $presentation['color'] = $this->resolveSemanticToken($this->evaluate($this->color, $row, $state), 'text color');
        }
        if ($this->icon instanceof Closure) {
            $presentation['icon'] = $this->resolveIcon($this->evaluate($this->icon, $row, $state));
        }
        if ($this->iconColor instanceof Closure) {
            $presentation['iconColor'] = $this->resolveSemanticToken($this->evaluate($this->iconColor, $row, $state), 'icon color');
        }
        if ($this->badge instanceof Closure) {
            $presentation['badge'] = $this->resolveBoolean($this->badge, $row, $state, 'badge');
        }
        if ($this->bulleted instanceof Closure) {
            $presentation['bulleted'] = $this->resolveBoolean($this->bulleted, $row, $state, 'bulleted');
        }
        if ($this->listWithLineBreaks instanceof Closure || $this->bulleted instanceof Closure) {
            $lineBreaks = $this->resolveBoolean($this->listWithLineBreaks, $row, $state, 'listWithLineBreaks');
            $presentation['listWithLineBreaks'] = $lineBreaks || ($presentation['bulleted'] ?? (is_bool($this->bulleted) && $this->bulleted));
        }
        if ($this->listLimit instanceof Closure) {
            $presentation['listLimit'] = $this->resolveListLimit($this->listLimit, $row, $state);
        }
        if ($this->expandableLimitedList instanceof Closure) {
            $presentation['expandableLimitedList'] = $this->resolveBoolean($this->expandableLimitedList, $row, $state, 'expandableLimitedList');
        }
        if ($this->wrap instanceof Closure) {
            $presentation['wrap'] = $this->resolveBoolean($this->wrap, $row, $state, 'wrap');
        }
        if ($this->limit instanceof Closure) {
            $presentation['limit'] = $this->resolveTextLimit($this->limit, $row, $state, 'character');
        }
        if ($this->words instanceof Closure) {
            $presentation['words'] = $this->resolveTextLimit($this->words, $row, $state, 'word');
        }
        if ($this->limitEnd instanceof Closure) {
            $presentation['limitEnd'] = $this->resolveString($this->limitEnd, $row, $state, 'character limit ending');
        }
        if ($this->wordsEnd instanceof Closure) {
            $presentation['wordsEnd'] = $this->resolveString($this->wordsEnd, $row, $state, 'word limit ending');
        }
        if ($this->prefix instanceof Closure) {
            $presentation['prefix'] = $this->resolveString($this->prefix, $row, $state, 'prefix');
        }
        if ($this->suffix instanceof Closure) {
            $presentation['suffix'] = $this->resolveString($this->suffix, $row, $state, 'suffix');
        }
        if ($this->textSize instanceof Closure) {
            $presentation['textSize'] = $this->resolveTextSize($this->textSize, $row, $state);
        }
        if ($this->lineClamp instanceof Closure) {
            $presentation['lineClamp'] = $this->resolveLineClamp($this->lineClamp, $row, $state);
        }
        if ($this->html instanceof Closure) {
            $presentation['html'] = $this->resolveBoolean($this->html, $row, $state, 'html');
        }
        if ($this->markdown instanceof Closure) {
            $presentation['markdown'] = $this->resolveBoolean($this->markdown, $row, $state, 'markdown');
        }

        return $presentation;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'wrap' => is_bool($this->wrap) ? $this->wrap : false, 'rowIndex' => $this->rowIndex, 'rowIndexFromZero' => $this->rowIndexFromZero, 'html' => is_bool($this->html) ? $this->html : false, 'markdown' => is_bool($this->markdown) ? $this->markdown : false, 'limit' => is_int($this->limit) ? $this->limit : null, 'limitEnd' => $this->limitEnd === null || is_string($this->limitEnd) ? $this->limitEnd : '…', 'dateFormat' => is_string($this->dateFormat) ? $this->dateFormat : null, 'dateTimezone' => is_string($this->dateTimezone) ? $this->dateTimezone : null, 'numeric' => $this->numeric, 'numericDecimalPlaces' => is_int($this->numericDecimalPlaces) ? $this->numericDecimalPlaces : null, 'numericLocale' => is_string($this->numericLocale) ? $this->numericLocale : null, 'numericDecimalSeparator' => is_string($this->numericDecimalSeparator) ? $this->numericDecimalSeparator : null, 'numericThousandsSeparator' => is_string($this->numericThousandsSeparator) ? $this->numericThousandsSeparator : null, 'numericMaxDecimalPlaces' => is_int($this->numericMaxDecimalPlaces) ? $this->numericMaxDecimalPlaces : null, 'money' => $this->money, 'currency' => is_string($this->currency) ? $this->currency : null, 'moneyDecimalPlaces' => is_int($this->moneyDecimalPlaces) ? $this->moneyDecimalPlaces : null, 'moneyLocale' => is_string($this->moneyLocale) ? $this->moneyLocale : null, 'moneyDivideBy' => is_int($this->moneyDivideBy) ? $this->moneyDivideBy : null, 'listWithLineBreaks' => is_bool($this->listWithLineBreaks) ? $this->listWithLineBreaks : false, 'bulleted' => is_bool($this->bulleted) ? $this->bulleted : false, 'listLimit' => is_int($this->listLimit) ? $this->listLimit : null, 'expandableLimitedList' => is_bool($this->expandableLimitedList) ? $this->expandableLimitedList : false, 'color' => is_string($this->color) ? $this->color : null, 'icon' => is_string($this->icon) ? $this->icon : null, 'iconColor' => is_string($this->iconColor) ? $this->iconColor : null, 'iconPosition' => $this->iconPosition, 'textSize' => is_string($this->textSize) ? $this->textSize : 'medium', 'fontWeight' => $this->fontWeight, 'fontFamily' => $this->fontFamily, 'lineClamp' => is_int($this->lineClamp) ? $this->lineClamp : null, 'badge' => is_bool($this->badge) ? $this->badge : false, 'since' => $this->since, 'sinceTimezone' => $this->resolvedSinceTimezone(), 'words' => is_int($this->words) ? $this->words : null, 'wordsEnd' => $this->wordsEnd === null || is_string($this->wordsEnd) ? $this->wordsEnd : '…', 'prefix' => is_string($this->prefix) ? $this->prefix : null, 'suffix' => is_string($this->suffix) ? $this->suffix : null];
    }

    private function formatNumericValue(mixed $state, mixed $decimalPlaces, mixed $locale, mixed $decimalSeparator, mixed $thousandsSeparator, mixed $maxDecimalPlaces): string
    {
        $decimalPlaces = $this->resolveDecimalPlaces($decimalPlaces, 'number');
        $maxDecimalPlaces = $this->resolveDecimalPlaces($maxDecimalPlaces, 'maximum number');
        if ($decimalPlaces !== null && $maxDecimalPlaces !== null && $maxDecimalPlaces < $decimalPlaces) {
            throw new \UnexpectedValueException('Maximum number decimal places must be greater than or equal to decimal places.');
        }
        $decimalSeparator = $this->resolveSeparator($decimalSeparator, 'decimal');
        $thousandsSeparator = $this->resolveSeparator($thousandsSeparator, 'thousands');

        if ($decimalSeparator !== null || $thousandsSeparator !== null) {
            $places = $decimalPlaces ?? $maxDecimalPlaces ?? 3;
            $formatted = number_format((float) $state, $places, $decimalSeparator ?? '.', $thousandsSeparator ?? ',');
            if ($decimalPlaces === null) {
                $formatted = $this->trimNumberZeros($formatted, $decimalSeparator ?? '.');
            }

            return $formatted;
        }

        $locale = $this->resolveLocale($locale, 'number');
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale ?? 'en_US', \NumberFormatter::DECIMAL);
            if ($decimalPlaces !== null) {
                $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimalPlaces);
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimalPlaces);
            } else {
                $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimalPlaces ?? 3);
            }
            $formatted = $formatter->format((float) $state);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        $places = $decimalPlaces ?? $maxDecimalPlaces ?? 3;
        $formatted = number_format((float) $state, $places, '.', ',');

        return $decimalPlaces === null ? $this->trimNumberZeros($formatted, '.') : $formatted;
    }

    private function formatMoneyValue(mixed $state, string $currency, mixed $decimalPlaces, mixed $locale): string
    {
        $decimalPlaces = $this->resolveDecimalPlaces($decimalPlaces, 'money');
        $locale = $this->resolveLocale($locale, 'money');
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale ?? 'en_US', \NumberFormatter::CURRENCY);
            if ($decimalPlaces !== null) {
                $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimalPlaces);
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimalPlaces);
            }
            $formatted = $formatter->formatCurrency((float) $state, $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        $places = $decimalPlaces ?? 2;

        return $currency.' '.number_format((float) $state, $places, '.', ',');
    }

    private function resolveDecimalPlaces(mixed $value, string $kind): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new \UnexpectedValueException("Text column {$kind} decimal places must resolve to a non-negative integer or null.");
        }

        return $value;
    }

    private function resolveSeparator(mixed $value, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Text column {$kind} separators must resolve to a non-empty string or null.");
        }

        return $value;
    }

    private function resolveLocale(mixed $locale, string $kind): ?string
    {
        if ($locale instanceof BackedEnum) {
            $locale = $locale->value;
        }
        if ($locale === null) {
            return null;
        }
        if (! is_string($locale) || trim($locale) === '') {
            throw new \UnexpectedValueException("Text column {$kind} locale must resolve to a non-empty string or null.");
        }

        return trim($locale);
    }

    private function trimNumberZeros(string $formatted, string $decimalSeparator): string
    {
        if (! str_contains($formatted, $decimalSeparator)) {
            return $formatted;
        }

        return rtrim(rtrim($formatted, '0'), $decimalSeparator);
    }

    private function validateCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException("Invalid text column currency [{$currency}].");
        }

        return $currency;
    }

    private function setRichFormatter(): void
    {
        $this->formatStateUsing = function (TextColumn $column, mixed $state, array $record): ?string {
            if ($state === null || $state === '') {
                return null;
            }
            if (! is_scalar($state) && ! $state instanceof \Stringable && ! $state instanceof BackedEnum) {
                throw new \UnexpectedValueException("Text column [{$column->name()}] rich text state must be scalar, stringable, backed enum, or null.");
            }
            $content = $state instanceof BackedEnum ? (string) $state->value : (string) $state;
            $markdown = $column->resolveBoolean($column->markdown, $record, $state, 'markdown');
            $html = $column->resolveBoolean($column->html, $record, $state, 'html');
            if ($markdown) {
                return RichContent::markdownToHtml($content);
            }
            if ($html) {
                return RichContent::sanitizeHtml($content);
            }

            return $content;
        };
    }

    private function setDateFormatter(string|Closure $format, string|Closure|null $timezone = null): void
    {
        if (is_string($format) && trim($format) === '') {
            throw new \InvalidArgumentException('Text column date formats must be non-empty.');
        }
        if (is_string($timezone)) {
            $this->assertDateTimezone($timezone);
        }
        $this->dateFormat = $format;
        $this->dateTimezone = $timezone;
        $this->since = false;
        $this->sinceTimezone = null;
        $this->formatStateUsing = function (TextColumn $column, mixed $state, array $record) use ($format, $timezone): ?string {
            if ($state === null || $state === '') {
                return null;
            }
            $resolvedFormat = $column->evaluate($format, $record, $state);
            if (! is_string($resolvedFormat) || trim($resolvedFormat) === '') {
                throw new \UnexpectedValueException("Text column [{$column->name()}] date format callbacks must return a non-empty string.");
            }
            return $column->formatDateValue($state, $resolvedFormat, $column->dateTimezone, $record);
        };
    }

    private function setDateTooltip(string|Closure $format, string|Closure|null $timezone): void
    {
        if (is_string($format) && trim($format) === '') {
            throw new \InvalidArgumentException('Text column date tooltip formats must be non-empty.');
        }
        if (is_string($timezone)) {
            $this->assertDateTimezone($timezone);
        }
        $this->tooltip(function (TextColumn $column, mixed $state, array $record) use ($format, $timezone): ?string {
            if ($state === null || $state === '') {
                return null;
            }
            $resolvedFormat = $column->evaluate($format, $record, $state);
            if (! is_string($resolvedFormat) || trim($resolvedFormat) === '') {
                throw new \UnexpectedValueException("Text column [{$column->name()}] date tooltip format callbacks must return a non-empty string.");
            }

            return $column->formatDateValue($state, $resolvedFormat, $timezone, $record);
        });
    }

    private function formatDateValue(mixed $state, string $format, string|Closure|null $timezone, array $record): ?string
    {
        $resolvedTimezone = $this->resolveDateTimezone($timezone, $record, $state);
        try {
            $date = $state instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($state)
                : new \DateTimeImmutable((string) $state);
            if ($resolvedTimezone !== null) {
                $date = $date->setTimezone(new \DateTimeZone($resolvedTimezone));
            }

            return $date->format($format);
        } catch (\Throwable) {
            return is_scalar($state) ? (string) $state : null;
        }
    }

    private function resolveDateTimezone(string|Closure|null $timezone, array $record, mixed $state): ?string
    {
        $resolved = $this->evaluate($timezone, $record, $state);
        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new \UnexpectedValueException("Text column [{$this->name}] date timezone callbacks must return a non-empty string or null.");
        }
        $this->assertDateTimezone($resolved);

        return $resolved;
    }

    private function assertDateTimezone(string $timezone): void
    {
        if (trim($timezone) === '') {
            throw new \InvalidArgumentException('Text column date timezones must be non-empty.');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Invalid text column date timezone [{$timezone}].");
        }
    }

    /** @param bool|Closure $value @param array<string, mixed> $row */
    private function resolveBoolean(bool|Closure $value, array $row, mixed $state, string $property): bool
    {
        $resolved = $value instanceof Closure ? $this->evaluate($value, $row, $state) : $value;
        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException("Dynamic text column {$property} callbacks must return a boolean.");
        }

        return $resolved;
    }

    /** @param int|Closure $value @param array<string, mixed> $row */
    private function resolveListLimit(int|Closure $value, array $row, mixed $state): int
    {
        $resolved = $value instanceof Closure ? $this->evaluate($value, $row, $state) : $value;
        if (! is_int($resolved) || $resolved < 1) {
            throw new \UnexpectedValueException('Dynamic text column list limits must resolve to an integer of at least 1.');
        }

        return $resolved;
    }

    /** @param int|Closure|null $value @param array<string, mixed> $row */
    private function resolveTextLimit(int|Closure|null $value, array $row, mixed $state, string $kind): ?int
    {
        $resolved = $value instanceof Closure ? $this->evaluate($value, $row, $state) : $value;
        if ($resolved === null) {
            return null;
        }
        $maximum = $kind === 'word' ? 200 : PHP_INT_MAX;
        if (! is_int($resolved) || $resolved < 1 || $resolved > $maximum) {
            throw new \UnexpectedValueException("Dynamic text column {$kind} limits must resolve to a positive integer".($kind === 'word' ? ' up to 200.' : '.'));
        }

        return $resolved;
    }

    /** @param string|Closure|null $value @param array<string, mixed> $row */
    private function resolveString(string|Closure|null $value, array $row, mixed $state, string $property): ?string
    {
        $resolved = $value instanceof Closure ? $this->evaluate($value, $row, $state) : $value;
        if ($resolved !== null && ! is_string($resolved)) {
            throw new \UnexpectedValueException("Dynamic text column {$property} callbacks must return a string or null.");
        }

        return $resolved;
    }

    /** @param string|Closure $value @param array<string, mixed> $row */
    private function resolveTextSize(string|Closure $value, array $row, mixed $state): string
    {
        $resolved = $value instanceof Closure ? $this->evaluate($value, $row, $state) : $value;
        if (! is_string($resolved) || ! in_array($resolved, ['small', 'medium', 'large'], true)) {
            throw new \UnexpectedValueException('Dynamic text column size callbacks must return small, medium, or large.');
        }

        return $resolved;
    }

    /** @param int|Closure|null $value @param array<string, mixed> $row */
    private function resolveLineClamp(int|Closure|null $value, array $row, mixed $state): ?int
    {
        return $this->resolveTextLimit($value, $row, $state, 'line clamp');
    }

    private function resolvedSinceTimezone(): ?string
    {
        $timezone = $this->sinceTimezone instanceof Closure
            ? ClosureEvaluator::evaluate($this->sinceTimezone, ['column' => $this], [Column::class => $this], [$this])
            : $this->sinceTimezone;
        if ($timezone === null) {
            return null;
        }
        if (! is_string($timezone) || trim($timezone) === '') {
            throw new \UnexpectedValueException("Text column [{$this->name}] since timezone must resolve to a non-empty string or null.");
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Invalid text column since timezone [{$timezone}].");
        }

        return $timezone;
    }

    private function validateSemanticToken(string $token, string $kind): string
    {
        $token = trim($token);
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $token) !== 1) {
            throw new \InvalidArgumentException("Invalid {$kind} token [{$token}].");
        }

        return $token;
    }

    private function resolveSemanticToken(mixed $token, string $kind): ?string
    {
        if ($token === null) {
            return null;
        }
        if (! is_string($token)) {
            throw new \UnexpectedValueException("Dynamic {$kind} callbacks must return a string or null.");
        }

        return $this->validateSemanticToken($token, $kind);
    }

    private function resolveIcon(mixed $icon): ?string
    {
        if ($icon === null) {
            return null;
        }
        if (! is_string($icon)) {
            throw new \UnexpectedValueException('Dynamic text icon callbacks must return a string or null.');
        }

        return $this->validateIconName($icon, 'text icon');
    }
}
