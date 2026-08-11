<?php

declare(strict_types=1);

namespace Inlay;

use Illuminate\Support\ServiceProvider;

abstract class PanelProvider extends ServiceProvider
{
    abstract public function panel(Panel $panel): Panel;

    public function register(): void
    {
        if (! $this->app->bound(PanelRegistry::class)) {
            $this->app->singleton(PanelRegistry::class, fn (): PanelRegistry => new PanelRegistry);
        }

        $panel = $this->panel(Panel::make($this->panelId()));
        $this->app->make(PanelRegistry::class)->register($panel, $this->isDefaultPanel());
    }

    protected function panelId(): string
    {
        $name = class_basename(static::class);
        $name = preg_replace('/PanelProvider$/', '', $name) ?: $name;
        $name = preg_replace('/(?<!^)[A-Z]/', '-$0', $name) ?: $name;

        return strtolower($name);
    }

    protected function isDefaultPanel(): bool
    {
        return false;
    }
}
