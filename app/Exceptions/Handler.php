<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * @var string|null
     */
    private $sentryId = null;

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  Throwable  $e
     * @return void
     *
     * @throws Throwable
     */
    public function report(Throwable $e)
    {
        if (app()->bound('sentry') && $this->shouldReport($e)) {
            $eventId = app('sentry')->captureException($e);
            $this->sentryId = $eventId !== null ? (string) $eventId : null;
        }

        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  Throwable  $e
     * @return Response
     *
     * @throws Throwable
     */
    public function render($request, Throwable $e)
    {
        if ($e instanceof ValidationException && $e->response !== null) {
            return $e->getResponse();
        }

        if ($request->ajax() || $request->wantsJson() || $request->isJson())
        {
            $json = $this->renderJson($request, $e);

            if ($e instanceof HttpException) {
                return response()->json($json, $e->getStatusCode(), $e->getHeaders());
            }

            return response()->json($json, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return parent::render($request, $e);
    }

    private function renderJson($request, Throwable $e)
    {
        $message = 'An internal error occurred';

        if ($e instanceof HttpException) {
            $message = $e->getMessage();
        }

        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
                'id' => $this->sentryId,
            ],
        ];

        if ($e->getCode()) {
            $response['error']['code'] = $e->getCode();
        }

        if (config('app.debug')) {
            $response['error']['exception'] = sprintf('[%s] %s', get_class($e), $e->getMessage());
            $response['error']['trace'] = $e->getTrace();
            $response['error']['file'] = $e->getFile();
            $response['error']['line'] = $e->getLine();
        }

        return $response;
    }
}
