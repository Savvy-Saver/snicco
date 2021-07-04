<?php


    declare(strict_types = 1);


    namespace WPEmerge\Middleware\Core;

    use Psr\Http\Message\ResponseInterface;
    use WPEmerge\Contracts\Middleware;
    use WPEmerge\Support\WP;
    use WPEmerge\Http\Delegate;
    use WPEmerge\Http\Psr7\Request;

    class SetRequestAttributes extends Middleware
    {

        public function handle(Request $request, Delegate $next) : ResponseInterface
        {

            $request = $request
                ->withUserId(WP::userId());

            return $next($request);

        }

    }