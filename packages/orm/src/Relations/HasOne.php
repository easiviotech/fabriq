<?php

declare(strict_types=1);

namespace Fabriq\Orm\Relations;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;

/**
 * Has-one relationship.
 *
 * Example: User hasOne Profile
 *   - profiles.user_id → users.id
 */
final class HasOne extends Relation
{
    public function __construct(
        Model $parent,
        Model $related,
        private readonly string $foreignKey,
        private readonly string $localKey,
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): ?Model
    {
        $localValue = $this->parent->getAttribute($this->localKey);

        if ($localValue === null) {
            return null;
        }

        return $this->newRelatedQuery()
            ->where($this->foreignKey, $localValue)
            ->first();
    }

    public function eagerLoad(Collection $models): void
    {
        $keys = $models->pluck($this->localKey)->all();
        $keys = array_filter($keys, fn($k) => $k !== null);

        if (empty($keys)) {
            return;
        }

        $results = $this->newRelatedQuery()
            ->whereIn($this->foreignKey, array_values(array_unique($keys)))
            ->get();

        // Key results by foreign key for fast lookup
        $dictionary = [];
        foreach ($results as $model) {
            $fkValue = $model->getAttribute($this->foreignKey);
            $dictionary[$fkValue] = $model;
        }

        // Assign to each parent model
        $relationName = $this->guessRelationName();
        foreach ($models as $model) {
            $localValue = $model->getAttribute($this->localKey);
            $model->setRelation($relationName, $dictionary[$localValue] ?? null);
        }
    }

    private function guessRelationName(): string
    {
        // Derive from related model class: Profile → profile
        $class = (new \ReflectionClass($this->related))->getShortName();
        return lcfirst($class);
    }
}

