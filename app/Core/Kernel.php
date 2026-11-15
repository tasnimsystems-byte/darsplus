<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\RateLimit;
use App\Middleware\SecurityHeaders;
use App\Middleware\SetLocale;
use App\Middleware\VerifyCsrf;

final class Kernel
{
    public function __construct(private Router $router)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (RedirectException $e) {
            App::session()->flash('error', $e->errorKey);
            $response = Response::redirect($e->path);
        } catch (HttpException $e) {
            $response = $this->errorPage($e->status);
        } catch (\Throwable $e) {
            App::log('error', $e->getMessage(), [
                'exception' => $e::class,
                'at' => $e->getFile() . ':' . $e->getLine(),
                'path' => $request->path,
            ]);
            if (App::isDebug() && App::config('app.env') !== 'testing') {
                throw $e;
            }
            $response = $this->errorPage(500);
        }
        return SecurityHeaders::apply($response);
    }

    private function dispatch(Request $request): Response
    {
        $auth = App::resetAuth();
        $auth->load();
        SetLocale::handle($auth);
        View::share("path", $request->path);
        View::share("title", null);

        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            throw new HttpException($this->router->pathExists($request->path) ? 405 : 404);
        }
        [$route, $params] = $match;
        $options = $route['options'];

        VerifyCsrf::handle($request);

        if ($options['auth']) {
            $redirect = Authenticate::handle($auth, $options);
            if ($redirect !== null) {
                return $redirect;
            }
            Authorize::handle($auth, $options['permission']);
            RateLimit::handle($request, App::session());
        }

        [$class, $method] = $route['handler'];
        return (new $class())->$method($request, ...array_values($params));
    }

    private function errorPage(int $status): Response
    {
        $known = in_array($status, [403, 404, 405, 419, 429, 500], true) ? $status : 500;
        try {
            $body = View::render('errors/error', ['status' => $known], 'layouts/auth');
        } catch (\Throwable) {
            $body = "Error $known";
        }
        return new Response($body, $status);
    }
}
