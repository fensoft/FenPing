<?php

declare(strict_types=1);

namespace FenPing\Api;

use FenPing\Api\Controller\Controller;
use FenPing\Auth\AuthService;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\NullLiveUpdatePublisher;
use Throwable;

final readonly class ApiKernel
{
    private LiveUpdatePublisher $liveUpdates;

    /** @param list<Controller> $controllers */
    public function __construct(private AuthService $auth, private array $controllers, ?LiveUpdatePublisher $liveUpdates = null)
    {
        $this->liveUpdates = $liveUpdates ?? new NullLiveUpdatePublisher();
    }

    /**  list<Route> */
    public function routes(): array
    {
        return array_merge(...array_map(
            static fn(Controller $controller): array => $controller->routes(),
            $this->controllers,
        ));
    }

    public function handle(Request $request): Response
    {
        RequestContext::set($request);
        try {
            $router = new Router($this->routes());
            $match = $router->match($request);
            $body = $match->route->auth === AuthPolicy::Guest ? [] : $request->body();
            $this->auth->authorize($match->route->auth, $body);
            try {
                $result = ($match->route->handler)($request, $match->params);
                $response = $result instanceof Response ? $result : new JsonResponse($result);
            } catch (ResponseException $ready) {
                $response = $ready->response;
            }
            if ($response->status >= 200 && $response->status < 300 && $match->route->liveScopes !== []) {
                $this->liveUpdates->publish(...$match->route->liveScopes);
            }
            return $response;
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
