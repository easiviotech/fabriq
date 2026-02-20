<?php

declare(strict_types=1);

namespace Fabriq\Orm;

use Fabriq\Orm\Concerns\HasAttributes;
use Fabriq\Orm\Concerns\HasTenantScope;
use Fabriq\Orm\Concerns\HasTimestamps;
use Fabriq\Orm\Relations\BelongsTo;
use Fabriq\Orm\Relations\BelongsToMany;
use Fabriq\Orm\Relations\HasMany;
use Fabriq\Orm\Relations\HasOne;
use Fabriq\Orm\Relations\Relation;

/**
 * Active Record base class.
 *
 * Provides CRUD operations, relationship definitions, and automatic
 * tenant scoping. All database access flows through QueryBuilder →
 * TenantDbRouter → ConnectionPool for coroutine-safe execution.
 *
 * Subclasses override the protected properties to customize behaviour:
 *
 *   protected string $table = 'users';
 *   protected string $primaryKey = 'id';
 *   protected string $pool = 'app';
 *   protected bool $tenantScoped = true;
 *   protected bool $timestamps = true;
 *   protected array $fillable = ['name', 'email'];
 *   protected array $casts = ['is_admin' => 'bool'];
 *
 * Usage:
 *   $user = User::find('uuid-123');
 *   $user->name = 'Jane';
 *   $user->save();
 *
 *   User::create(['name' => 'Jane', 'email' => 'jane@example.com']);
 */
abstract class Model
{
    use HasAttributes;
    use HasTimestamps;
    use HasTenantScope;

    /** @var string Database table name */
    protected string $table = '';

    /** @var string Primary key column */
    protected string $primaryKey = 'id';

    /** @var bool Whether the PK auto-increments */
    protected bool $incrementing = false;

    /** @var string Connection pool name */
    protected string $pool = 'app';

    /** @var bool Whether model is tenant-scoped */
    protected bool $tenantScoped = true;

    /** @var bool Whether model manages timestamps */
    protected bool $timestamps = true;

    /** @var list<string> Mass-assignable attributes */
    protected array $fillable = [];

    /** @var list<string> Mass-assignment guarded attributes */
    protected array $guarded = ['*'];

    /** @var array<string, string> Attribute casts */
    protected array $casts = [];

    /** @var bool Whether this model instance already exists in DB */
    protected bool $exists = false;

    /** @var array<string, Collection|Model|null> Loaded relations cache */
    protected array $relations = [];

    /** @var TenantDbRouter|null Static router instance (set by OrmServiceProvider) */
    protected static ?TenantDbRouter $router = null;

    // ── Static Configuration ────────────────────────────────────────

    /**
     * Set the TenantDbRouter for all models.
     * Called once during OrmServiceProvider boot.
     */
    public static function setRouter(TenantDbRouter $router): void
    {
        static::$router = $router;
    }

    /**
     * Get the TenantDbRouter.
     */
    public static function getRouter(): ?TenantDbRouter
    {
        return static::$router;
    }

    // ── Constructor & Hydration ─────────────────────────────────────

    public function __construct(array $attributes = [])
    {
        if (!empty($attributes)) {
            $this->fill($attributes);
        }
    }

    /**
     * Create a model instance from a database row (no dirty tracking).
     *
     * @param array<string, mixed> $row
     * @return static
     */
    public static function fromRow(array $row): static
    {
        $model = new static();
        $model->setRawAttributes($row);
        $model->exists = true;
        return $model;
    }

    // ── Query Builder Entry Points ──────────────────────────────────

    /**
     * Get a new QueryBuilder for this model.
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery();
    }

    /**
     * Create a QueryBuilder configured for this model instance.
     */
    public function newQuery(): QueryBuilder
    {
        $builder = new QueryBuilder(static::$router);

        return $builder
            ->table($this->table)
            ->on($this->pool)
            ->tenantScoped($this->tenantScoped)
            ->setModelClass(static::class);
    }

    // ── Convenience Static Methods ──────────────────────────────────

    /**
     * Find a model by primary key.
     *
     * @param string|int $id
     * @return static|null
     */
    public static function find(string|int $id): ?static
    {
        return static::query()
            ->where((new static())->primaryKey, $id)
            ->first();
    }

    /**
     * Find a model by primary key or throw.
     *
     * @param string|int $id
     * @return static
     * @throws \RuntimeException
     */
    public static function findOrFail(string|int $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            throw new \RuntimeException(static::class . " not found for ID [{$id}]");
        }

        return $model;
    }

    /**
     * Get all records.
     *
     * @return Collection<static>
     */
    public static function all(): Collection
    {
        return static::query()->get();
    }

    /**
     * Start a where clause.
     */
    public static function where(string $column, mixed $operatorOrValue = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where($column, $operatorOrValue, $value);
    }

    // ── CRUD Instance Methods ───────────────────────────────────────

    /**
     * Create a new model and persist it.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function create(array $attributes): static
    {
        $model = new static();
        $model->fill($attributes);
        $model->save();
        return $model;
    }

    /**
     * Save the model (insert or update).
     *
     * @return bool
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    /**
     * Delete the model from the database.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            return false;
        }

        $builder = $this->newQuery();
        $affected = $builder->where($this->primaryKey, $pkValue)->delete();

        if ($affected > 0) {
            $this->exists = false;
            return true;
        }

        return false;
    }

    /**
     * Refresh the model from the database.
     *
     * @return static
     */
    public function fresh(): static
    {
        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            return $this;
        }

        $fresh = static::find($pkValue);
        if ($fresh !== null) {
            $this->setRawAttributes($fresh->getAttributes());
            $this->exists = true;
        }

        return $this;
    }

    // ── Relationships ───────────────────────────────────────────────

    /**
     * Define a has-one relationship.
     *
     * @param class-string<Model> $related
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? $this->getForeignKeyName();
        $localKey = $localKey ?? $this->primaryKey;

        return new HasOne($this, $instance, $foreignKey, $localKey);
    }

    /**
     * Define a has-many relationship.
     *
     * @param class-string<Model> $related
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? $this->getForeignKeyName();
        $localKey = $localKey ?? $this->primaryKey;

        return new HasMany($this, $instance, $foreignKey, $localKey);
    }

    /**
     * Define an inverse belongs-to relationship.
     *
     * @param class-string<Model> $related
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?? $instance->getForeignKeyName();
        $ownerKey = $ownerKey ?? $instance->primaryKey;

        return new BelongsTo($this, $instance, $foreignKey, $ownerKey);
    }

    /**
     * Define a many-to-many relationship.
     *
     * @param class-string<Model> $related
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ): BelongsToMany {
        $instance = new $related();
        $pivotTable = $pivotTable ?? $this->joiningTable($instance);
        $foreignPivotKey = $foreignPivotKey ?? $this->getForeignKeyName();
        $relatedPivotKey = $relatedPivotKey ?? $instance->getForeignKeyName();
        $parentKey = $parentKey ?? $this->primaryKey;
        $relatedKey = $relatedKey ?? $instance->primaryKey;

        return new BelongsToMany(
            $this, $instance, $pivotTable,
            $foreignPivotKey, $relatedPivotKey,
            $parentKey, $relatedKey
        );
    }

    /**
     * Get a loaded relationship or resolve it.
     */
    public function getRelation(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        if (method_exists($this, $name)) {
            $relation = $this->$name();
            if ($relation instanceof Relation) {
                $result = $relation->getResults();
                $this->relations[$name] = $result;
                return $result;
            }
        }

        return null;
    }

    /**
     * Set a loaded relationship.
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = $value;
    }

    // ── Eager Loading ───────────────────────────────────────────────

    /**
     * Eager load relationships on a collection of models.
     *
     * @param Collection<static> $models
     * @param list<string> $relations
     * @return Collection<static>
     */
    public static function eagerLoad(Collection $models, array $relations): Collection
    {
        if ($models->isEmpty()) {
            return $models;
        }

        $instance = $models->first();

        foreach ($relations as $relationName) {
            if (!method_exists($instance, $relationName)) {
                continue;
            }

            /** @var Relation $relation */
            $relation = $instance->$relationName();
            $relation->eagerLoad($models);
        }

        return $models;
    }

    // ── Serialization ───────────────────────────────────────────────

    /**
     * Convert model to array including relations.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = $this->attributesToArray();

        foreach ($this->relations as $name => $relation) {
            if ($relation instanceof Collection) {
                $array[$name] = $relation->toArray();
            } elseif ($relation instanceof self) {
                $array[$name] = $relation->toArray();
            } elseif ($relation === null) {
                $array[$name] = null;
            }
        }

        return $array;
    }

    /**
     * Convert model to JSON string.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Get the table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the primary key name.
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the primary key value.
     */
    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /**
     * Check if this model exists in the database.
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Get the pool name.
     */
    public function getPool(): string
    {
        return $this->pool;
    }

    /**
     * Get a foreign key name for this model.
     * E.g., User model → 'user_id'
     */
    public function getForeignKeyName(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($class))) . '_id';
    }

    /**
     * Get the joining table name for a many-to-many relationship.
     */
    protected function joiningTable(Model $related): string
    {
        $tables = [$this->getTable(), $related->getTable()];
        sort($tables);
        return implode('_', $tables);
    }

    // ── Internal CRUD ───────────────────────────────────────────────

    private function performInsert(): bool
    {
        $this->touchCreatedAt();
        $this->touchUpdatedAt();

        $data = $this->attributes;

        // Inject tenant_id
        $data = $this->injectTenantIdForInsert($data);

        $builder = $this->newQuery();
        $insertId = $builder->insert($data);

        if ($this->incrementing && $insertId) {
            $this->attributes[$this->primaryKey] = $insertId;
        }

        $this->exists = true;
        $this->syncOriginal();

        return true;
    }

    private function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true; // Nothing to update
        }

        $this->touchUpdatedAt();

        // Include updated_at in dirty if it was touched
        $dirty = $this->getDirty();

        $pkValue = $this->attributes[$this->primaryKey] ?? null;
        if ($pkValue === null) {
            return false;
        }

        $builder = $this->newQuery();
        $builder->where($this->primaryKey, $pkValue)->update($dirty);

        $this->syncOriginal();

        return true;
    }
}

