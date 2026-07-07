<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('shares:purge-trash')->daily();
Schedule::command('shares:purge-zips')->daily();
Schedule::command('shares:purge-activity')->daily();
