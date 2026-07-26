<?php

namespace MODXMCP\Protocol;

/**
 * A protocol-level failure that must be rendered as a JSON-RPC error response.
 *
 * Carries the HTTP status alongside the JSON-RPC code because MCP couples them:
 * an unknown method is 404 + -32601, a header mismatch is 400 + -32020, and a
 * client uses that pairing to tell a modern server from a legacy one.
 */
class McpException extends \RuntimeException
{
    private int $httpStatus;

    /** @var array<string,mixed>|null */
    private ?array $data;

    /**
     * @param array<string,mixed>|null $data
     */
    public function __construct(int $httpStatus, int $code, string $message, ?array $data = null)
    {
        parent::__construct($message, $code);
        $this->httpStatus = $httpStatus;
        $this->data       = $data;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string,mixed>|null */
    public function data(): ?array
    {
        return $this->data;
    }

    // --- named constructors, so call sites read as the rule they enforce ---

    public static function parseError(): self
    {
        return new self(400, Errors::PARSE, 'Parse error');
    }

    public static function invalidRequest(string $why = 'Invalid Request'): self
    {
        return new self(400, Errors::INVALID_REQUEST, $why);
    }

    public static function invalidParams(string $why): self
    {
        return new self(400, Errors::INVALID_PARAMS, $why);
    }

    public static function headerMismatch(string $why): self
    {
        return new self(400, Errors::HEADER_MISMATCH, $why);
    }

    /** @param string[] $supported */
    public static function unsupportedVersion(string $requested, array $supported): self
    {
        return new self(400, Errors::UNSUPPORTED_VERSION, 'Unsupported protocol version', [
            'supported' => $supported,
            'requested' => $requested,
        ]);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(404, Errors::METHOD_NOT_FOUND, "Method not found: {$method}");
    }

    public static function unauthorized(string $why = 'Unauthorized'): self
    {
        return new self(401, Errors::INVALID_REQUEST, $why);
    }

    public static function forbidden(string $why): self
    {
        return new self(403, Errors::INVALID_REQUEST, $why);
    }

    public static function internal(string $why): self
    {
        return new self(500, Errors::INTERNAL, $why);
    }

    public static function unavailable(string $why): self
    {
        return new self(503, Errors::INTERNAL, $why);
    }
}
