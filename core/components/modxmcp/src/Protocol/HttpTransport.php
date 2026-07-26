<?php

namespace MODXMCP\Protocol;

/**
 * Streamable HTTP binding for MCP revision 2026-07-28.
 *
 * Deliberately never opens an SSE stream. The specification lets a server answer
 * each request with either a single JSON object or a request-scoped SSE stream;
 * always choosing JSON means no long-lived connections (which PHP-FPM handles
 * badly), no keep-alive bookkeeping, and no need to advertise subscriptions.
 */
final class HttpTransport
{
    /** Read a request header case-insensitively. */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        // Content-Type and Content-Length arrive without the HTTP_ prefix.
        $bare = strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$bare]) ? (string) $_SERVER[$bare] : null;
    }

    public function requestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public function body(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * Decode the specification's base64 sentinel form: =?base64?<payload>?=
     *
     * Servers MUST decode before comparing a mirrored header to its body value,
     * otherwise any non-ASCII tool name would look like a header mismatch.
     */
    public function decodeHeaderValue(string $value): string
    {
        if (strlen($value) > 11
            && strncmp($value, '=?base64?', 9) === 0
            && substr($value, -2) === '?=') {
            $decoded = base64_decode(substr($value, 9, -2), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $value;
    }

    /**
     * Transport-level checks that happen before any JSON is parsed.
     *
     * Returns true when the caller should stop: the response has been sent.
     */
    public function rejectedByPreflight(): bool
    {
        // POST only. Earlier revisions used GET for a standalone SSE stream and
        // DELETE to end a session; 2026-07-28 has neither.
        if ($this->requestMethod() !== 'POST') {
            header('Allow: POST');
            $this->emit(405, null);
            return true;
        }

        // Origin validation is the defence against DNS rebinding, and the spec
        // makes it mandatory. Normal MCP clients send no Origin at all; a
        // browser always does, so a mismatch means a page is talking to us.
        $origin = $this->header('Origin');
        if ($origin !== null && $origin !== '') {
            $originHost = (string) parse_url($origin, PHP_URL_HOST);
            $host       = (string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':');
            if ($originHost === '' || strcasecmp($originHost, $host) !== 0) {
                $this->emitError(403, null, Errors::INVALID_REQUEST, 'Origin not allowed');
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    public function emit(int $status, ?array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($payload !== null) {
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @param mixed                    $id
     * @param array<string,mixed>|null $data
     */
    public function emitError(int $status, $id, int $code, string $message, ?array $data = null): void
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        $this->emit($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
    }

    /**
     * @param mixed                $id
     * @param array<string,mixed>|\stdClass $result Some results are empty JSON
     *        objects (ping) and so must not be arrays, which encode as [].
     */
    public function emitResult($id, $result): void
    {
        $this->emit(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    public function emitException(McpException $e, $id): void
    {
        $this->emitError($e->httpStatus(), $id, (int) $e->getCode(), $e->getMessage(), $e->data());
    }
}
