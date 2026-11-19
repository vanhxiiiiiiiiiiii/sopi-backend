<?php

namespace App\Http\Middleware;

use App\Models\Account\User\User;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class Authenticate extends Middleware
{
    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);
        if ($request->user() instanceof \App\Models\Account\Admin\Admin) {
            abort_unless($request->user()->is_active, 403, 'Tài khoản quản trị đã ngừng hoạt động.');
        }
        if ($request->user() instanceof User) {
            $request->user()->ensureActive();
        }
    }

    /**
     * Handle an unauthenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $guards
     * @return void
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function unauthenticated($request, array $guards)
    {
        throw new UnauthorizedHttpException('', 'Unauthenticated!');
    }
}
