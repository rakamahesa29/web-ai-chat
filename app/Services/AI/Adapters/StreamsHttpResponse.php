<?php

namespace App\Services\AI\Adapters;

/**
 * StreamsHttpResponse — reliable line-by-line streaming over a raw socket.
 *
 * Why this exists:
 *   Guzzle's `stream => true` option routes requests through PHP's native
 *   `http://` stream wrapper, which buffers keep-alive streaming responses.
 *   The result is that `fread($stream, 8192)` / `fgets($stream)` block forever
 *   once the response stops growing — the classic "stuck in thinking" bug
 *   (the model actually finished, but PHP never sees the final chunks).
 *
 *   A raw TCP/TLS socket with manual HTTP framing and explicit chunked
 *   transfer decoding streams each line to the consumer as soon as it arrives.
 */
trait StreamsHttpResponse
{
    /**
     * POST a JSON payload and yield each response body line as it streams in.
     *
     * @param  string  $url          Full target URL (http/https).
     * @param  array   $payload      JSON-encodable request body.
     * @param  array   $headers      Extra request headers (Host/Content-Type/Content-Length/Connection are auto-set).
     * @param  int     $timeout      Idle read timeout in seconds (per read, not total).
     * @param  string  $errorPrefix  Label used in thrown exceptions / logs.
     * @return \Generator<string>    Trimmed, dechunked body lines (NDJSON / SSE).
     *
     * @throws \Exception on connect failure or HTTP >= 400.
     */
    protected function streamLines(string $url, array $payload, array $headers = [], int $timeout = 300, string $errorPrefix = 'API'): \Generator
    {
        $parts  = parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $host   = $parts['host'] ?? '127.0.0.1';
        $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $transport = $scheme === 'https' ? 'tls' : 'tcp';
        $context   = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        $fp = @stream_socket_client(
            "{$transport}://{$host}:{$port}",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$fp) {
            throw new \Exception("Failed to connect to {$host}:{$port} — {$errstr} ({$errno})");
        }

        stream_set_timeout($fp, $timeout);

        $body = json_encode($payload);
        $headers = array_merge([
            'Host'           => ($port === 80 || $port === 443) ? $host : "{$host}:{$port}",
            'Content-Type'   => 'application/json',
            'Content-Length' => strlen($body),
            'Connection'     => 'close',
        ], $headers);

        $headerBlock = '';
        foreach ($headers as $name => $value) {
            $headerBlock .= "{$name}: {$value}\r\n";
        }

        fwrite($fp, "POST {$path} HTTP/1.1\r\n{$headerBlock}\r\n{$body}");

        // Status line
        $statusLine = fgets($fp);
        $statusCode = 0;
        if ($statusLine !== false && preg_match('#\s(\d{3})\s#', $statusLine, $m)) {
            $statusCode = (int) $m[1];
        }

        // Response headers
        $respHeaders = [];
        while (($line = fgets($fp)) !== false && $line !== "\r\n") {
            $line = trim($line);
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
                $respHeaders[strtolower($m[1])] = $m[2];
            }
        }

        if ($statusCode >= 400) {
            $errBody = (string) stream_get_contents($fp, 65536);
            fclose($fp);
            \Illuminate\Support\Facades\Log::error("{$errorPrefix} [HTTP {$statusCode}]", ['body' => $errBody]);
            throw new \Exception("{$errorPrefix} ({$statusCode}): " . ($errBody ?: $statusLine));
        }

        $isChunked = stripos($respHeaders['transfer-encoding'] ?? '', 'chunked') !== false;

        if ($isChunked) {
            yield from $this->readChunkedLines($fp);
        } else {
            while (($line = fgets($fp)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                yield $line;
            }
        }

        fclose($fp);
    }

    /**
     * Decode HTTP/1.1 chunked transfer encoding and yield complete lines.
     * Handles lines split across chunk boundaries via a carry buffer.
     */
    private function readChunkedLines($fp): \Generator
    {
        $carry = '';

        while (true) {
            $sizeLine = fgets($fp);
            if ($sizeLine === false) {
                break;
            }

            // Chunk size may include extensions: "1a;ext=value"
            $sizeStr = trim($sizeLine);
            if (($semi = strpos($sizeStr, ';')) !== false) {
                $sizeStr = substr($sizeStr, 0, $semi);
            }
            $size = hexdec($sizeStr);

            if ($size <= 0) {
                // Final chunk — consume trailing CRLF (may already be at EOF)
                fgets($fp);
                break;
            }

            $data = '';
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = fread($fp, $remaining);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
                $remaining -= strlen($chunk);
            }

            fgets($fp); // consume CRLF following chunk data

            $data  = $carry . $data;
            $lines = explode("\n", $data);
            $carry = array_pop($lines); // may be a partial line carried into next chunk

            foreach ($lines as $line) {
                $line = rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }
                yield $line;
            }
        }

        if ($carry !== '') {
            $carry = rtrim($carry, "\r");
            if ($carry !== '') {
                yield $carry;
            }
        }
    }
}
