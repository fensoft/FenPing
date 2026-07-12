<?php

declare(strict_types=1);

namespace FenPing\Api;

use FenPing\Api\Controller\Controller;
use FenPing\Auth\AuthService;
use Throwable;

final readonly class ApiKernel
{
    /** @param list<Controller> $controllers */
    public function __construct(private AuthService $auth, private array $controllers)
    {
    }

    public function handle(Request $request): Response
    {
        RequestContext::set($request);
        try {
            $router = new Router(array_merge(...array_map(
                static fn(Controller $controller): array => $controller->routes(),
                $this->controllers,
            )));
            $match = $router->match($request);
            $body = $match->route->auth === AuthPolicy::Guest ? [] : $request->body();
            $this->auth->authorize($match->route->auth, $body);
            $result = ($match->route->handler)($match->params);
            return $result instanceof Response ? $result : new JsonResponse($result);
        } catch (ResponseException $response) {
            return $response->response;
        } catch (HttpException $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->status);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'server error'], 500);
        } finally {
            RequestContext::clear();
        }
    }

    public function run(): never
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
        $this->handle(Request::fromGlobals())->emit();
    }
}
