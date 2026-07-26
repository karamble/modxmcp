<?php

namespace MODXMCP\Protocol;

/**
 * A parsed, not-yet-validated JSON-RPC request.
 *
 * Construction only decodes the envelope. Everything the specification requires
 * a server to reject lives in the protocol implementation, so that validation
 * order stays explicit and testable rather than scattered through a parser.
 */
final class Request
{
    /** @var mixed */
    private $id;
    private string $method;
    /** @var array<string,mixed> */
    private array $params;
    /** @var array<string,mixed> */
    private array $meta;
    private bool $isNotification;

    /**
     * @param mixed                $id
     * @param array<string,mixed>  $params
     * @param array<string,mixed>  $meta
     */
    public function __construct($id, string $method, array $params, array $meta, bool $isNotification)
    {
        $this->id             = $id;
        $this->method         = $method;
        $this->params         = $params;
        $this->meta           = $meta;
        $this->isNotification = $isNotification;
    }

    /**
     * @throws McpException on malformed JSON or a non-conforming envelope
     */
    public static function fromJson(string $raw): self
    {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw McpException::parseError();
        }

        $method = isset($body['method']) && is_string($body['method']) ? $body['method'] : '';
        if (($body['jsonrpc'] ?? '') !== '2.0' || $method === '') {
            throw McpException::invalidRequest();
        }

        $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : [];
        $meta   = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : [];

        // Absence of `id` is what makes a message a notification. A null id is
        // still an id, so array_key_exists is required here, not isset().
        return new self($body['id'] ?? null, $method, $params, $meta, !array_key_exists('id', $body));
    }

    /** @return mixed */
    public function id()
    {
        return $this->id;
    }

    public function method(): string
    {
        return $this->method;
    }

    /** @return array<string,mixed> */
    public function params(): array
    {
        return $this->params;
    }

    /** @return mixed */
    public function param(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    public function isNotification(): bool
    {
        return $this->isNotification;
    }

    public function protocolVersion(): ?string
    {
        $v = $this->meta[Meta::VERSION] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @return array<string,mixed> */
    public function clientInfo(): array
    {
        $i = $this->meta[Meta::CLIENT_INFO] ?? [];
        return is_array($i) ? $i : [];
    }
}
