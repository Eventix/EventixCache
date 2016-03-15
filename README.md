# Installation

Open ```App/Http/Kernel.php``` and add the following line to the ```$routeMiddleWare``` variable:

	'cache' => \Eventix\Cache\CacheMiddleware::class
