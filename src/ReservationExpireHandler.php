<?php

namespace Eventix\Cache;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Redis;
use Illuminate\Console\Command;

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
        Redis::getFacadeRoot()->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        Redis::pSubscribe(['__keyevent@*__:expired'], function ($message) {
            $base = Reservator::$base . ":";
            $this->dispatch(new RemoveReservation(substr($message, strlen($base))));
        });
    }
}
