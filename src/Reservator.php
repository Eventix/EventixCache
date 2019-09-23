<?php

namespace Eventix\Cache;

use Uuid;
use Illuminate\Support\Facades\Redis;

class Reservator {

    private static $releases = [];
    public static $base = "reservation";
    public static $reservedCountBase = "reservedcount";
    public static $pendingCountBase = "pendingcount";
    const extraReservationTime = 10;

    public static function addRelease($reservation) {
        static::$releases[] = $reservation;
    }

    public static function executeReleases($reservations = null) {
        array_map(['self', 'release'], $reservations ?? static::$releases);
    }

    public static function releaseChildReservations($childReservations = []) {
        foreach ($childReservations as $childProduct) {
            foreach ($childProduct as $childReservation) {
                self::release($childReservation);
            }
        }
    }

    /**
     * @param       $id
     * @param       $duration
     * @param array $childReservations
     * @param bool  $isChild WHether this is a child, which means we will *not* set an expiry
     * @return bool|string
     * @throws \Exception
     */
    public static function reserve($id, $duration, $childReservations = [], $isChild = false) {
        $reservation = (string) Uuid::generate();

        $base = self::$base . ":" . $reservation;
        $reservedCountBase = self::$reservedCountBase;

        // Allready add one extraReservationTime expiry
        $expiry = $duration * 60 + self::extraReservationTime;

        // Set the expiry event further when we are a childreservation, these should not be released automatically
        if ($isChild) {
            $expiry *= 5;
        }

        $result = Redis::transaction()
            ->getset("$base:id", $id)// Set a key so we can always retrieve the id of the reserved object
            ->setex($base, $expiry, $id)// Set another key which expires at the desired duration, add some extra time to reduce a data race
            ->incr("$reservedCountBase:$id")// Increment the reservedcount for the id of the thing we are reserving
            ->exec();

        if (!empty($result[0])) {
            // If not empty we are interfering with another reservation, so simply decrement and return false
            self::decrementReservedCountFor($id);

            return ReservationErrors::OtherError;
        }

        // Add the child reservations
        if (is_array($childReservations) && count($childReservations)) {

            $set = [];
            foreach ($childReservations as $obj => $child) {
                foreach ($child as $res) {
                    $set[$res] = $obj;
                }
            }

            Redis::hmset("$base:children", $set);
        }

        return $reservation;
    }

    /**
     * @param string|array $id
     * @return array|float|int
     */
    public static function getReservedCountFor($id) {
        return self::getCountFor($id, self::$reservedCountBase);
    }

    /**
     * @param string|array $id
     * @return array|float|int
     */
    public static function getPendingCountFor($id) {
        return self::getCountFor($id, self::$pendingCountBase);
    }

    /**
     * @param string|array $id
     * @param string|null  $name
     * @return array|float|int
     */
    public static function getCountFor($id, $name = null) {
        if (is_null($name)) {
            $name = self::$reservedCountBase;
        }

        if (!is_iterable($id)) {
            return 1 * Redis::get("$name:$id");
        }

        foreach ($id as $key => $value) {
            $guid = is_numeric($key) ? $value : $key;
            $counts[$guid] = self::getCountFor($guid, $name);
        }

        return $counts ?? [];
    }

    /**
     * @param string|array $id
     * @param int          $diff
     * @return array|float|int
     */
    public static function incrementReservedCountFor($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$reservedCountBase, $diff);
    }

    /**
     * @param string|array $id
     * @param int          $diff
     * @return array|float|int
     */
    public static function incrementPendingCountFor($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$pendingCountBase, $diff);
    }

    /**
     * @param string|array $id
     * @param int          $diff
     * @return array|float|int
     */
    public static function decrementReservedCountFor($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$reservedCountBase, (-1) * $diff);
    }

    /**
     * @param string|array $id
     * @param int          $diff
     * @return array|float|int
     */
    public static function decrementPendingCountFor($id, int $diff = 1) {
        return self::incrementCountFor($id, self::$pendingCountBase, (-1) * $diff);
    }

    /**
     * @param string|array $id
     * @param string|null  $name
     * @param int          $diff
     * @return array|float|int
     */
    public static function incrementCountFor($id, $name = null, int $diff = 1) {
        if (is_null($name)) {
            $name = self::$pendingCountBase;
        }

        if ($name !== false) {
            $name = "$name:";
        } else {
            $name = '';
        }

        if (!is_array($id)) {
            return Redis::incrBy("$name" . "$id", $diff);
        }

        foreach ($id as $key => $value) {
            if (is_string($key)) {
                // key is guid
                $counts[$key][] = (int) $value;
            } else if (is_string($value)) {
                // value is guid
                $counts[$value][] = $diff;
            } else {
                // Should not happen, ever, we dont want integers as identifiers and also no string as incrementation value!
                // Ignore
            }
        }

        $counts = array_map('array_sum', $counts ?? []);
        $countsMap = array_keys($counts);

        $executed = Redis::pipeline(function ($pipe) use ($counts, $name) {
            foreach ($counts as $guid => $diff) {
                $pipe->incrBy($name . $guid, $diff);
            }
        });

        foreach ($executed as $i => $result) {
            if (array_key_exists($i, $countsMap)) {
                $newCounts[$countsMap[$i]] = $result;
            }
        }

        return $newCounts ?? [];
    }

    public static function checkReservation($key, $reservation, $child = false) {
        $baseKey = self::$base . ":" . $reservation;

        // If the keys do not match, or we are a root reservation and the ttl is less then the extra expiry time it cannot be valid
        if (Redis::get("$baseKey:id") != $key || Redis::ttl("$baseKey") < ((intval($child) + 1) * self::extraReservationTime)) {
            return false;
        }

        // Check all child reservations
        // Release all child reservations
        $it = null;
        $baseConnection = Redis::connection()->client();
        while ($keys = $baseConnection->hscan("$baseKey:children", $it)) {
            foreach ($keys as $childReservation => $childKey) {
                if (!static::checkReservation($childKey, $childReservation, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function release($reservation) {
        $baseKey = self::$base . ":" . $reservation;
        $reservedCountBase = self::$reservedCountBase;

        // First delete basekey, when nothing is deleted, return false
        Redis::del($baseKey);
        $id = Redis::get("$baseKey:id");

        if (empty($id)) {
            return false;
        }

        // Release all child reservations
        $it = null;
        $baseConnection = Redis::connection()->client();
        while ($keys = $baseConnection->hscan("$baseKey:children", $it)) {
            foreach ($keys as $childReservation => $childKey) {
                self::release($childReservation);
            }
        }

        // Now decrement and delete all relevant keys
        $result = Redis::pipeline(function ($pipe) use ($baseKey, $reservedCountBase, $id) {
            $pipe->del("$baseKey:id")
                ->incrBy("$reservedCountBase:$id", -1)
                ->del("$baseKey:children");
        });

        if ($result[0] !== 1 || $result[1] < 0) {
            // TODO something failed.. what to do??
        }

        return true;
    }
}
