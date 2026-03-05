<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;

abstract class Controller
{
    protected function json(Response $response, array $data, int $status = 200): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    protected function notFound(Response $response, string $message = 'Not found'): void
    {
        $this->json($response, ['error' => $message], 404);
    }

    protected function badRequest(Response $response, string $message): void
    {
        $this->json($response, ['error' => $message], 400);
    }
}
