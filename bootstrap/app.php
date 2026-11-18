<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Global catch-all: log every unhandled exception with request context.
        $exceptions->report(function (Throwable $e) {
            logger()->error($e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'user_id' => optional(request()->user())->id,
            ]);
        });

        // Global catch-all: render a consistent JSON error for API/JSON requests.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null; // fall back to Laravel's default HTML handling
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'This action is unauthorized.',
                ], Response::HTTP_FORBIDDEN);
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            return response()->json([
                'success' => false,
                'message' => config('app.debug') || $status < 500
                    ? $e->getMessage()
                    : 'Something went wrong. Please try again later.',
            ], $status);
        });
    })->create();