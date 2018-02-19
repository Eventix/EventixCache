<?php namespace Eventix\Cache\Eloquent;

use Eventix\Cache\Reservator;
use Illuminate\Support\MessageBag;
use Uuid;
use lRedis;
use Helpers;
use DB;

trait Reservable {

    private $childReservations = [];

    /**
     * @param bool $doWork
     * @return array|bool|string
     * @throws \Exception
     */
    public function reserve($doWork = true) {
        $children = $this->getChildren($doWork);

        if (!is_array($children) && $children !== 0)
            return $children;

        if ($doWork)
            $this->childReservations = $children;

        $status = $this->isReservable(true);

        if ($status !== 0) {

            // When we are reserving, release child reservations on error
            if ($doWork)
                foreach ($children as $childProduct)
                    foreach ($childProduct as $childReservation)
                        Reservator::release($childReservation);

            return $status;
        }

        return $doWork ? Reservator::reserve($this->guid, $this->getReservationTime(), $children) : $status;
    }

    public function getchildReservations() {
        return $this->childReservations;
    }

    public function releaseReservation($reservation) {
        return Reservator::release($reservation);
    }

    public function isReserved($reservation) {
        return Reservator::checkReservation($this->guid, $reservation);
    }

    // Get default reservation time in shop
    public function getReservationTime() {
        return env('RESERVATION_DURATION', 20);
    }

    // Retrieve whether we are reservable
    // Should return 0 when success; 1 when sold out and 2 when everything is in reservation, and 3 when not yet sold
    public function isReservable($doWork = false) {
        return true;
    }

    public function getChildren($doWork = false) {
        return [];
    }

    public function getReservationErrors() {
        $errors = $this->reservationErrors ?? '';

        return (gettype($errors) === 'array' ? new MessageBag($errors) : $errors);
    }
}
