<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 | The CRM has no public site: the root leads straight into the admin panel.
 | Filament redirects guests to its login page and authenticated users to the dashboard.
 */
Route::redirect('/', '/admin/login', 302)->name('root');
