<?php

namespace Webrek\MongoPermission\Support;

use Illuminate\Database\Eloquent\Model;
use MongoDB\Laravel\Query\Builder;

class AtomicArray
{
    /** Optimistic compare-and-swap preserves independent writes from stale models.
     * @return array{0: array, 1: array}
     */
    public static function mutate(Model $model, string $field, callable $transform): array
    {
        if (! $model->exists) {
            $model->save();
        }
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $fresh = $model->newQuery()->findOrFail($model->getKey());
            $before = $fresh->getAttribute($field) ?? [];
            $after = array_values($transform($before));
            $query = $model->newQuery()->whereKey($model->getKey());
            $mongoQuery = $query->getQuery();
            if (! $mongoQuery instanceof Builder) {
                throw new \LogicException('Grant mutations require a MongoDB model.');
            }
            if (array_key_exists($field, $fresh->getAttributes())) {
                $mongoQuery->where($field, '=', $fresh->getAttribute($field));
            } else {
                $mongoQuery->where($field, 'exists', false);
            }
            if ($before == $after || $query->update([$field => $after]) === 1) {
                $model->setAttribute($field, $after);
                $model->syncOriginalAttribute($field);

                return [$before, $after];
            }
        }
        throw new \RuntimeException('Concurrent grant updates exceeded the retry limit. Retry the operation.');
    }
}
