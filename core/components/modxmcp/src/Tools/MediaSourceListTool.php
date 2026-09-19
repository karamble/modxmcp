<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * The media sources a file can be written to, and where each one points.
 *
 * modxmcp_file_upload takes a `source` id and documents the default as 1, and
 * nothing in the surface could say what source 1 was, what else existed, or
 * where any of them was rooted. That gap is worse than it sounds, because
 * modxmcp.upload_path_allowlist is checked against the path *within* a source:
 * one entry of "images/*" authorises <webroot>/images/ on the Filesystem source
 * MODX ships, whose base path is empty and therefore the webroot, and
 * <webroot>/assets/images/products/images/ on a source configured under
 * assets/. The README says so, and then had to send the reader to the Manager
 * to find out which case they were in.
 *
 * Why a curated tool rather than an allowlistable class: generic access to a
 * media source is permanently blocked, because the credentials of a remote
 * source live inside the serialised `properties` column and field-name
 * redaction cannot see into a column. That block is right and stays. What makes
 * a curated read safe is that it names the columns it returns, and `properties`
 * is not among them.
 *
 * Two access checks, doing different jobs:
 *
 *   source_view, asked of MODX directly. It is the permission MODX's own
 *   Processors\Source\GetList declares, which is to say the permission the
 *   Manager requires to list sources at all -- and the Manager screen it gates
 *   is the one that shows base paths. Asking it here turns "a token can never
 *   do more than that user can do in the Manager" from a claim in the README
 *   into a check. This is the first read tool with a Manager-permission gate,
 *   which is worth naming the processor for so it does not look arbitrary.
 *
 *   checkPolicy('load'), which costs nothing because modMediaSource descends
 *   from modAccessibleSimpleObject: modAccessibleObject::loadCollection()
 *   applies it per row, so a source this user may not load is simply absent.
 *   That governs using a source, not seeing its configuration, which is why it
 *   is not a substitute for the permission above.
 */
final class MediaSourceListTool extends AbstractTool
{
    use MediaSourceSupport;

    public function name(): string
    {
        return 'modxmcp_media_source_list';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List media sources',
            'description' => 'The media sources this site defines, with the id '
                . 'modxmcp_file_upload expects, where each one is rooted, and whether the upload '
                . 'source allowlist currently covers it. Call this before modxmcp_file_upload if '
                . 'you do not already know the source id: modxmcp.upload_path_allowlist is '
                . 'checked against the path within the source, not against assets/, so one '
                . 'allowlist entry means different directories on different sources. base_path '
                . 'is the root as the source itself reports it, which for a remote source is a '
                . 'URL rather than a filesystem path. Remote sources are not contacted unless '
                . 'include_remote is set, because reaching one can mean a network round trip. '
                . 'Credentials are never returned; a remote source\'s keys and passwords are '
                . 'not part of this result and cannot be read through any tool.',
            'inputSchema' => Schema::object([
                'search'         => Schema::string(
                    'Match against the source name and description.'),
                'include_remote' => Schema::boolean(
                    'Ask non-filesystem sources where they are rooted too. Off by default: '
                    . 'initialising an S3 source checks the bucket over the network, so a '
                    . 'listing would block on an unreachable endpoint. Their rows say '
                    . 'paths="not_attempted" until this is set.', false),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        // MODX's own source listing is gated on this, and it is the screen that
        // shows base paths. Refused before anything is read, so the refusal
        // cannot be mistaken for an empty site.
        if (!$modx->hasPermission('source_view')) {
            throw McpException::forbidden(
                'The MODX user this token acts as lacks the source_view permission, which is '
                . 'what the Manager requires to list media sources. Grant it through the user '
                . "group's access policy, or use a token bound to a user that has it. Nothing "
                . 'was read.');
        }

        $search        = $this->arg($arguments, 'search');
        $includeRemote = !empty($arguments['include_remote']);

        $criteria = null;
        if ($search !== null) {
            $like = '%' . (string) $search . '%';
            // 'OR:' is an xPDO criteria construct, code-owned here. It is
            // exactly what ObjectSupport::buildCriteria() refuses to accept
            // from a caller, which is why building it internally is fine.
            $criteria = ['name:LIKE' => $like, 'OR:description:LIKE' => $like];
        }

        $sources = [];
        foreach ($modx->getCollection($this->mediaSourceClass(), $criteria) as $source) {
            $row = $this->pick($source->toArray(), $this->sourceFields());

            $row['id']        = (int) ($row['id'] ?? 0);
            $row['name']      = (string) ($row['name'] ?? '');
            $row['is_stream'] = !empty($row['is_stream']);

            // A name match against MODX's own reserved list, mirroring the
            // isProtected flag in the Manager's grid. So a renamed Filesystem
            // source reads false, and a user source called "Filesystem" reads
            // true. MODX decides this the same way.
            $row['is_core'] = (bool) $source->isCoreSource($row['name']);

            $row += $this->resolveBases($modx, $source, $includeRemote);

            // Named after the setting it checks, not after the outcome. Being
            // allowlisted is necessary and not sufficient: uploads_enabled
            // below, the extension allowlist, upload_maxsize and the source's
            // own policy all still apply.
            $row['upload_allowlisted'] = $this->sourceAllowedForUpload($modx, $row['id']);

            if ($note = $this->noteFor($row)) {
                $row['note'] = $note;
            }

            $sources[] = $row;
        }

        usort($sources, fn(array $a, array $b) => $a['id'] <=> $b['id']);

        // getCollection applies checkPolicy('load') per row, so a source this
        // user may not load never arrives. Counting separately is what lets the
        // response distinguish "this site has one source" from "you may see one
        // of six" -- the same distinction schema_list's note exists to keep.
        $total  = (int) $modx->getCount($this->mediaSourceClass(), $criteria);
        $shown  = count($sources);
        $hidden = max(0, $total - $shown);

        $out = [
            'total'                 => $shown,
            'uploads_enabled'       => $this->uploadsEnabled($modx),
            'default_media_source'  => (int) $modx->getOption('default_media_source', null, 1),
            'sources'               => $sources,
        ];

        if ($hidden > 0) {
            $out['hidden'] = $hidden;
            $out['note']   = sprintf(
                '%d further source(s) exist and are not shown. Either the MODX user this token '
                . 'acts as has no load access to them, or their class_key names a class that is '
                . 'no longer installed, in which case MODX cannot instantiate the row at all.',
                $hidden
            );
        }

        return $out;
    }

    /**
     * Whether an upload could succeed at all, regardless of source.
     *
     * On a default install every upload is refused because
     * modxmcp.upload_path_allowlist ships empty, and a row saying
     * upload_allowlisted=true beside that would be technically true and
     * practically misleading. Stating it once on the envelope is cheaper than
     * qualifying every row.
     */
    private function uploadsEnabled(modX $modx): bool
    {
        return trim((string) $modx->getOption('modxmcp.upload_path_allowlist', null, '')) !== '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function noteFor(array $row): ?string
    {
        switch ($row['paths'] ?? '') {
            case 'not_attempted':
                return 'Not a filesystem source, so it was not contacted. Pass '
                    . 'include_remote=true to have it report where it is rooted, accepting that '
                    . 'this may mean a network round trip.';

            case 'backend_unavailable':
                return 'This source reported that it could not start up. Its backend is '
                    . 'unreachable or its credentials are wrong. MODX has logged the reason; '
                    . 'uploading to it will fail.';

            case 'base_path_missing':
                return sprintf(
                    'This source is configured with a base path of "%s", and no such directory '
                    . 'exists on this server, so it resolves to nothing. Create it or correct '
                    . 'the source; uploading to it will fail.',
                    (string) ($row['base_path_configured'] ?? '')
                );

            case 'error':
                return 'This source threw while reporting where it is rooted. The details are in '
                    . 'the MODX error log. Uploading to it will probably fail.';

            default:
                return null;
        }
    }
}
