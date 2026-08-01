<?php

namespace MODXMCP\Knowledge;

/**
 * One thing about this site that a caller could not work out for itself.
 *
 * These describe rules that live in extras' event handlers and config blobs
 * rather than in any schema, so no amount of introspection will surface them.
 * The Collections rule is the archetype: a child of a container that keeps
 * show_in_tree=1 vanishes from listings while remaining published and reachable,
 * and nothing about the symptom points at the cause.
 *
 * Two properties matter more than the prose.
 *
 * Detected, not asserted. An advisory is produced only when the site actually
 * exhibits the condition, which is why detect() may return null. Warning about
 * Collections on a site with no containers trains a reader to skim the block
 * that also carries the real blockers.
 *
 * Machine-usable. `detected` carries the evidence and `rule` carries something
 * testable, so a model can check its own pending call against it instead of
 * parsing a sentence. Everything a tool returns is emitted verbatim as
 * structuredContent, so this needs no separate encoding.
 */
final class Advisory
{
    /** Content is silently lost or invisible. */
    public const BLOCKER = 'blocker';

    /** Surprising, but recoverable once known. */
    public const GOTCHA = 'gotcha';

    /** Informational, including "checked, and clean". */
    public const NOTE = 'note';

    private string $id;
    private string $severity;
    private string $summary;
    /** @var array<string,mixed> */
    private array $detected;
    /** @var array<string,mixed> */
    private array $rule;
    /** @var array<string,mixed>|null */
    private ?array $extra = null;

    /**
     * @param array<string,mixed> $detected
     * @param array<string,mixed> $rule
     */
    private function __construct(
        string $id,
        string $severity,
        string $summary,
        array $detected,
        array $rule
    ) {
        $this->id       = $id;
        $this->severity = $severity;
        $this->summary  = $summary;
        $this->detected = $detected;
        $this->rule     = $rule;
    }

    /**
     * @param array<string,mixed> $detected
     * @param array<string,mixed> $rule
     */
    public static function blocker(string $id, string $summary, array $detected, array $rule): self
    {
        return new self($id, self::BLOCKER, $summary, $detected, $rule);
    }

    /**
     * @param array<string,mixed> $detected
     * @param array<string,mixed> $rule
     */
    public static function gotcha(string $id, string $summary, array $detected, array $rule): self
    {
        return new self($id, self::GOTCHA, $summary, $detected, $rule);
    }

    /**
     * @param array<string,mixed> $detected
     * @param array<string,mixed> $rule
     */
    public static function note(string $id, string $summary, array $detected, array $rule): self
    {
        return new self($id, self::NOTE, $summary, $detected, $rule);
    }

    /** Immutable: returns a copy rather than mutating in place. */
    public function withExtra(string $namespace, ?string $version = null): self
    {
        $clone        = clone $this;
        $clone->extra = ['namespace' => $namespace, 'version' => $version];

        return $clone;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [
            'id'       => $this->id,
            'severity' => $this->severity,
            'summary'  => $this->summary,
            'detected' => $this->detected,
            'rule'     => $this->rule,
        ];

        if ($this->extra !== null) {
            $out['extra'] = $this->extra;
        }

        return $out;
    }
}
