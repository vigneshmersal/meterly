<?php

namespace App\Support;

use Illuminate\Database\QueryException;

final class DatabaseExceptionClassifier
{
    public static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === '23505') {
            return true;
        }

        if ((string) $exception->getCode() !== '23000') {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint');
    }
}
