<?php

declare(strict_types=1);

namespace Inlay\Support\Concerns;

use Closure;

trait Configurable
{
    /** @var list<array{id: int, scope: class-string, callback: Closure, important: bool, sequence: int}> */
    private static array $globalConfigurations = [];

    private static int $globalConfigurationSequence = 0;

    /**
     * Register a permanent configuration, or scope it to the execution of `$during`.
     */
    public static function configureUsing(Closure $modify, ?Closure $during = null, bool $isImportant = false): mixed
    {
        $id = ++self::$globalConfigurationSequence;
        self::$globalConfigurations[] = [
            'id' => $id,
            'scope' => static::class,
            'callback' => $modify,
            'important' => $isImportant,
            'sequence' => $id,
        ];

        if ($during === null) {
            return null;
        }

        try {
            return $during();
        } finally {
            self::$globalConfigurations = array_values(array_filter(
                self::$globalConfigurations,
                static fn (array $configuration): bool => $configuration['id'] !== $id,
            ));
        }
    }

    /** Remove configurations registered for exactly the called class. */
    public static function flushConfiguration(): void
    {
        $scope = static::class;
        self::$globalConfigurations = array_values(array_filter(
            self::$globalConfigurations,
            static fn (array $configuration): bool => $configuration['scope'] !== $scope,
        ));
    }

    protected function applyGlobalConfiguration(): void
    {
        $configurations = array_values(array_filter(
            self::$globalConfigurations,
            fn (array $configuration): bool => is_a($this, $configuration['scope']),
        ));

        usort($configurations, function (array $left, array $right): int {
            if ($left['important'] !== $right['important']) {
                return $left['important'] <=> $right['important'];
            }

            $specificity = $this->configurationDistance($right['scope']) <=> $this->configurationDistance($left['scope']);

            return $specificity !== 0 ? $specificity : $left['sequence'] <=> $right['sequence'];
        });

        foreach ($configurations as $configuration) {
            ($configuration['callback'])($this);
        }
    }

    /** A larger distance means a broader ancestor configuration. */
    private function configurationDistance(string $scope): int
    {
        $distance = 0;
        $class = static::class;
        while ($class !== $scope && ($class = get_parent_class($class)) !== false) {
            $distance++;
        }

        return $distance;
    }
}
