<?php

namespace Eventix\Cache\Eloquent;

trait CacheTrait
{
    protected $cachedValues = [];
    private $dirtyCached = [];

    /**
     * Boot the trait. Adds an observer class for validating.
     *
     * @return void
     */
    public static function bootCacheTrait()
    {
        static::observe(new CachedObserver());
    }

    /**
     * Retrieve only the cached properties
     *
     * @param $id The unique identifier of the model to retrieve for
     * @return array The retrieved items. When not set in the cache, null is returned
     */
    public function findCached($id)
    {
        $tag = CachedObserver::getTag(get_class(), $id);

        return array_map([$tag, 'get'], $this->getCachedProperties());
    }

    /**
     * Get all properties that should be cached.
     *
     * @return array The attributes that are cached
     */
    public function getCachedProperties()
    {
        return $this->cachedProperties ?? [];
    }

    /**
     * Remove all cached property values, note when the model is reloaded, these are set again
     *
     * @return void
     */
    public function forgetCached()
    {
        CachedObserver::getTag($this)->flush();
    }

    public function getAppends()
    {
        return array_merge(parent::getAppends(), $this->getCachedProperties());
    }

    public function getDirtyCached()
    {
        return $this->dirtyCached;
    }

    public function hydrateCached($cached)
    {
        $this->cachedValues = $cached;
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        if (key_exists($key, $this->getCachedProperties())) {
            return $this->getCachedProperties();
        }

        return parent::__get($key);
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string $key
     * @return mixed
     */
    public function __set($key, $value)
    {
        if (in_array($key, $this->getCachedProperties())) {
            $this->dirtyCached[$key] = $this->cachedValues[$key] = $value;

            if (key_exists($key, $this->getAttributes())) {
                return parent::__set($key, $value);
            }

            return $this;
        } else {
            return parent::__set($key, $value);
        }
    }

    public function toArray()
    {
        return array_merge(parent::toArray(), $this->cachedValues);
    }

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $f = parent::newFromBuilder($attributes, $connection);

        $loadKeys = $f->getCachedProperties();
        if (count($loadKeys)) {
            $base = CachedObserver::getTag($f);

            $vals = [];
            foreach ($loadKeys as $key) {
                $vals[$key] = $base->get($key);
            }
            $f->hydrateCached($vals);
        }

        return $f;
    }
}
