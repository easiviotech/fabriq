<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;

abstract class Controller
{
    protected function json(Response $response, array $data, int $status = 200): void
    {
        $response->json($data, $status);
    }

    protected function notFound(Response $response, string $message = 'Not found'): void
    {
        $response->error($message, 404);
    }

    protected function badRequest(Response $response, string $message): void
    {
        $response->error($message, 400);
    }
}
