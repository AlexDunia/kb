<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class UtcDates
{
    public static function row(object|array $row): array
    {
        $data = (array) $row;
        foreach ($data as $key => $value) {
            if ($value !== null && str_ends_with($key, '_at')) {
                $data[$key] = Carbon::parse($value, 'UTC')->utc()->toIso8601String();
            }
        }

        return $data;
    }
}
