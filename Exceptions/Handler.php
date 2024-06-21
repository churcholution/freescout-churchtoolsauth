<?php

namespace App;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class ChurchToolsAuthExceptionHandler extends ExceptionHandler
{
    public function render($request, Exception $exception)
    {
        /*
        if ($exception instanceof \CTApi\Exceptions\CTAuthException) {
            return response()->json(['error' => 'Example'], 500);
        }
        */

        return parent::render($request, $exception);
    }

     /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param \Exception $exception
     *
     * @return void
     */
    public function report(Exception $exception)
    {
        /*
        if ($exception instanceof \CTApi\Exceptions\CTAuthException) {
            return;
        }
        */
        
        parent::report($exception);

    }

}