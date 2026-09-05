<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bootstrap super administrator
|--------------------------------------------------------------------------
|
| Read by `php artisan app:onboard`. Leave blank to be prompted interactively.
| Never commit real credentials; these come from .env on the machine running
| the command.
|
*/

return [
    'name' => env('ADMIN_NAME', ''),
    'email' => env('ADMIN_EMAIL', ''),
    'password' => env('ADMIN_PASSWORD', ''),
];
