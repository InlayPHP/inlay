<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Inlay\Tables\Filter;
use Inlay\Tables\Filters\QueryBuilder\Constraint;
use Inlay\Tables\Filters\QueryBuilder\RelationshipConstraint;

final class QueryBuilder extends Filter
{
    /** @var array<string, Constraint> */
    private array $constraints = [];

    private int $maxDepth = 5;

    private int $maxRules = 50;

    /** @param list<Constraint> $constraints */
    public function constraints(array $constraints): self
    {
        $mapped = [];
        foreach ($constraints as $constraint) {
            if (! $constraint instanceof Constraint) {
                throw new \InvalidArgumentException('Query builder constraints must extend '.Constraint::class.'.');
            }
            if (isset($mapped[$constraint->name()])) {
                throw new \InvalidArgumentException("Duplicate query constraint [{$constraint->name()}].");
            }
            $mapped[$constraint->name()] = $constraint;
        }
        $this->constraints = $mapped;

        return $this;
    }

    public function limits(int $maxDepth = 5, int $maxRules = 50): self
    {
        if ($maxDepth < 1 || $maxDepth > 10 || $maxRules < 1 || $maxRules > 200) {
            throw new \InvalidArgumentException('Query builder limits are outside the supported range.');
        }
        $this->maxDepth = $maxDepth;
        $this->maxRules = $maxRules;

        return $this;
    }

    public function apply(Builder $query, mixed $value): void
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException('Query builder state must be an object.');
        }
        $count = 0;
        $this->applyGroup($query, $value, 1, $count, 'and');
    }

    public function bindRelationshipOptions(Builder $query): void
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint instanceof RelationshipConstraint && $constraint->hasRemoteOptions()) {
                $constraint->bindOwnerQuery($query);
            }
        }
    }

    public function relationshipConstraint(string $name): ?RelationshipConstraint
    {
        $constraint = $this->constraints[$name] ?? null;
        if ($constraint instanceof RelationshipConstraint) {
            return $constraint;
        }

        // Relationship option requests can outlive a renderer deployment. A
        // renderer that was generated before a friendly constraint key was
        // introduced may still ask for the relationship path (for example
        // `roles`) instead of the public key (`role_membership`). Resolve the
        // same safe alias used by apply() so that request-time option loading
        // does not fail while the rule itself remains allow-listed.
        foreach ($this->constraints as $candidate) {
            if ($candidate instanceof RelationshipConstraint && $candidate->relationshipName() === $name) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<RelationshipConstraint> */
    public function remoteRelationshipConstraints(): array
    {
        return array_values(array_filter(
            $this->constraints,
            fn (Constraint $constraint): bool => $constraint instanceof RelationshipConstraint && $constraint->hasRemoteOptions(),
        ));
    }

    protected function type(): string
    {
        return 'query-builder';
    }

    protected function indicatorValue(mixed $value): ?string
    {
        $rules = $this->countRules(is_array($value) ? $value : []);

        return $rules === 0 ? null : $rules.' '.($rules === 1 ? 'condition' : 'conditions');
    }

    /** @param array<string, mixed> $group */
    private function countRules(array $group): int
    {
        $children = $group['children'] ?? null;
        if (! is_array($children)) {
            return 0;
        }

        $count = 0;
        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }
            $count += isset($child['children']) && is_array($child['children']) ? $this->countRules($child) : 1;
        }

        return $count;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'constraints' => array_values($this->constraints), 'maxDepth' => $this->maxDepth, 'maxRules' => $this->maxRules];
    }

    /** @param array<string, mixed> $group */
    private function applyGroup(Builder $query, array $group, int $depth, int &$count, string $outerBoolean): void
    {
        if ($depth > $this->maxDepth) {
            throw new \InvalidArgumentException('Query builder nesting exceeds the configured maximum.');
        }
        $boolean = ($group['boolean'] ?? 'and') === 'or' ? 'or' : 'and';
        $children = $group['children'] ?? [];
        if (! is_array($children)) {
            throw new \InvalidArgumentException('Query builder children must be an array.');
        }
        $query->where(function (Builder $nested) use ($children, $depth, &$count, $boolean): void {
            foreach (array_values($children) as $index => $child) {
                if (! is_array($child)) {
                    throw new \InvalidArgumentException('Query builder rules must be objects.');
                }
                $join = $index === 0 ? 'and' : $boolean;
                if (isset($child['children'])) {
                    $this->applyGroup($nested, $child, $depth + 1, $count, $join);

                    continue;
                }
                if (++$count > $this->maxRules) {
                    throw new \InvalidArgumentException('Query builder rule count exceeds the configured maximum.');
                }
                $name = $child['constraint'] ?? null;
                $operator = $child['operator'] ?? null;
                // A rule can be briefly incomplete while a deferred filter is
                // being edited, or after an older saved URL is restored. It
                // should not turn a normal table GET into a 500 response.
                if (! is_string($name) || $name === '' || ! is_string($operator) || $operator === '') {
                    continue;
                }
                // Filter state commonly lives in a bookmark or is restored
                // from a deferred browser visit. If an application renames or
                // removes a constraint, that old URL must not turn a table GET
                // into a 500 response. Unknown constraints are inert: they
                // never reach the query, while forged operators on a declared
                // constraint are still rejected by Constraint::apply().
                $constraint = $this->constraintFor($name);
                if (! $constraint instanceof Constraint) {
                    continue;
                }
                $constraint->apply($nested, $this->normalizeOperator($constraint, $operator), $child['value'] ?? null, $join);
            }
        }, boolean: $outerBoolean);
    }

    /**
     * Keep old browser payloads compatible when an operator was renamed. Only
     * aliases that map to an operator declared by the constraint are accepted;
     * unknown values still reach Constraint::apply() and are rejected.
     */
    private function normalizeOperator(Constraint $constraint, string $operator): string
    {
        $declared = $constraint->jsonSerialize()['operators'] ?? [];
        if (in_array($operator, $declared, true)) {
            return $operator;
        }

        $aliases = [
            'exists' => 'has',
            'not_exists' => 'does_not_have',
            'is' => 'equals',
            'is_not' => 'not_equals',
        ];
        $candidate = $aliases[$operator] ?? null;

        return is_string($candidate) && in_array($candidate, $declared, true) ? $candidate : $operator;
    }

    /**
     * Resolve a declared constraint by its public name or a relationship
     * path alias. The latter keeps saved/deferred browser state compatible
     * when an application gives a relationship constraint a human-friendly
     * name after an earlier renderer already serialized the path.
     */
    private function constraintFor(string $name): ?Constraint
    {
        if (isset($this->constraints[$name])) {
            return $this->constraints[$name];
        }

        foreach ($this->constraints as $constraint) {
            if ($constraint instanceof RelationshipConstraint && $constraint->relationshipName() === $name) {
                return $constraint;
            }
        }

        return null;
    }
}
