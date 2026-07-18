<?php

declare(strict_types=1);

namespace FenPing\Api;

final class RequestContext
{
    private static ?Request $request = null;

    public static function set(Request $request): void
    {
        self::$request = $request;
        $_GET = $request->query;
        $_POST = $request->post;
        $_FILES = $request->files;
        $_SERVER = $request->server;
        $_COOKIE = $request->cookies;
    }

    public static function body(): ?array
    {
        return self::$request?->body();
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function clear(): void
    {
        self::$request = null;
    }
}
