<?php

declare(strict_types=1);

namespace Fabriq\Http;

use Fabriq\Http\Exception\HttpException;
use Fabriq\Http\Exception\ValidationException;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Centralized error handler for HTTP requests.
 *
 * In debug mode, renders a detailed HTML error page with stack trace,
 * code snippets, and request details. In production mode, returns a
 * safe JSON response with no internal details leaked.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug = false,
    ) {}

    /**
     * Handle an exception and send the appropriate response.
     */
    public function handle(\Throwable $e, Request $request, Response $response): void
    {
        $statusCode = $this->resolveStatusCode($e);

        foreach ($this->resolveHeaders($e) as $name => $value) {
            $response->header($name, $value);
        }

        $response->status($statusCode);

        if ($this->debug) {
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->end($this->renderDebugPage($e, $request, $statusCode));
        } else {
            $response->header('Content-Type', 'application/json');
            $response->end($this->renderProductionJson($e, $statusCode));
        }
    }

    private function resolveStatusCode(\Throwable $e): int
    {
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }

        return 500;
    }

    /**
     * @return array<string, string>
     */
    private function resolveHeaders(\Throwable $e): array
    {
        if ($e instanceof HttpException) {
            return $e->getHeaders();
        }

        return [];
    }

    private function renderProductionJson(\Throwable $e, int $statusCode): string
    {
        $body = [
            'error' => $this->statusText($statusCode),
        ];

        if ($e instanceof ValidationException) {
            $body['errors'] = $e->getErrors();
        }

        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function renderDebugPage(\Throwable $e, Request $request, int $statusCode): string
    {
        $class = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $file = $e->getFile();
        $line = $e->getLine();
        $trace = $e->getTraceAsString();

        $codeSnippet = $this->extractCodeSnippet($file, $line);

        $method = strtoupper($request->server['request_method'] ?? 'GET');
        $uri = htmlspecialchars($request->server['request_uri'] ?? '/', ENT_QUOTES, 'UTF-8');
        $headers = $request->header ?? [];
        $headersHtml = '';
        foreach ($headers as $name => $value) {
            $headersHtml .= '<tr><td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                . '</td><td>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $statusText = $this->statusText($statusCode);
        $traceHtml = htmlspecialchars($trace, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$statusCode} {$statusText} — Fabriq</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#1a1a2e;color:#e0e0e0;line-height:1.6}
.header{background:#e94560;color:#fff;padding:2rem;border-bottom:4px solid #c81e45}
.header h1{font-size:1.5rem;font-weight:600}
.header .status{font-size:0.9rem;opacity:0.8;margin-top:0.25rem}
.container{max-width:1200px;margin:0 auto;padding:2rem}
.section{background:#16213e;border-radius:8px;margin-bottom:1.5rem;overflow:hidden;border:1px solid #0f3460}
.section-title{background:#0f3460;padding:0.75rem 1.25rem;font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:0.05em}
.section-body{padding:1.25rem}
.exception-class{color:#e94560;font-family:monospace;font-size:1.1rem}
.exception-msg{margin-top:0.5rem;font-size:1rem;color:#f5f5f5}
.file-info{margin-top:0.5rem;color:#8892b0;font-family:monospace;font-size:0.85rem}
pre{background:#0a0e27;padding:1rem;border-radius:4px;overflow-x:auto;font-size:0.85rem;line-height:1.8}
pre code{color:#a9b7c6}
.line-highlight{background:rgba(233,69,96,0.2);display:block}
.line-num{color:#636d83;user-select:none;display:inline-block;width:3.5em;text-align:right;margin-right:1em}
table{width:100%;border-collapse:collapse}
table td{padding:0.4rem 0.75rem;border-bottom:1px solid #0f3460;font-family:monospace;font-size:0.85rem}
table td:first-child{color:#e94560;white-space:nowrap;width:200px}
.trace{white-space:pre-wrap;word-break:break-all;color:#8892b0}
.badge{display:inline-block;background:#e94560;color:#fff;padding:0.15rem 0.5rem;border-radius:3px;font-size:0.8rem;font-weight:600;margin-right:0.5rem}
</style>
</head>
<body>
<div class="header">
<h1><span class="badge">{$statusCode}</span> {$statusText}</h1>
<div class="status">{$method} {$uri}</div>
</div>
<div class="container">
<div class="section">
<div class="section-title">Exception</div>
<div class="section-body">
<div class="exception-class">{$class}</div>
<div class="exception-msg">{$message}</div>
<div class="file-info">{$file}:{$line}</div>
</div>
</div>

<div class="section">
<div class="section-title">Source</div>
<div class="section-body"><pre><code>{$codeSnippet}</code></pre></div>
</div>

<div class="section">
<div class="section-title">Stack Trace</div>
<div class="section-body"><pre class="trace">{$traceHtml}</pre></div>
</div>

<div class="section">
<div class="section-title">Request Headers</div>
<div class="section-body"><table>{$headersHtml}</table></div>
</div>
</div>
</body>
</html>
HTML;
    }

    private function extractCodeSnippet(string $file, int $line, int $padding = 8): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '<span class="line-num">-</span>File not readable';
        }

        $lines = file($file);
        if ($lines === false) {
            return '<span class="line-num">-</span>Could not read file';
        }

        $start = max(0, $line - $padding - 1);
        $end = min(count($lines), $line + $padding);
        $snippet = '';

        for ($i = $start; $i < $end; $i++) {
            $lineNum = $i + 1;
            $code = htmlspecialchars(rtrim($lines[$i]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $highlight = $lineNum === $line ? ' class="line-highlight"' : '';
            $snippet .= "<span{$highlight}><span class=\"line-num\">{$lineNum}</span>{$code}</span>\n";
        }

        return $snippet;
    }

    private function statusText(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
