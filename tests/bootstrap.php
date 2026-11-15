<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Migrator;
use App\Core\Session;
use Database\Seeders\BaseSeeder;

require dirname(__DIR__) . '/vendor/autoload.php';

App::boot(dirname(__DIR__));

if (App::config('app.env') !== 'testing' || !str_ends_with((string) App::config('database.database'), '_test')) {
    fwrite(STDERR, "Refusing to run: tests need APP_ENV=testing and a database whose name ends with _test.\n");
    exit(1);
}

App::useSession(Session::fake());
(new Migrator(App::db(), App::root('database/migrations')))->fresh();
(new BaseSeeder(App::db()))->run();
