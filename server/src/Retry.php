<?php

class Retry
{
    public static function run(callable $fn, int $attempts = 3, int $sleep = 2)
    {
        $lastException = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $fn();
            } catch (Exception $e) {
                $lastException = $e;
                sleep($sleep);
            }
        }

        throw $lastException;
    }
}
