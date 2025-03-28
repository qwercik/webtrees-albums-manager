<?php

declare(strict_types=1);

use Fisharebest\Webtrees\Registry;
use Komputeryk\Webtrees\AlbumsManager\AlbumsManagerModule;

require __DIR__ . '/vendor/autoload.php';

return Registry::container()->get(AlbumsManagerModule::class);