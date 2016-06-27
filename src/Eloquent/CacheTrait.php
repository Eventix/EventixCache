<?php

namespace Eventix\Cache\Eloquent;

use Illuminate\Contracts\Support\Jsonable;
use lRedis;
use Helpers;
use Illuminate\Database\Eloquent\Model;

trait CacheTrait {

    protected $cachedValues = [];
    private $dirtyCached = [];

    /**
     * Boot the trait. Adds an observer class for validating.
     *
     * @return void
     */
    public static function bootCacheTrait() {
        static::saving(function (Model $model) {
            $dirty = $model->getDirtyCached();

            $key = Helpers::cacheKey($model);
            if (!empty($dirty))
                lRedis::hmset($key . ":properties", $dirty);
        }, 1000);
    }

    /**
     * Get all properties that should be cached.
     *
     * @return array The attributes that are cached
     */
    public function getCachedProperties() {
        return $this->cachedProperties ?? [];
    }

    public function getCachedValues() {
        return $this->cachedValues ?? [];
    }

    public static function seedCached($guid) {
        $class = get_class();
        $instance = $class::find($guid);
        if (is_null($instance))
            return [];

        $key = Helpers::cacheKey($instance) . ":properties";
        $dirty = array_intersect_key($instance->toArray(), array_flip($instance->getCachedProperties()));
        lRedis::hmset($key, $dirty);

        return $dirty;
    }

    public static function findJustCached($guid) {
        $class = get_class();
        $key = Helpers::cacheKey($class, $guid);
        $all = lRedis::hgetall($key . ":properties");

        $instance = new $class;
        $casts = $instance->getCasts();

        foreach ($casts as $key => $value) {
            if (key_exists($key, $all)) {
                $v = $all[$key];
                $all[$key] = !is_null($v) && $v !== "" ? $instance->castAttribute($key, $all[$key]) : null;
            }
        }

        return new Container(count($all) > 0 ? $all : self::seedCached($guid));
    }

    /**
     * Remove all cached property values, note when the model is reloaded, these are set again
     *
     * @return void
     */
    public function forgetCached() {
        lRedis::del(Helpers::cacheKey($this) . ":properties");
    }

    public function getAppends() {
        return array_merge(parent::getAppends(), $this->getCachedProperties());
    }

    public function getDirtyCached() {
        return $this->dirtyCached;
    }

    public function hydrateCached($cached) {
        $casts = $this->getCasts();
        foreach ($cached as $key => &$value) {
            $inner = $this->$key ?? $value;
            $value = (in_array($key, $casts) ? $this->castAttribute($key, $inner) : $inner);
            $this->__set($key, $value);
        }

        $this->cachedValues = $cached;
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key) {
        if (key_exists($key, $this->getCachedProperties())) {
            return $this->castAttribute($key, $this->getCachedProperties()[$key]);
        }

        return parent::__get($key);
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string $key
     * @return mixed
     */
    public function __set($key, $value) {
        $casts = $this->getCasts();
        if (in_array($key, $this->getCachedProperties())) {
            $this->dirtyCached[$key] = $this->cachedValues[$key] = in_array($key, $casts)
                ? $this->castAttribute($key, $value) : $value;

            if (key_exists($key, $this->getAttributes())) {
                return parent::__set($key, $value);
            }

            return $this;
        } else {
            return parent::__set($key, $value);
        }
    }

    public function toArray() {
        return array_diff_key(array_merge(parent::toArray(), $this->cachedValues), array_flip($this->getHidden()));
    }

    public function newFromBuilder($attributes = [], $connection = null) {
        $f = parent::newFromBuilder($attributes, $connection);

        $loadKeys = $f->getCachedProperties();
        if (count($loadKeys)) {

            $key = Helpers::cacheKey($f);
            $base = lRedis::hmget($key . ":properties", $loadKeys);

            $vals = array_combine($loadKeys, $base);
            $f->hydrateCached($vals);
        }

        return $f;
    }

    public function update(array $attr = [], array $options = []) {
        $cached = $this->getCachedProperties();

        $todo = array_intersect_key($attr, array_flip($cached));
        foreach ($todo as $key => $value) {

            $this->cachedValues[$key] = $this->dirtyCached[$key] = $value;
        }
    }

    public function increment($column, $amount = 1, array $extra = []) {
        if (in_array($column, $this->getCachedProperties())) {
            $key = Helpers::cacheKey($this);
            lRedis::hincrby($key . ":properties", $column, $amount);
        }

        parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = []) {
        if (in_array($column, $this->getCachedProperties())) {
            $key = Helpers::cacheKey($this);
            lRedis::hdecrby($key . ":properties", $column, $amount);
        }

        parent::decrement($column, $amount, $extra);
    }
}

class Container implements Jsonable, \JsonSerializable {

    private $inner;

    public function __construct(Array $arr = []) {
        $this->inner = $arr;
    }

    public function __get($key) {
        return $inner[$key] ?? null;
    }

    public function __set($key, $value) {
        $inner[$key] = $value;
    }

    public function toJson($options = 0) {
        return json_encode($this->inner, $options);
    }

    /**
     * Specify data which should be serialized to JSON
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    function jsonSerialize() {
        return $this->inner;
    }
}
