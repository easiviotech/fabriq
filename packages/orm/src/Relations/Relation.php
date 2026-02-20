<?php

declare(strict_types=1);

namespace Fabriq\Orm\Relations;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;
use Fabriq\Orm\QueryBuilder;

/**
 * Abstract base class for model relationships.
 *
 * Provides the contract for resolving relationship results and
 * eager loading support for N+1 prevention.
 */
abstract class Relation
{
    public function __construct(
        protected Model $parent,
        protected Model $related,
    ) {}

    /**
     * Get the results of the relationship for the parent model.
     *
     * @return Collection<Model>|Model|null
     */
    abstract public function getResults(): Collection|Model|null;

    /**
     * Eager load the relationship for a collection of parent models.
     *
     * @param Collection<Model> $models
     */
    abstract public function eagerLoad(Collection $models): void;

    /**
     * Get a fresh QueryBuilder for the related model.
     */
    protected function newRelatedQuery(): QueryBuilder
    {
        return $this->related->newQuery();
    }
}

