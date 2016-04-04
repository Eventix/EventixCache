<?php namespace Eventix\Cache\Eloquent;

use Uuid;
use lRedis;
use Eventix\Cache\Eloquent\Reservable;

trait HasReserved {

    function addReservation(Model $reserve){
        $traits = class_uses($reserve);

        if($traits === false || !in_array(Reservable::class, $traits))
            return false;

        $uuid = $reserve->reserve($this);

        if($uuid === false)
            return false;

        lRedis::sadd($key, $uuid);
        return $uuid;
    }
}
