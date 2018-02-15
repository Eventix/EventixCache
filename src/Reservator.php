<?php

namespace Eventix\Cache;

use Predis\Collection\Iterator;
use lRedis;
use Uuid;

class Reservator {

    private static $releases = [];
    public static $base = "reservation:";

    public static function addRelease($reservation) {
        static::$releases[] = $reservation;
    }

    public static function executeReleases($reservations = null) {
        array_map(['self', 'release'], $reservations ?? static::$releases);
    }

    public static function reserve($id, $duration, $childReservations = []) {
        $reservation = Uuid::generate()->__toString();
        $base = self::$base . $reservation;

        $result = lRedis::transaction()
            ->getset("$base:id", $id)// Set a key so we can always retrieve the id of the reserved object
            ->setex($base, $duration * 60, $id)// Set another key which expires at the desiried duration
            ->incr("reservationcount:$id")// Increment the reservationcount for the id of the thing we are reserving
            ->exec();

        if (!empty($result[0])) {
            // If not empty we are interfering with another reservation, so simply decrement and return false
            lRedis::incrBy("reservationcount:$id", -1);

            return false;
        }

        // Add the childreservations
        if (is_array($childReservations) && count($childReservations)) {

            $set = [];
            foreach ($childReservations as $obj => $child)
                foreach ($child as $res)
                    $set[$res] = $obj;

            lRedis::hmset("$base:children", $set);
        }

        return $reservation;
    }

    public static function getCountFor($id, $name = 'reservationcount') {
        return 1 * lRedis::get("$name:$id");
    }

    public static function incrementCountFor($id, $name = 'pendingcount') {
        return lRedis::incrBy("$name:$id", -1);
    }

    public static function decrementCountFor($id, $name = 'pendingcount') {
        return lRedis::incrBy("$name:$id", -1);
    }

    public static function checkReservation($key, $reservation) {
        $baseKey = self::$base . $reservation;

        // If key is not valid, reservation does not hold
        if (lRedis::get($baseKey . ":id") != $key)
            return false;

        // Check all child reservations
        // Don't ever return an empty array until we're done iterating
        $cursor = 0;

        do {
            list($cursor, $keys) = lRedis::hscan("$baseKey:children", $cursor);
            foreach ($keys as $childReservation => $childKey)
                if (!static::checkReservation($childKey, $childReservation))
                    return false;
        } while ($cursor);

        return true;
    }

    public static function release($reservation) {
        $baseKey = self::$base . $reservation;

        // First delete basekey, when nothing is deleted, return false
        if (lRedis::del($baseKey) == 0)
            return false;

        $id = lRedis::get("$baseKey:id");

        if (!empty($id)) {
            // Release all child resevations

            $cursor = 0;
            do {
                list($cursor, $keys) = lRedis::hscan("$baseKey:children", $cursor);
                foreach ($keys as $childReservation => $childKey)
                    self::release($childReservation);
            } while ($cursor);

            // Now decrement and delete all relevant keys
            $results = lRedis::pipeline()
                ->del("$baseKey:id")
                ->incrBy("reservationcount:$id", -1)
                ->del("$baseKey:children")
                ->execute();
        }

        return true;
    }
}