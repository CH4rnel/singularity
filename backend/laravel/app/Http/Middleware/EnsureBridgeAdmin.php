<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBridgeAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->user()?->email;
        $allowed = config('bridge.admin_emails', []);

        abort_if(! $email || ! in_array($email, $allowed, true), 404);

        return $next($request);
    }
}
