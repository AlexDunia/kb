<?php
namespace App\Support;
use InvalidArgumentException;
final class Money { public static function decimalToMinor(string|int|float $value): int { $value=trim((string)$value); if(!preg_match('/^\d+(?:\.(\d{1,2}))?$/',$value,$matches)){throw new InvalidArgumentException('Invalid money value.');} [$whole,$fraction]=array_pad(explode('.',$value,2),2,''); $fraction=str_pad(substr($fraction,0,2),2,'0'); return ((int)$whole*100)+(int)$fraction; } }