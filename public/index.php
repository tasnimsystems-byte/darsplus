<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Router;
use App\Services\AuditLogger;

// PHP's built-in development server: let it serve existing static files itself.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

App::boot(dirname(__DIR__));

ini_set('display_errors', App::isDebug() ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', App::root('storage/logs/php-error.log'));
error_reporting(E_ALL);

$router = new Router();
require App::root('routes/web.php');

$request = Request::fromGlobals((string) App::config('app.base_path'));
AuditLogger::setIp($request->ip());

(new Kernel($router))->handle($request)->send();
