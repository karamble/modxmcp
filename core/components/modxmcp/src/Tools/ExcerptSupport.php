<?php

namespace MODXMCP\Tools;

/**
 * Locating a needle in a body and quoting it back with context.
 *
 * Two details here are less obvious than they look.
 *
 * The first is multibyte safety. Slicing a UTF-8 body with substr() splits a
 * codepoint whenever the cut lands mid-character, and the result is invalid
 * UTF-8. json_encode() does not warn about that: it returns false for the whole
 * payload, so a single accented character in one excerpt empties the entire
 * response. mb_* everywhere, with a byte-wise fallback for installs built
 * without mbstring.
 *
 * The second is that every match is reported, not just the first. The reason to
 * search element bodies at all is usually a rename, and a rename needs every
 * call site. Stopping at the first one produces a search that is worse than
 * useless, because it looks complete.
 */
trait ExcerptSupport
{
    /** Characters of context either side of a match. */
    private const EXCERPT_PAD = 45;

    /** Matches reported per body before the rest are counted but not quoted. */
    private const EXCERPT_LIMIT = 12;

    /**
     * Every occurrence of any needle, with a line number and quoted context.
     *
     * @param string[] $needles
     * @return array{count:int,excerpts:array<int,array{line:int,text:string}>,truncated:bool}
     */
    protected function excerpts(string $body, array $needles, bool $caseSensitive = false): array
    {
        $offsets = [];
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            $from = 0;
            while (($at = $this->indexOf($body, $needle, $from, $caseSensitive)) !== false) {
                $offsets[$at] = $this->length($needle);
                $from = $at + 1;
            }
        }

        if ($offsets === []) {
            return ['count' => 0, 'excerpts' => [], 'truncated' => false];
        }

        ksort($offsets);
        $count = count($offsets);

        $excerpts = [];
        foreach (array_slice($offsets, 0, self::EXCERPT_LIMIT, true) as $at => $len) {
            $start = max(0, $at - self::EXCERPT_PAD);
            $text  = $this->slice($body, $start, ($at - $start) + $len + self::EXCERPT_PAD);

            $excerpts[] = [
                'line' => $this->lineAt($body, $at),
                'text' => ($start > 0 ? '...' : '')
                    . trim(preg_replace('/\s+/u', ' ', $text) ?? $text)
                    . ($start + $this->length($text) < $this->length($body) ? '...' : ''),
            ];
        }

        return [
            'count'     => $count,
            'excerpts'  => $excerpts,
            'truncated' => $count > self::EXCERPT_LIMIT,
        ];
    }

    /**
     * Escape a literal needle for a LIKE comparison.
     *
     * Without this, searching for "100%" matches every row in the table and the
     * caller has no way to tell that it did.
     */
    protected function escapeLike(string $needle): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
    }

    /**
     * @return int|false
     */
    private function indexOf(string $haystack, string $needle, int $offset, bool $caseSensitive)
    {
        if (function_exists('mb_strpos')) {
            return $caseSensitive
                ? mb_strpos($haystack, $needle, $offset, 'UTF-8')
                : mb_stripos($haystack, $needle, $offset, 'UTF-8');
        }
        return $caseSensitive
            ? strpos($haystack, $needle, $offset)
            : stripos($haystack, $needle, $offset);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function slice(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, $start, $length, 'UTF-8')
            : substr($value, $start, $length);
    }

    /**
     * 1-indexed line of a character offset, so a caller can jump straight to it.
     */
    private function lineAt(string $body, int $offset): int
    {
        $before = $this->slice($body, 0, $offset);
        return substr_count($before, "\n") + 1;
    }
}
