<?php

namespace Eventix\Cache\Eloquent;

use Cache;
use Illuminate\Database\Eloquent\Model;
use Helpers;
use lRedis;

class CachedObserver {

    /**
     * Register the validation event for saving the model. Saving validation
     * should only occur if creating and updating validation does not.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return boolean
     */
    public function saving(Model $model) {
        $dirty = $model->getDirtyCached();

        $key = Helpers::cacheKey($model);
        if (!empty($dirty))
            lRedis::hmset($key . ":properties", $dirty);

        return true;
    }
}
