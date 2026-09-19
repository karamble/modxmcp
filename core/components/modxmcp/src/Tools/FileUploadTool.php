<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODX\Revolution\Sources\modMediaSource;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Upload a file through the MODX Browser/File/Upload processor.
 *
 * Going through the processor rather than writing the file ourselves keeps the
 * premise of this extra intact for media too: the media source's access policy
 * is enforced, the upload_files extension setting and upload_maxsize apply, and
 * OnFileManagerBeforeUpload / OnFileManagerUpload fire, so plugins that resize
 * images or purge caches see the file exactly as if the Manager had uploaded it.
 *
 * The processor reads the $_FILES superglobal, and Flysystem underneath reads
 * the temp file with file_get_contents() rather than move_uploaded_file(), so a
 * decoded payload written to a private temp file passes through it unchanged.
 * That superglobal is swapped in for the one processor call and restored in a
 * finally block.
 *
 * Everything here is gated twice: MODX's own checks inside the processor, and
 * this extra's stricter defaults in front of it. The path allowlist ships empty,
 * which means uploads are off until an administrator names the directories an
 * agent may write into. That is deliberate and mirrors the generic-object
 * allowlists: a tool that can drop files anywhere under the web root is not a
 * default anyone should inherit by upgrading.
 */
final class FileUploadTool extends AbstractTool
{
    // The allowlist predicate, shared with media_source_list so the listing
    // cannot report an upload as permitted that this tool then refuses.
    use MediaSourceSupport;

    /**
     * Extensions no setting can enable, matched against EVERY dot-segment of
     * the filename, not just the final extension. "shell.php.jpg" ends in a
     * harmless .jpg, but an Apache AddHandler mapping matches any segment, so a
     * double extension is the classic way an upload gate gets outrun. The .ini
     * entry is for .user.ini, whose auto_prepend_file is remote code execution
     * with no PHP extension in sight.
     */
    private const BLOCKED_SEGMENTS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps',
        'phar', 'shtml', 'shtm', 'cgi', 'fcgi', 'asp', 'aspx', 'jsp',
        'htaccess', 'htpasswd', 'ini',
    ];

    /** MIME types finfo should roughly agree with, keyed by extension. */
    private const EXPECTED_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'pdf'  => 'application/pdf',
    ];

    public function requiredScope(): string
    {
        return 'write:media';
    }

    public function name(): string
    {
        return 'modxmcp_file_upload';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Upload a file',
            'description' => 'Upload one file into a media source, base64-encoded. Writes through '
                . 'the MODX upload processor, so the media source access policy, the upload_files '
                . 'and upload_maxsize system settings, and the file-manager upload events all '
                . 'apply exactly as they would for a Manager upload. Additionally gated by this '
                . 'extra\'s own settings: the target directory must be covered by '
                . 'modxmcp.upload_path_allowlist (empty means uploads are disabled), the '
                . 'extension by modxmcp.upload_extension_allowlist, the decoded size by '
                . 'modxmcp.upload_max_bytes, and the media source by '
                . 'modxmcp.upload_source_allowlist. The result echoes the stored path and the '
                . 'public URL, verified to exist after the write.',
            'inputSchema' => Schema::object([
                'path'           => Schema::string(
                    'Target directory, relative to the media source root, e.g. "images/uploads/". '
                    . 'It must already exist; this tool does not create directories.'),
                'filename'       => Schema::string(
                    'Name to store the file under. Letters, digits, dot, dash and underscore '
                    . 'only; directory separators are rejected rather than stripped.'),
                'content_base64' => Schema::string(
                    'The file content, base64-encoded. A data: URI prefix is tolerated and '
                    . 'stripped. Not recorded in the audit log.'),
                'source'         => Schema::integer(
                    'Media source id. The default filesystem source is 1.', 1),
                'overwrite'      => Schema::boolean(
                    'Replace an existing file of the same name. Off by default; the existing '
                    . 'file is removed through the file-manager remove processor first, so '
                    . 'removal events fire.', false),
            ], ['path', 'filename', 'content_base64']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $sourceId  = (int) $this->arg($arguments, 'source', 1);
        $overwrite = !empty($arguments['overwrite']);
        $warnings  = [];

        $path     = $this->normalisePath((string) $this->requireArg($arguments, 'path'));
        $filename = $this->validateFilename((string) $this->requireArg($arguments, 'filename'));
        $this->assertPathAllowed($modx, $path);
        $this->assertSourceAllowed($modx, $sourceId);
        $this->assertExtensionAllowed($modx, $filename);

        $content = $this->decodeContent($modx, (string) $this->requireArg($arguments, 'content_base64'));
        $size    = strlen($content);
        $mime    = $this->sniff($content, $filename, $warnings);

        // MODX applies this cap inside the processor too, but there it surfaces
        // as a generic upload error. Failing here names the setting to change.
        $modxMax = (int) $modx->getOption('upload_maxsize', null, 1048576);
        if ($modxMax > 0 && $size > $modxMax) {
            throw McpException::invalidParams(
                "Decoded file is {$size} bytes but the MODX system setting upload_maxsize allows {$modxMax}. "
                . 'Raise upload_maxsize in System Settings if this upload is intended.'
            );
        }

        $source  = $this->loadSource($modx, $sourceId);
        $relPath = $path . $filename;

        $existed = $this->fileExists($source, $relPath, $warnings);
        if ($existed === true && !$overwrite) {
            throw McpException::invalidParams(
                "A file already exists at '{$relPath}' in media source {$sourceId}. "
                . 'Pass overwrite: true to replace it. Nothing was written.'
            );
        }
        if ($existed === true && $overwrite) {
            // Through the remove processor, not unlink: the source policy for
            // removal applies and OnFileManagerFileRemove fires.
            $this->runProcessor($modx, 'Browser/File/Remove', [
                'file'   => $relPath,
                'source' => $sourceId,
            ]);
        }

        $this->uploadViaProcessor($modx, $sourceId, $path, $filename, $mime, $content, $size);

        // Read back through the source rather than trusting the processor:
        // uploadObjectsToContainer skips files in ways that predate its error
        // collection, and a success that wrote nothing is this extra's least
        // favourite failure mode.
        if ($this->fileExists($source, $relPath, $warnings) === false) {
            throw McpException::internal(
                "MODX reported success but '{$relPath}' does not exist in media source {$sourceId} afterwards. "
                . 'Check the MODX error log; the usual causes are a directory that does not exist '
                . 'or a plugin on OnFileManagerBeforeUpload rejecting the file.'
            );
        }

        $result = [
            'uploaded'    => true,
            'source'      => $sourceId,
            'path'        => $relPath,
            'url'         => $this->publicUrl($modx, $source, $relPath),
            'size'        => $size,
            'mime'        => $mime,
            'overwritten' => $existed === true,
        ];
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Normalise the target directory and refuse anything that walks.
     *
     * Validation rebuilds nothing here: a path that needs repair is a path
     * whose intent is unclear, and an upload is not the place to guess.
     */
    private function normalisePath(string $path): string
    {
        if (strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw McpException::invalidParams('The path may not contain backslashes or null bytes.');
        }

        $path = trim($path);
        $path = ltrim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path);
        if ($path !== '' && substr($path, -1) !== '/') {
            $path .= '/';
        }

        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw McpException::invalidParams('The path may not contain . or .. segments.');
            }
        }

        if ($path === '') {
            throw McpException::invalidParams(
                'A target directory is required, e.g. "images/uploads/". Uploading to the '
                . 'media source root must be spelled explicitly in modxmcp.upload_path_allowlist as "/".'
            );
        }

        return $path;
    }

    private function validateFilename(string $filename): string
    {
        $filename = trim($filename);

        if ($filename === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename)) {
            throw McpException::invalidParams(
                'Filenames are restricted to letters, digits, dot, dash and underscore, '
                . 'and may not start with a dot. Rename the file rather than relying on '
                . 'the server to repair it.'
            );
        }

        $segments = array_slice(explode('.', strtolower($filename)), 1);
        if ($segments === []) {
            throw McpException::invalidParams('The filename needs an extension, e.g. "hero.jpg".');
        }
        foreach ($segments as $segment) {
            if (in_array($segment, self::BLOCKED_SEGMENTS, true)) {
                throw McpException::invalidParams(
                    "The extension '.{$segment}' is blocked for uploads and cannot be enabled by "
                    . 'any setting. This applies to every dot-segment of the name, because a '
                    . 'server handler can match "file.php.jpg" on the inner segment.'
                );
            }
        }

        return $filename;
    }

    private function assertPathAllowed(modX $modx, string $path): void
    {
        $raw = trim((string) $modx->getOption('modxmcp.upload_path_allowlist', null, ''));
        if ($raw === '') {
            throw McpException::forbidden(
                'File uploads are disabled: modxmcp.upload_path_allowlist is empty. An '
                . 'administrator can enable them on the modxmcp Settings tab by listing the '
                . 'directories uploads may target, e.g. "images/uploads/*".'
            );
        }

        $entries = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '*') {
                return;
            }
            if ($entry === '/') {
                // Explicit opt-in to the source root; normalisePath() has
                // already guaranteed $path is non-empty and slash-terminated.
                return;
            }
            $entry = ltrim($entry, '/');
            if (substr($entry, -1) === '*') {
                if (strncmp($path, rtrim(substr($entry, 0, -1), '/') . '/', strlen(rtrim(substr($entry, 0, -1), '/')) + 1) === 0) {
                    return;
                }
                continue;
            }
            if (rtrim($entry, '/') . '/' === $path) {
                return;
            }
        }

        throw McpException::forbidden(
            "The directory '{$path}' is not covered by modxmcp.upload_path_allowlist. "
            . 'An administrator can extend the list on the modxmcp Settings tab.'
        );
    }

    private function assertSourceAllowed(modX $modx, int $sourceId): void
    {
        if ($this->sourceAllowedForUpload($modx, $sourceId)) {
            return;
        }

        throw McpException::forbidden(sprintf(
            'Media source %d is not covered by modxmcp.upload_source_allowlist (currently '
            . "'%s'). An administrator can extend the list on the modxmcp Settings tab. "
            . 'modxmcp_media_source_list shows which sources exist and which of them uploads '
            . 'are permitted to.',
            $sourceId,
            implode(', ', $this->uploadSourceAllowlist($modx))
        ));
    }

    private function assertExtensionAllowed(modX $modx, string $filename): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $raw = trim((string) $modx->getOption(
            'modxmcp.upload_extension_allowlist', null,
            'jpg, jpeg, png, gif, webp, avif, pdf'
        ));
        $allowed = preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (!in_array($ext, $allowed, true)) {
            throw McpException::forbidden(
                "The extension '.{$ext}' is not in modxmcp.upload_extension_allowlist "
                . "(currently '{$raw}'). An administrator can extend the list on the modxmcp "
                . 'Settings tab. The MODX upload_files setting applies on top of this list.'
            );
        }
    }

    private function decodeContent(modX $modx, string $encoded): string
    {
        // Tolerate a data: URI, which is how several clients hand files over.
        if (strncasecmp($encoded, 'data:', 5) === 0) {
            $comma   = strpos($encoded, ',');
            $encoded = $comma === false ? '' : substr($encoded, $comma + 1);
        }
        $encoded = preg_replace('/\s+/', '', $encoded);

        $maxBytes = (int) $modx->getOption('modxmcp.upload_max_bytes', null, 10485760);
        if ($maxBytes > 0 && strlen($encoded) > (int) ceil($maxBytes * 4 / 3) + 4) {
            throw McpException::invalidParams(
                "The payload exceeds modxmcp.upload_max_bytes ({$maxBytes} bytes decoded). "
                . 'An administrator can raise it on the modxmcp Settings tab.'
            );
        }

        $content = base64_decode($encoded, true);
        if ($content === false || $content === '') {
            throw McpException::invalidParams(
                'content_base64 did not decode as base64. Send the raw file content encoded '
                . 'with standard base64; a data: URI prefix is tolerated.'
            );
        }
        if ($maxBytes > 0 && strlen($content) > $maxBytes) {
            throw McpException::invalidParams(
                'The decoded file is ' . strlen($content) . " bytes but modxmcp.upload_max_bytes "
                . "allows {$maxBytes}. An administrator can raise it on the modxmcp Settings tab."
            );
        }

        return $content;
    }

    /**
     * Detect the real content type, block embedded PHP, and warn on a
     * type/extension mismatch instead of failing: the mismatch table is
     * deliberately partial and a warning cannot strand a legitimate file.
     *
     * @param string[] $warnings
     */
    private function sniff(string $content, string $filename, array &$warnings): string
    {
        if (stripos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
            throw McpException::invalidParams(
                'The file content contains a PHP open tag. Uploads that embed PHP are '
                . 'refused outright, whatever their extension.'
            );
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $content);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }

        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $expected = self::EXPECTED_MIME[$ext] ?? null;
        if ($expected !== null && $mime !== $expected) {
            $warnings[] = "The content sniffs as '{$mime}' but the extension '.{$ext}' suggests "
                . "'{$expected}'. The file was stored as sent; rename it if the extension is wrong.";
        }

        return $mime;
    }

    private function loadSource(modX $modx, int $sourceId): modMediaSource
    {
        $source = modMediaSource::getDefaultSource($modx, $sourceId, false);
        if (!$source || (int) $source->get('id') !== $sourceId) {
            throw McpException::invalidParams("Media source {$sourceId} does not exist.");
        }
        $source->initialize();

        return $source;
    }

    /**
     * Existence through the source's own filesystem, so it answers for S3 and
     * friends too. Returns null when the source cannot say, which downgrades
     * the overwrite guard to a warning rather than a wrong answer.
     *
     * @param string[] $warnings
     */
    private function fileExists(modMediaSource $source, string $relPath, array &$warnings): ?bool
    {
        try {
            return (bool) $source->getFilesystem()->fileExists($relPath);
        } catch (\Throwable $e) {
            $warnings[] = "Could not check whether '{$relPath}' already exists: " . $e->getMessage();
            return null;
        }
    }

    private function uploadViaProcessor(
        modX $modx,
        int $sourceId,
        string $path,
        string $filename,
        string $mime,
        string $content,
        int $size
    ): void {
        $tmpDir = rtrim($modx->getCachePath(), '/') . '/modxmcp/uploads/';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw McpException::internal('Could not create the temp directory under the MODX cache path.');
        }
        $tmpFile = @tempnam($tmpDir, 'up');
        if ($tmpFile === false || @file_put_contents($tmpFile, $content) !== $size) {
            throw McpException::internal('Could not stage the upload in the MODX cache path.');
        }

        $previousFiles = $_FILES;
        $_FILES        = [
            'file' => [
                'name'     => $filename,
                'type'     => $mime,
                'tmp_name' => $tmpFile,
                'error'    => 0,
                'size'     => $size,
            ],
        ];

        try {
            $this->runProcessor($modx, 'Browser/File/Upload', [
                'path'   => $path,
                'source' => $sourceId,
            ]);
        } finally {
            $_FILES = $previousFiles;
            @unlink($tmpFile);
        }
    }

    private function publicUrl(modX $modx, modMediaSource $source, string $relPath): string
    {
        // Through the shared helper rather than beside it: media_source_list
        // reports a source's base URL the same way, and two copies of this
        // would be two chances to disagree about it.
        return $this->absoluteSourceUrl($modx, (string) $source->getObjectUrl($relPath));
    }
}
