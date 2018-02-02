<?php

namespace Eventix\Cache;

use App\Jobs\Job;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use lRedis;
use Helpers;

class RemoveReservation extends Job implements ShouldQueue {
    use InteractsWithQueue;

    private $reservation;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($reservation) {
        $this->reservation = $reservation;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle() {
        Reservator::release($this->reservation);
    }
}
