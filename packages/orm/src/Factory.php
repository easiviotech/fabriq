<?php

declare(strict_types=1);

namespace Fabriq\Orm;

/**
 * Model factory for generating test/seed data.
 *
 * Subclasses define a definition() method that returns default
 * attribute values. Closures are resolved at creation time.
 *
 * Usage:
 *   class UserFactory extends Factory {
 *       protected string $model = User::class;
 *
 *       public function definition(): array {
 *           return [
 *               'id' => fn() => uuid(),
 *               'name' => 'Test User',
 *               'email' => fn() => 'user-' . mt_rand(1000, 9999) . '@test.com',
 *           ];
 *       }
 *   }
 *
 *   $users = UserFactory::new()->count(10)->create();
 *   $admin = UserFactory::new()->state(['role' => 'admin'])->make();
 */
abstract class Factory
{
    /** @var class-string<Model> */
    protected string $model = Model::class;

    private int $count = 1;

    /** @var array<string, mixed> */
    private array $overrides = [];

    /** @var array<string, array<string, mixed>> Named states */
    private array $states = [];

    /** @var list<string> Active state names */
    private array $activeStates = [];

    /**
     * Define default attribute values.
     *
     * Closures are resolved lazily during make/create.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Create a new factory instance.
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Set the number of models to create.
     */
    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(1, $count);
        return $clone;
    }

    /**
     * Override specific attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function state(array $attributes): static
    {
        $clone = clone $this;
        $clone->overrides = array_merge($clone->overrides, $attributes);
        return $clone;
    }

    /**
     * Register a named state.
     *
     * @param array<string, mixed> $attributes
     */
    public function defineState(string $name, array $attributes): static
    {
        $clone = clone $this;
        $clone->states[$name] = $attributes;
        return $clone;
    }

    /**
     * Apply a named state.
     */
    public function as(string $stateName): static
    {
        $clone = clone $this;
        $clone->activeStates[] = $stateName;
        return $clone;
    }

    /**
     * Make model instances without persisting.
     *
     * @return Collection<Model>|Model
     */
    public function make(): Collection|Model
    {
        if ($this->count === 1) {
            return $this->makeOne();
        }

        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->makeOne();
        }

        return new Collection($models);
    }

    /**
     * Create model instances and persist them.
     *
     * @return Collection<Model>|Model
     */
    public function create(): Collection|Model
    {
        if ($this->count === 1) {
            $model = $this->makeOne();
            $model->save();
            return $model;
        }

        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $model = $this->makeOne();
            $model->save();
            $models[] = $model;
        }

        return new Collection($models);
    }

    /**
     * Build a single model instance with resolved attributes.
     */
    private function makeOne(): Model
    {
        $attributes = $this->resolveAttributes();

        $modelClass = $this->model;
        /** @var Model $model */
        $model = new $modelClass($attributes);

        return $model;
    }

    /**
     * Merge definition + named states + overrides, resolving closures.
     *
     * @return array<string, mixed>
     */
    private function resolveAttributes(): array
    {
        $attributes = $this->definition();

        foreach ($this->activeStates as $stateName) {
            if (isset($this->states[$stateName])) {
                $attributes = array_merge($attributes, $this->states[$stateName]);
            }
        }

        $attributes = array_merge($attributes, $this->overrides);

        foreach ($attributes as $key => $value) {
            if ($value instanceof \Closure) {
                $attributes[$key] = $value();
            }
        }

        return $attributes;
    }
}
