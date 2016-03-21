<?php

namespace Eventix\Cache\Eloquent;

use Cache;
use Illuminate\Database\Eloquent\Model;

class CachedObserver
{

    /**
     * Register the validation event for saving the model. Saving validation
     * should only occur if creating and updating validation does not.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return boolean
     */
    public function saving(Model $model)
    {
        $dirty = $model->getDirtyCached();
        $tag = self::getTag($model);

        array_walk($dirty, function ($a, $b) use ($model, $tag) {
            $tag->forever($b, $a);
        });

        return true;
    }

    public static function getTag($model, $id = null)
    {
        $id = $model instanceof Model && $id == null ? $model->getKey() : $id;

        return Cache::tags('modelcache_' . str_slug(get_class($model) . $id));
    }
}
