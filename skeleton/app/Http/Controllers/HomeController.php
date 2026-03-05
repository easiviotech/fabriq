<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;

class HomeController extends Controller
{
    public function index(Request $request, Response $response): void
    {
        $this->json($response, [
            'message' => 'Welcome to Fabriq',
            'docs'    => 'https://fabriq.dev/docs',
        ]);
    }
}
