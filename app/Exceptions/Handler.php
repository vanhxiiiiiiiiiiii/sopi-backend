<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (Throwable $e) {
            // Let Laravel preserve validation responses and authentication status codes.
            if ($e instanceof HttpResponseException
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException) {
                return null;
            }

            if ($e instanceof HttpException) {
                $message = $e->getStatusCode() !== 404
                    ? $e->getMessage()
                    : 'Not found';

                return response()->json([
                    'status' => false,
                    'message' => $message,
                ], $e->getStatusCode());
            }
            // Hide the errors in production environment
            else if (!config('app.debug')) {
                return response()->json([
                    'status' => false,
                    'message' => 'An unknown error occurred!',
                ], 500);
            }
        });
    }
}
