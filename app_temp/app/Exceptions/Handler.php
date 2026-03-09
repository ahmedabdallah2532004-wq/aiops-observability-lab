<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Http\Request;

class Handler
{
    public static function categorizeError(?Throwable $e, float $latencyMs = 0): string
    {
        if ($latencyMs > 4000) {
            return 'TIMEOUT_ERROR';
        }

        if (!$e) {
            return 'UNKNOWN';
        }

        if ($e instanceof ValidationException) {
            return 'VALIDATION_ERROR';
        }

        if ($e instanceof QueryException || $e instanceof \PDOException) {
            return 'DATABASE_ERROR';
        }

        if ($e instanceof NotFoundHttpException) {
            return 'UNKNOWN';
        }

        return 'SYSTEM_ERROR';
    }

    public static function report(Throwable $e, Request $request)
    {
        $category = self::categorizeError($e);
        $request->attributes->set('error_category', $category);
    }
}
