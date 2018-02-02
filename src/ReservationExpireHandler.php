<?php

namespace Eventix\Cache;

use Illuminate\Foundation\Bus\DispatchesJobs;
use lRedis;
use Illuminate\Console\Command;
use Helpers;

class ReservationExpireHandler extends Command {
    use DispatchesJobs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservations:expirehandler';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Handles expiration of reservations';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle() {
        lRedis::pSubscribe(['__keyevent@*__:expired'], function ($message) {
            $this->dispatch(new RemoveReservation(substr($message, strlen(Reservator::$base))));
        });
    }
}
