<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use InvalidArgumentException;
use JsonSerializable;

final class WidgetDashboard implements JsonSerializable
{
    /** @var list<Widget> */
    private array $widgets = [];

    private int $columns = 12;

    private ?Dashboard $dashboard = null;

    public static function make(): self
    {
        return new self;
    }

    /** @param iterable<Widget> $widgets */
    public function widgets(iterable $widgets): self
    {
        $seen = [];
        foreach ($widgets as $widget) {
            if (! $widget instanceof Widget) {
                throw new InvalidArgumentException('Dashboard widgets must be Widget instances.');
            }
            if (isset($seen[$widget->name()])) {
                throw new InvalidArgumentException("Duplicate dashboard widget [{$widget->name()}].");
            }
            $seen[$widget->name()] = true;
            if ($widget->isVisible()) {
                $this->widgets[] = $widget;
            }
        }
        usort($this->widgets, fn (Widget $a, Widget $b): int => [$a->sortOrder(), $a->name()] <=> [$b->sortOrder(), $b->name()]);

        return $this;
    }

    public function columns(int $columns): self
    {
        if ($columns < 1 || $columns > 12) {
            throw new InvalidArgumentException('Dashboard columns must be between one and twelve.');
        }
        $this->columns = $columns;

        return $this;
    }

    public function dashboard(?Dashboard $dashboard): self
    {
        $this->dashboard = $dashboard;

        return $this;
    }

    public function jsonSerialize(): array
    {
        $presentation = $this->dashboard?->jsonSerialize() ?? [];
        $tabs = [];
        foreach ($this->widgets as $widget) {
            $name = $widget->tabName() ?? 'overview';
            $tabs[$name] ??= [
                'name' => $name,
                'label' => $widget->tabLabel() ?? self::tabLabel($name),
                'widgets' => [],
            ];
            $tabs[$name]['widgets'][] = $widget->name();
            if ($widget->tabLabel() !== null) {
                $tabs[$name]['label'] = $widget->tabLabel();
            }
        }

        return [
            'contract' => 'inlay.widget-dashboard.v1',
            'columns' => $this->columns,
            'eyebrow' => $presentation['eyebrow'] ?? null,
            'heading' => $presentation['heading'] ?? null,
            'description' => $presentation['description'] ?? null,
            'headerActions' => $presentation['headerActions'] ?? [],
            'tabs' => array_values($tabs),
            'widgets' => $this->widgets,
        ];
    }

    private static function tabLabel(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
