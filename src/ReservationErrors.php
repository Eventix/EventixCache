<?php

namespace Eventix\Cache;

class ReservationErrors {
    const NoError = 0;
    const AllReserved = 1;
    const OutOfStock = 2;
    const NotSold = 3;
    const OtherError = 4;
}