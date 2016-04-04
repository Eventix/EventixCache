<?php namespace Eventix\Cache\Eloquent;

use Illuminate\Support\MessageBag;
use Uuid;
use lRedis;
use Helpers;

trait Reservable {

    public function reserve() {
        $guid = '' . Uuid::generate();

        $key = Helpers::cacheKey($this);
        $duration = $this->getReservationTime() * 60;
        $count = lRedis::setex('reservation:' . $guid . ":$key", $duration, $key);

        if ($count) {
            lRedis::sadd($key . ':reserves', $guid);
            lRedis::hincrby($key . ":properties", 'reserved_count', 1);
        }

        if ($this->isReservable() === false) {
            $this->releaseReserved($guid);

            return false;
        }

        return $guid;
    }

    public function getReservationTime() {
        return env('RESERVATION_DURATION', 20);
    }

    public function isReservable(bool $doWork = true) {
        return true;
    }

    public function getReservationErrors() {
        $errors = $this->reservationErrors ?? '';

        return (gettype($errors) === 'array' ? new MessageBag($errors) : $errors);
    }

    private $lastInstance = false;
    private $lastClass = false;
    private $lastId = false;

    public function releaseReserved($guid) {
        $key = Helpers::cacheKey($this);
        $count = lRedis::del('reservation:' . $guid . ":$key");
        $count += lRedis::srem($key . ':reserves', $guid);

        $all = lRedis::smembers('reservation:' . $key . ":childReservations");
        sort($all);
        foreach ($all as $reservation) {
            $split = explode(':', $reservation);

            if (count($split) !== 3)
                continue;

            $class = $split[0];
            $guid = $split[1];
            $reservation = $split[2];

            if ($this->lastClass !== $class || $this->lastId !== $guid) {
                $this->lastInstance = $class::find($guid);

                if (is_null($this->lastInstance)) {
                    $this->lastInstance = false;
                    continue;
                }
            }

            $this->lastClass = $class;
            $this->lastId = $guid;

            $this->lastInstance->releaseReserved($reservation);
        }

        lRedis::del('reservation:' . $key . ":childReservations");

        if ($count)
            lRedis::hincrby($key . ":properties", 'reserved_count', -1);

        // Should be 2, so greater 1 works
        return $count > 1;
    }

    /**
     * @param $class The classname of the stored children
     * @param $toAdd An associative array having key the id of the child, and the value the reservation/array of reservation ids
     */
    public function setReservedChildren($class, $toAdd) {

        $key = Helpers::cacheKey($this);

        $to = [];

        foreach ($toAdd as $guid => $reservations) {
            switch (typeof($reservations)) {
                case 'array':
                    foreach ($reservations as $reservation) {
                        $to[] = "$class:$key:$reservation";
                    }
                    break;
                case 'string':
                    $to[] = "$class:$key:$reservations";
                    break;
            }
        }

        lRedis::sadd('reservation:' . $key . ":childReservations", $to);
    }
}
