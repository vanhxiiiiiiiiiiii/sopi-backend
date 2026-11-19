<?php

namespace App\Http\Middleware;

use App\Models\Account\User\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AllowAccounts
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request  $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @param array $accountTypes
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, ...$accountTypes)
    {
        if ($request->user() instanceof User) {
            $request->user()->ensureActive();
        }

        if (
            !in_array($request->user()->currentAccessToken()->tokenable_type, $accountTypes) &&
            !in_array('*', $accountTypes)
        ) {
            throw new UnauthorizedHttpException('', 'Not for you, kid!');
        }

        return $next($request);
    }
}
