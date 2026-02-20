<?php

declare(strict_types=1);

namespace Fabriq\Orm\Relations;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;

/**
 * Has-many relationship.
 *
 * Example: Room hasMany Messages
 *   - messages.room_id → rooms.id
 */
final class HasMany extends Relation
{
    public function __construct(
        Model $parent,
        Model $related,
        private readonly string $foreignKey,
        private readonly string $localKey,
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): Collection
    {
        $localValue = $this->parent->getAttribute($this->localKey);

        if ($localValue === null) {
            return new Collection();
        }

        return $this->newRelatedQuery()
            ->where($this->foreignKey, $localValue)
            ->get();
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

        // Group results by foreign key
        $dictionary = [];
        foreach ($results as $model) {
            $fkValue = $model->getAttribute($this->foreignKey);
            $dictionary[$fkValue][] = $model;
        }

        // Assign collections to each parent model
        $relationName = $this->guessRelationName();
        foreach ($models as $model) {
            $localValue = $model->getAttribute($this->localKey);
            $related = $dictionary[$localValue] ?? [];
            $model->setRelation($relationName, new Collection($related));
        }
    }

    private function guessRelationName(): string
    {
        // Derive from related model class: Message → messages
        $class = (new \ReflectionClass($this->related))->getShortName();
        return lcfirst($class) . 's';
    }
}

