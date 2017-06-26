<?php namespace Eventix\Cache\Eloquent;

use Illuminate\Support\MessageBag;
use Uuid;
use lRedis;
use Helpers;

trait Reservable {

    public function reserve(bool $doWork = true) {
        $guid = '' . Uuid::generate();

        $key = Helpers::cacheKey($this);
        $duration = $this->getReservationTime() * 60;
        $count = lRedis::setex('reservation:' . $guid . ":$key", $duration, $key);

        if ($count) {
            lRedis::sadd($key . ':reserves', $guid);
            lRedis::hincrby($key . ":properties", 'reserved_count', 1);
        }

        if ($this->isReservable(true, $guid) === false) {
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

        $hashKey = 'reservation:' . $key . ":$guid:childReservations";
        $all = lRedis::smembers($hashKey);

        sort($all);

        foreach ($all as $reservation) {
            $split = explode(':', $reservation);

            if (count($split) !== 5)
                continue;

            $class = $split[0];
            $guid = $split[3];
            $reservation = $split[4];

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

        lRedis::del($hashKey);

        if ($count)
            lRedis::hincrby($key . ":properties", 'reserved_count', -1);

        // Should be 2, so greater 1 works
        return $count > 1;
    }

    private $easyParsed = [];

    /**
     * @param $class The classname of the stored children
     * @param $toAdd An associative array having key the id of the child, and the value the reservation/array of reservation ids
     */
    public function setReservedChildren($class, $reservation, $toAdd) {
        $key = Helpers::cacheKey($this);

        $to = [];

        foreach ($toAdd as $guid => $reservations) {
            switch (gettype($reservations)) {
                case 'array':
                    foreach ($reservations as $reservation) {
                        $this->easyParsed[$class][$guid][] = $reservation;
                        $to[] = "$class:$key:$guid:$reservation";
                    }
                    break;
                case 'string':
                    $this->easyParsed[$class][$guid][] = $reservations;
                    $to[] = "$class:$key:$guid:$reservations";
                    break;
            }
        }

        if (!empty($to))
            lRedis::sadd('reservation:' . $key . ":$reservation:childReservations", $to);
    }

    public function getJustInsertedasyParsedChildren() {
        return $this->easyParsed;
    }

    public function isReserved($reservationId) {
        return lRedis::exists("reservation:$reservationId:" . Helpers::cacheKey($this)) || false;
    }

    protected $reservedIds = [];

    public function isUnique($reservationId){
        if(!in_array($reservationId, $this->reservedIds)){
            $this->reservedIds[] = $reservationId;
            return true;
        }
        return false;
    }

}
