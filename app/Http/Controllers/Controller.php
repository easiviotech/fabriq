<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;

/**
 * Base controller — shared helpers for all controllers.
 *
 * All controllers should extend this class.
 */
abstract class Controller
{
    /**
     * Generate a UUID v4.
     */
    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // v4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

