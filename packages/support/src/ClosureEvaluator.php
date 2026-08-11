<?php

declare(strict_types=1);

namespace Inlay\Support;

use Closure;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

final class ClosureEvaluator
{
    /**
     * @param  array<string, mixed>  $named
     * @param  array<class-string, object>  $typed
     * @param  list<mixed>  $positional
     */
    public static function evaluate(
        Closure $closure,
        array $named = [],
        array $typed = [],
        array $positional = [],
        ?Closure $resolveDependency = null,
    ): mixed
    {
        $arguments = [];

        foreach ((new ReflectionFunction($closure))->getParameters() as $position => $parameter) {
            $resolved = self::resolve($parameter, $position, $named, $typed, $positional, $resolveDependency);
            if ($parameter->isVariadic() && is_array($resolved)) {
                array_push($arguments, ...$resolved);
            } else {
                $arguments[] = $resolved;
            }
        }

        return $closure(...$arguments);
    }

    /**
     * A named value satisfies the parameter unless the parameter declares class
     * types the value is not an instance of and a typed utility matches instead.
     *
     * @param  array<class-string, object>  $typed
     */
    private static function satisfiesType(mixed $value, ?ReflectionType $type, array $typed): bool
    {
        $classNames = self::classNames($type);
        if ($classNames === []) {
            return true;
        }

        foreach ($classNames as $typeName) {
            if ($value instanceof $typeName) {
                return true;
            }
        }

        foreach ($classNames as $typeName) {
            if (self::resolveTyped($typeName, $typed, []) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $named
     * @param  array<class-string, object>  $typed
     * @param  list<mixed>  $positional
     */
    private static function resolve(
        ReflectionParameter $parameter,
        int $position,
        array $named,
        array $typed,
        array $positional,
        ?Closure $resolveDependency,
    ): mixed
    {
        $type = $parameter->getType();

        // A named utility wins, unless the parameter declares a class type it
        // cannot satisfy while a typed utility can.
        if (array_key_exists($parameter->getName(), $named)
            && self::satisfiesType($named[$parameter->getName()], $type, $typed)) {
            return $named[$parameter->getName()];
        }

        if ($type instanceof ReflectionIntersectionType) {
            $resolved = self::resolveIntersection($type, $typed, $named, $parameter, $resolveDependency);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        foreach (self::classNames($type) as $typeName) {
            $resolved = self::resolveTyped($typeName, $typed, $named);
            if ($resolved !== null) {
                return $resolved;
            }

            if ($resolveDependency !== null) {
                $resolved = $resolveDependency($typeName, $parameter);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        if (array_key_exists($position, $positional)) {
            return $positional[$position];
        }

        if ($parameter->isVariadic()) {
            return [];
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new \InvalidArgumentException("Unable to resolve closure parameter [\${$parameter->getName()}].");
    }

    /**
     * @param  array<class-string, object>  $typed
     * @param  array<string, mixed>  $named
     */
    private static function resolveTyped(string $typeName, array $typed, array $named): ?object
    {
        if (array_key_exists($typeName, $typed) && $typed[$typeName] instanceof $typeName) {
            return $typed[$typeName];
        }

        foreach ([...array_values($typed), ...array_values($named)] as $value) {
            if (is_object($value) && $value instanceof $typeName) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<class-string, object>  $typed
     * @param  array<string, mixed>  $named
     */
    private static function resolveIntersection(
        ReflectionIntersectionType $type,
        array $typed,
        array $named,
        ReflectionParameter $parameter,
        ?Closure $resolveDependency,
    ): ?object
    {
        $typeNames = array_map(
            static fn (ReflectionNamedType $candidate): string => $candidate->getName(),
            $type->getTypes(),
        );

        foreach ([...array_values($typed), ...array_values($named)] as $value) {
            if (is_object($value) && self::satisfiesEveryType($value, $typeNames)) {
                return $value;
            }
        }

        if ($resolveDependency !== null) {
            foreach ($typeNames as $typeName) {
                $value = $resolveDependency($typeName, $parameter);
                if (is_object($value) && self::satisfiesEveryType($value, $typeNames)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param list<class-string> $typeNames */
    private static function satisfiesEveryType(object $value, array $typeNames): bool
    {
        foreach ($typeNames as $typeName) {
            if (! $value instanceof $typeName) {
                return false;
            }
        }

        return true;
    }

    /** @return list<class-string> */
    private static function classNames(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $candidate) {
                array_push($names, ...self::classNames($candidate));
            }

            return array_values(array_unique($names));
        }

        return [];
    }
}
