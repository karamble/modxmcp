<?php

namespace MODXMCP\Tools;

use MODX\Revolution\Sources\modFileMediaSource;
use MODX\Revolution\Sources\modMediaSource;
use MODX\Revolution\modX;

/**
 * What a media source is, and where it points.
 *
 * Two tools need the same answers and would otherwise disagree about them.
 * file_upload has to refuse a source the administrator has not allowed;
 * media_source_list has to say whether a source would be refused, which is the
 * same question without the exception. One predicate means the listing cannot
 * promise an upload the upload path then rejects.
 *
 * Asking a source where it is rooted is the awkward part, and is more dangerous
 * than it looks. See resolveBases().
 */
trait MediaSourceSupport
{
    /**
     * Columns of modMediaSource that may be returned.
     *
     * A positive list, not a redaction pass, and that is not a stylistic
     * preference. The table has six columns and the sixth is `properties`,
     * which holds an S3 key and secret or an FTP password for a remote source.
     * ClassGuard::SENSITIVE_FIELDS matches field names, and no pattern in it
     * matches the name "properties" -- which is exactly why media sources are
     * hard-blocked from generic access rather than merely redacted. Naming what
     * may be returned cannot leak a column nobody thought about; naming what
     * may not, can.
     */
    private const SOURCE_FIELDS = ['id', 'name', 'description', 'class_key', 'is_stream'];

    /**
     * Is this media source one file_upload will accept?
     *
     * The predicate behind FileUploadTool::assertSourceAllowed(), which keeps
     * the refusal and its wording. Being allowlisted is necessary and not
     * sufficient: the path allowlist, the extension allowlist, upload_maxsize
     * and the source's own MODX policy all still apply, which is why the field
     * built on this is named after the setting it checks rather than after the
     * outcome.
     *
     * Quirks of the setting, all deliberate and all differing from the path
     * allowlist beside it: it defaults to '1' rather than empty, so uploads to
     * the default source are not disabled out of the box; entries are exact ids
     * with no '*' wildcard; and the split is the comma-or-whitespace idiom used
     * for every list setting here.
     */
    protected function sourceAllowedForUpload(modX $modx, int $sourceId): bool
    {
        return in_array((string) $sourceId, $this->uploadSourceAllowlist($modx), true);
    }

    /**
     * The raw entries of modxmcp.upload_source_allowlist.
     *
     * @return string[]
     */
    protected function uploadSourceAllowlist(modX $modx): array
    {
        $raw = trim((string) $modx->getOption('modxmcp.upload_source_allowlist', null, '1'));

        return preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Where a source is rooted, and a state saying how far the answer got.
     *
     * Neither the path nor the URL is a column, and reaching them means
     * initialize(), which is not an accessor. Three hazards, each of which the
     * first version of this method got wrong:
     *
     *  - modS3MediaSource::initialize() constructs an S3Client and, unless
     *    no_check_bucket is set, calls doesBucketExist() -- a live HeadBucket
     *    request with the SDK's retry budget behind it. A read-scope listing
     *    must not be able to block on somebody else's endpoint, so remote
     *    sources are not resolved unless asked for. Ancestry decides, not the
     *    class name: a remote backend subclasses modMediaSource directly, so
     *    anything that is not a modFileMediaSource is treated as remote.
     *
     *  - initialize() reports failure by RETURNING FALSE, not by throwing. S3
     *    catches its own exception, logs it and returns false, and getBasePath()
     *    then still answers from the stored url property. Ignoring the return
     *    value is how a dead source comes back looking perfectly resolved.
     *
     *  - getBases() sets pathAbsolute to '' when realpath() fails, which is
     *    what happens when the configured directory does not exist on disk.
     *    That is a misconfigured source, not one resolved to nothing, and it is
     *    the actionable case, so it is reported separately and names the
     *    configured value.
     *
     * getBases() once rather than getBasePath() plus getBaseUrl(): each of
     * those re-enters getProperties(), which invokes the
     * OnMediaSourceGetProperties event and so runs every extra's plugin. One
     * call halves that and removes any chance of the two halves disagreeing.
     *
     * @param mixed $source a modMediaSource, already loaded
     * @return array{base_path:?string,base_path_configured:?string,base_url:?string,paths:string}
     */
    protected function resolveBases(modX $modx, $source, bool $includeRemote = false): array
    {
        $unresolved = static fn(string $state, ?string $configured = null): array => [
            'base_path'            => null,
            'base_path_configured' => $configured,
            'base_url'             => null,
            'paths'                => $state,
        ];

        if (!$includeRemote && !($source instanceof modFileMediaSource)) {
            return $unresolved('not_attempted');
        }

        try {
            if ($source->initialize() === false) {
                // MODX has already logged why; repeating it here would add
                // nothing and could carry an endpoint or a bucket name.
                return $unresolved('backend_unavailable');
            }

            $bases = $source->getBases();
        } catch (\Throwable $e) {
            // The detail goes to the log, not the caller: it can carry
            // filesystem paths and remote endpoints, and the caller already
            // learns what matters from the state.
            $modx->log(modX::LOG_LEVEL_ERROR, sprintf(
                'modxmcp: media source %d (%s) could not resolve its bases: %s',
                (int) $source->get('id'),
                (string) $source->get('name'),
                $e->getMessage()
            ));

            return $unresolved('error');
        }

        $configured = (string) ($bases['path'] ?? '');
        $absolute   = (string) ($bases['pathAbsolute'] ?? '');
        $url        = (string) ($bases['urlAbsolute'] ?? ($bases['url'] ?? ''));

        if ($absolute === '') {
            return $unresolved('base_path_missing', $configured);
        }

        return [
            'base_path'            => $absolute,
            'base_path_configured' => $configured,
            'base_url'             => $url === '' ? null : $this->absoluteSourceUrl($modx, $url),
            'paths'                => 'resolved',
        ];
    }

    /**
     * A source URL as something a caller can open.
     *
     * Shared with FileUploadTool::publicUrl() rather than reimplemented beside
     * it, so the two cannot disagree about what a URL from a media source looks
     * like -- the same argument that put the allowlist predicate here. A source
     * configured with a full URL is left alone; a site-relative one is
     * absolutised against site_url.
     */
    protected function absoluteSourceUrl(modX $modx, string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return rtrim((string) $modx->getOption('site_url'), '/') . '/' . ltrim($url, '/');
    }

    /** @return string[] */
    protected function sourceFields(): array
    {
        return self::SOURCE_FIELDS;
    }

    /** The class every media source descends from, for a getCollection target. */
    protected function mediaSourceClass(): string
    {
        return modMediaSource::class;
    }
}
