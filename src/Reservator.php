<?php

namespace Eventix\Cache;

use lRedis;
use Uuid;

class Reservator {

    private static $releases = [];
    public static $base = "reservation";
    public static $reservationCountBase = "reservationcount";
    public static $pendingCountBase = "pendingcount";

    public static function addRelease ($reservation) {
        static::$releases[] = $reservation;
    }

    public static function executeReleases ($reservations = null) {
        array_map(['self', 'release'], $reservations ?? static::$releases);
    }

    /**
     * @param $id
     * @param $duration
     * @param array $childReservations
     * @return bool|string
     * @throws \Exception
     */
    public static function reserve ($id, $duration, $childReservations = []) {
        $reservation = (string) Uuid::generate();

        $base = self::$base . ":" . $reservation;
        $reservationCountBase = self::$reservationCountBase;

        $result = lRedis::transaction()
                        ->getset("$base:id", $id)// Set a key so we can always retrieve the id of the reserved object
                        ->setex($base, $duration * 60, $id)// Set another key which expires at the desiried duration
                        ->incr("$reservationCountBase:$id")// Increment the reservationcount for the id of the thing we are reserving
                        ->exec();

        if (!empty($result[0])) {
            // If not empty we are interfering with another reservation, so simply decrement and return false
            self::decrementReservationCountFor($id);

            return false;
        }

        // Add the child reservations
        if (is_array($childReservations) && count($childReservations)) {

            $set = [];
            foreach ($childReservations as $obj => $child)
                foreach ($child as $res)
                    $set[$res] = $obj;

            lRedis::hmset("$base:children", $set);
        }

        return $reservation;
    }

    /**
     * @param string|array $id
     * @return array|float|int
     */
    public static function getReservationCountFor ($id) {
        return self::getCountFor($id, self::$reservationCountBase);
    }

    /**
     * @param string|array $id
     * @return array|float|int
     */
    public static function getPendingCountFor ($id) {
        return self::getCountFor($id, self::$pendingCountBase);
    }

    /**
     * @param string|array $id
     * @param string|null $name
     * @return array|float|int
     */
    public static function getCountFor ($id, $name = null) {
        if (is_null($name))
            $name = self::$reservationCountBase;

        if (is_array($id)) {
            foreach ($id as $key => $value) {
                $guid = is_numeric($key) ? $value : $key;
                $counts[$guid] = self::getCountFor($guid, $name);
            }

            return $counts ?? [];
        }

        return 1 * lRedis::get("$name:$id");
    }

    /**
     * @param string|array $id
     * @param int $diff
     * @return array|float|int
     */
    public static function incrementReservationCountFor ($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$reservationCountBase, $diff);
    }

    /**
     * @param string|array $id
     * @param int $diff
     * @return array|float|int
     */
    public static function incrementPendingCountFor ($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$pendingCountBase, $diff);
    }

    /**
     * @param string|array $id
     * @param int $diff
     * @return array|float|int
     */
    public static function decrementReservationCountFor ($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$reservationCountBase, (-1) * $diff);
    }

    /**
     * @param string|array $id
     * @param int $diff
     * @return array|float|int
     */
    public static function decrementPendingCountFor ($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$pendingCountBase, (-1) * $diff);
    }

    /**
     * @param string|array $id
     * @param string|null $name
     * @param int $diff
     * @return array|float|int
     */
    public static function incrementCountFor ($id, $name = null, int $diff = 1) {
        if (is_null($name))
            $name = self::$pendingCountBase;

        if (is_array($id)) {
            foreach ($id as $key => $guid) {
                if (!is_numeric($key)) {
                    // $key is a GUID, $guid is the diff value
                    $diff = $guid;
                    $guid = $key;
                }

                $counts[$guid] = self::incrementCountFor($guid, $name, $diff);
            }

            return $counts ?? [];
        }

        return lRedis::incrBy("$name:$id", $diff);
    }

    public static function checkReservation ($key, $reservation) {
        $baseKey = self::$base . ":" . $reservation;

        // If key is not valid, reservation does not hold
        if (lRedis::get("$baseKey:id") != $key)
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

    public static function release ($reservation) {
        $baseKey = self::$base . ":" . $reservation;
        $reservationCountBase = self::$reservationCountBase;

        // First delete basekey, when nothing is deleted, return false
        if (lRedis::del($baseKey) == 0)
            return false;

        $id = lRedis::get("$baseKey:id");

        if (!empty($id)) {
            // Release all child reservations
            $cursor = 0;
            do {
                list($cursor, $keys) = lRedis::hscan("$baseKey:children", $cursor);
                foreach ($keys as $childReservation => $childKey)
                    self::release($childReservation);
            } while ($cursor);

            // Now decrement and delete all relevant keys
            $result = lRedis::pipeline()
                            ->del("$baseKey:id")
                            ->incrBy("$reservationCountBase:$id", -1)
                            ->del("$baseKey:children")
                            ->execute();

            if (!empty($result[0])) {
                // TODO If not empty something failed.. what to do??
                return false;
            }
        }

        return true;
    }
}