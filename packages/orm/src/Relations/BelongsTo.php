<?php

declare(strict_types=1);

namespace Fabriq\Orm\Relations;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;

/**
 * Belongs-to (inverse) relationship.
 *
 * Example: Message belongsTo User
 *   - messages.user_id → users.id
 */
final class BelongsTo extends Relation
{
    public function __construct(
        Model $parent,
        Model $related,
        private readonly string $foreignKey,
        private readonly string $ownerKey,
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): ?Model
    {
        $foreignValue = $this->parent->getAttribute($this->foreignKey);

        if ($foreignValue === null) {
            return null;
        }

        return $this->newRelatedQuery()
            ->where($this->ownerKey, $foreignValue)
            ->first();
    }

    public function eagerLoad(Collection $models): void
    {
        $keys = $models->pluck($this->foreignKey)->all();
        $keys = array_filter($keys, fn($k) => $k !== null);

        if (empty($keys)) {
            return;
        }

        $results = $this->newRelatedQuery()
            ->whereIn($this->ownerKey, array_values(array_unique($keys)))
            ->get();

        // Key results by owner key
        $dictionary = [];
        foreach ($results as $model) {
            $ownerValue = $model->getAttribute($this->ownerKey);
            $dictionary[$ownerValue] = $model;
        }

        // Assign to each parent model
        $relationName = $this->guessRelationName();
        foreach ($models as $model) {
            $foreignValue = $model->getAttribute($this->foreignKey);
            $model->setRelation($relationName, $dictionary[$foreignValue] ?? null);
        }
    }

    private function guessRelationName(): string
    {
        // Derive from related model class: User → user
        $class = (new \ReflectionClass($this->related))->getShortName();
        return lcfirst($class);
    }
}

