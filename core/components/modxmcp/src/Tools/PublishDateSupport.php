<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * Publish state and scheduling.
 *
 * The Resource processors treat these five fields as a small state machine, not
 * as columns, and almost every rule in it is undocumented. The three that bite:
 *
 * Dates are parsed with a bare strtotime() and nothing validates the result.
 * strtotime('1754000000') is false, and false then satisfies the processor's
 * "is this date in the past" test, so a caller who sends a UNIX timestamp as a
 * string does not schedule a post: it publishes immediately. Everything is
 * normalised to 'Y-m-d H:i:s' here and anything unparseable is refused, because
 * the failure mode is publishing a draft rather than an error message.
 *
 * publishedon is silently discarded unless `published` is sent in the same call
 * (Resource/Update.php:473-476, where an absent `published` reads as empty and
 * triggers unsetProperty). Same shape as the tvs flag: a property whose absence
 * quietly disables another one.
 *
 * And a caller without the publish_document permission gets every one of these
 * fields reset to its stored value while the save still reports success
 * (Resource/Update.php:486-508). That cannot be detected before the write, so it
 * is checked after it, against the row the tool re-reads anyway.
 */
trait PublishDateSupport
{
    /** Fields the processors reset together when the publish permission is missing. */
    private static array $publishFields = ['published', 'publishedon', 'pub_date', 'unpub_date'];

    /**
     * Normalise a caller-supplied date to what the processors parse reliably.
     *
     * Accepts 'Y-m-d H:i:s', ISO 8601, anything else strtotime() understands,
     * and a genuine integer epoch. Refuses a numeric string, which strtotime()
     * rejects and the processor then misreads as "in the past".
     *
     * @param mixed $value
     * @return string '' means "clear the schedule"
     * @throws McpException
     */
    protected function normaliseDate($value, string $field): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return '';
        }

        if (is_int($value)) {
            return date('Y-m-d H:i:s', $value);
        }

        $raw = trim((string) $value);

        // A numeric string is ambiguous and strtotime() simply fails on it, so
        // it is refused rather than guessed at. Guessing wrong here publishes
        // something that was meant to stay a draft.
        if ($raw !== '' && ctype_digit($raw)) {
            throw McpException::invalidParams(sprintf(
                "%s looks like a UNIX timestamp ('%s'). MODX parses these fields with "
                . 'strtotime(), which rejects a bare number and then treats the result as a '
                . 'date in the past, publishing the resource immediately. Send a date string '
                . "such as '%s' instead. Nothing was written.",
                $field,
                $raw,
                date('Y-m-d H:i:s', (int) $raw)
            ));
        }

        $parsed = strtotime($raw);
        if ($parsed === false) {
            throw McpException::invalidParams(sprintf(
                "Could not read %s as a date ('%s'). Use 'YYYY-MM-DD HH:MM:SS', e.g. '%s'. "
                . 'Nothing was written.',
                $field,
                $raw,
                date('Y-m-d H:i:s', time() + 86400)
            ));
        }

        return date('Y-m-d H:i:s', $parsed);
    }

    /**
     * Fold the publish arguments into the processor properties.
     *
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $stored     current row, empty when creating
     * @return string[] warnings
     * @throws McpException
     */
    protected function applyPublishDates(
        array $arguments,
        array &$properties,
        array $stored = []
    ): array {
        $warnings = [];

        if (array_key_exists('publishedby', $arguments) && $arguments['publishedby'] !== null
            && $stored !== []) {
            throw McpException::invalidParams(
                'publishedby cannot be set when updating a resource. MODX overwrites it on '
                . 'every save: it becomes the current user when a resource is published and 0 '
                . 'when it is unpublished. Nothing was written.');
        }

        $touched = false;
        foreach (['pub_date', 'unpub_date', 'publishedon'] as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $properties[$field] = $this->normaliseDate($arguments[$field], $field);
            $touched = true;
        }

        if (!$touched) {
            return $warnings;
        }

        // publishedon is dropped unless `published` accompanies it, so carry the
        // stored value forward when the caller did not state one.
        if (array_key_exists('publishedon', $properties) && !array_key_exists('published', $arguments)) {
            $properties['published'] = !empty($stored['published']) ? 1 : 0;
        }

        // A future pub_date with published=1 is the contradiction worth catching.
        // The processor resolves it the same way, silently; saying so is the
        // difference between a schedule and a post that went out early.
        $pubDate = $properties['pub_date'] ?? '';
        if ($pubDate !== '' && strtotime($pubDate) > time() && !empty($properties['published'])) {
            $properties['published'] = 0;
            $warnings[] = sprintf(
                'pub_date is in the future (%s), so published was set to false. A resource '
                . 'cannot be published now and scheduled for later at the same time; MODX '
                . 'publishes it when the date arrives.',
                $pubDate
            );
        }

        return $warnings;
    }

    /**
     * Confirm the publish state the caller asked for is the state that was saved.
     *
     * Without publish_document MODX restores all of these from the stored row and
     * still reports success, so this is the only place the caller can find out.
     * It compares against the row the tool re-reads anyway, so it costs nothing.
     *
     * Only fields the caller actually named are compared. On update $properties
     * starts from the stored row, so every publish field is present on every
     * call; comparing all of them would report a difference on updates that never
     * touched publishing.
     *
     * @param array<string,mixed> $arguments  what the caller sent
     * @param array<string,mixed> $properties what was submitted to the processor
     * @param array<string,mixed> $saved      the re-read row
     * @return string[]
     */
    protected function publishStateWarnings(
        modX $modx,
        array $arguments,
        array $properties,
        array $saved
    ): array {
        $differs = [];
        foreach (self::$publishFields as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (!array_key_exists($field, $properties)) {
                continue;
            }
            if (!$this->sameMoment($properties[$field], $saved[$field] ?? null)) {
                $differs[] = $field;
            }
        }

        if ($differs === []) {
            return [];
        }

        return [sprintf(
            'The saved publish state does not match what was requested (%s). MODX silently '
            . 'restores these fields when the account behind this token lacks the '
            . 'publish_document or unpublish_document permission, and reports success anyway. '
            . 'Check those permissions for the user the token is bound to.',
            implode(', ', $differs)
        )];
    }

    /**
     * @param mixed $requested
     * @param mixed $saved
     */
    private function sameMoment($requested, $saved): bool
    {
        // published is a flag; the rest are moments in time.
        if (is_bool($requested) || $requested === 0 || $requested === 1) {
            return (int) (bool) $requested === (int) (bool) $saved;
        }

        // Both sides are normalised through the same parse because the two
        // representations differ. These columns are int in the database, but
        // their phptype is `timestamp`, so xPDO hands them back as
        // 'Y-m-d H:i:s' strings while raw SQL returns the epoch. Comparing a
        // parsed request against an unparsed read makes every date look wrong.
        $left  = $this->toEpoch($requested);
        $right = $this->toEpoch($saved);

        // A minute of slack: publishedon defaults to time() when the caller asked
        // for "now", and the re-read happens a moment later.
        return abs($left - $right) <= 60;
    }

    /** @param mixed $value */
    private function toEpoch($value): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        $raw = trim((string) $value);
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        return (int) strtotime($raw);
    }

    /** @return array<string,mixed> */
    protected function publishSchema(): array
    {
        return [
            'pub_date' => \MODXMCP\Registry\Schema::string(
                'Publish automatically at this date and time, e.g. "2026-08-14 09:00:00". '
                . 'To schedule a post, pass published=false together with a future pub_date; '
                . 'MODX publishes it when the date arrives. Send a date string, not a UNIX '
                . 'timestamp. Pass an empty string to clear the schedule.'),
            'unpub_date' => \MODXMCP\Registry\Schema::string(
                'Unpublish automatically at this date and time. Same format as pub_date.'),
            'publishedon' => \MODXMCP\Registry\Schema::string(
                'The date shown as the publication date. Display only: it does not control '
                . 'whether the resource is visible, and it is not what schedules a post. Use '
                . 'it to backdate an article. Ignored unless the resource is published.'),
        ];
    }
}
