<?php

declare(strict_types=1);

namespace Fabriq\Orm\Relations;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;
use Fabriq\Orm\QueryBuilder;

/**
 * Many-to-many relationship via a pivot table.
 *
 * Example: User belongsToMany Rooms via room_members pivot
 *   - room_members.user_id → users.id
 *   - room_members.room_id → rooms.id
 */
final class BelongsToMany extends Relation
{
    public function __construct(
        Model $parent,
        Model $related,
        private readonly string $pivotTable,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        private readonly string $parentKey,
        private readonly string $relatedKey,
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): Collection
    {
        $parentValue = $this->parent->getAttribute($this->parentKey);

        if ($parentValue === null) {
            return new Collection();
        }

        return $this->newRelatedQuery()
            ->join(
                $this->pivotTable,
                "`{$this->related->getTable()}`.`{$this->relatedKey}`",
                '=',
                "`{$this->pivotTable}`.`{$this->relatedPivotKey}`"
            )
            ->where("`{$this->pivotTable}`.`{$this->foreignPivotKey}`", $parentValue)
            ->get();
    }

    public function eagerLoad(Collection $models): void
    {
        $keys = $models->pluck($this->parentKey)->all();
        $keys = array_filter($keys, fn($k) => $k !== null);

        if (empty($keys)) {
            return;
        }

        // Query related models via pivot
        $results = $this->newRelatedQuery()
            ->addSelect("`{$this->pivotTable}`.`{$this->foreignPivotKey}` AS __pivot_fk")
            ->join(
                $this->pivotTable,
                "`{$this->related->getTable()}`.`{$this->relatedKey}`",
                '=',
                "`{$this->pivotTable}`.`{$this->relatedPivotKey}`"
            )
            ->whereIn("`{$this->pivotTable}`.`{$this->foreignPivotKey}`", array_values(array_unique($keys)))
            ->get();

        // Group by pivot foreign key
        $dictionary = [];
        foreach ($results as $model) {
            $pivotFk = $model->getAttribute('__pivot_fk');
            $dictionary[$pivotFk][] = $model;
        }

        // Assign to parent models
        $relationName = $this->guessRelationName();
        foreach ($models as $model) {
            $parentValue = $model->getAttribute($this->parentKey);
            $related = $dictionary[$parentValue] ?? [];
            $model->setRelation($relationName, new Collection($related));
        }
    }

    /**
     * Attach a related model (insert pivot row).
     *
     * @param string|int $relatedId
     * @param array<string, mixed> $pivotData Extra columns for the pivot row
     */
    public function attach(string|int $relatedId, array $pivotData = []): void
    {
        $parentValue = $this->parent->getAttribute($this->parentKey);

        $data = array_merge($pivotData, [
            $this->foreignPivotKey => $parentValue,
            $this->relatedPivotKey => $relatedId,
        ]);

        // Build a raw pivot query
        $builder = new QueryBuilder(Model::getRouter());
        $builder->table($this->pivotTable)
            ->on($this->related->getPool())
            ->tenantScoped($this->related->isTenantScoped())
            ->insert($data);
    }

    /**
     * Detach a related model (delete pivot row).
     *
     * @param string|int|null $relatedId If null, detaches all
     */
    public function detach(string|int|null $relatedId = null): int
    {
        $parentValue = $this->parent->getAttribute($this->parentKey);

        $builder = new QueryBuilder(Model::getRouter());
        $query = $builder->table($this->pivotTable)
            ->on($this->related->getPool())
            ->tenantScoped($this->related->isTenantScoped())
            ->where($this->foreignPivotKey, $parentValue);

        if ($relatedId !== null) {
            $query = $query->where($this->relatedPivotKey, $relatedId);
        }

        return $query->delete();
    }

    private function guessRelationName(): string
    {
        $class = (new \ReflectionClass($this->related))->getShortName();
        return lcfirst($class) . 's';
    }
}

