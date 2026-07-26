<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Discovery\PackageScanner;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Manage SeoSuite redirects.
 *
 * SeoSuite creates a redirect when an alias changes in the Manager, but not for
 * a change made any other way, so a programmatic rename silently leaves every
 * existing link to the old URL returning 404. modxmcp_resource_update warns
 * about that; this is what acts on the warning.
 */
final class SeoRedirectTool extends AbstractTool
{
    private const CLASS_NAME = 'Sterc\\SeoSuite\\Model\\SeoSuiteRedirect';

    public function requiredScope(): string
    {
        return 'write:content';
    }

    public function name(): string
    {
        return 'modxmcp_seo_redirect';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List or create SeoSuite redirects',
            'description' => 'List existing redirects, or create one. Use this after changing a '
                . 'resource alias: SeoSuite only creates redirects for changes made in the '
                . 'Manager, so a rename through the API leaves old links returning 404 until a '
                . 'redirect exists. Pass old_url and new_url to create one; omit them to list.',
            'inputSchema' => Schema::object([
                'old_url'       => Schema::string('Path that should redirect, without the domain, e.g. "old-page.html".'),
                'new_url'       => Schema::string('Target path, or a resource id to resolve.'),
                'resource_id'   => Schema::integer('Resource the redirect points at. Its current URI is used when new_url is omitted.'),
                'redirect_type' => Schema::enum('HTTP status.', ['301', '302'], '301'),
                'context'       => Schema::string('Context key.', 'web'),
                'search'        => Schema::string('When listing, match against old_url and new_url.'),
                'limit'         => Schema::integer('When listing, maximum rows.', 25),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        // Loads SeoSuite's model the same way any other extra's is discovered.
        (new PackageScanner($modx))->classes();

        if (!class_exists(self::CLASS_NAME)) {
            throw McpException::invalidParams(
                'SeoSuite does not appear to be installed on this site.');
        }

        $oldUrl = $this->arg($arguments, 'old_url');
        if ($oldUrl === null) {
            return $this->listRedirects($modx, $arguments);
        }

        return $this->createRedirect($modx, $arguments, (string) $oldUrl);
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function listRedirects(modX $modx, array $arguments): array
    {
        $limit = max(1, min(200, (int) $this->arg($arguments, 'limit', 25)));

        $query = $modx->newQuery(self::CLASS_NAME);
        if (($search = $this->arg($arguments, 'search')) !== null) {
            $query->where(['old_url:LIKE' => '%' . $search . '%', 'OR:new_url:LIKE' => '%' . $search . '%']);
        }
        $total = $modx->getCount(self::CLASS_NAME, $query);
        $query->limit($limit);

        $rows = [];
        foreach ($modx->getCollection(self::CLASS_NAME, $query) as $redirect) {
            $rows[] = $this->pick($redirect->toArray(), [
                'id', 'context_key', 'resource_id', 'old_url', 'new_url', 'redirect_type', 'active', 'visits',
            ]);
        }

        return ['total' => $total, 'returned' => count($rows), 'redirects' => $rows];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws McpException
     */
    private function createRedirect(modX $modx, array $arguments, string $oldUrl): array
    {
        $context    = (string) $this->arg($arguments, 'context', 'web');
        $resourceId = (int) $this->arg($arguments, 'resource_id', 0);
        $newUrl     = $this->arg($arguments, 'new_url');

        // Resolving from the resource keeps the redirect correct if the target's
        // URI was itself computed rather than supplied.
        if ($newUrl === null && $resourceId > 0) {
            /** @var modResource|null $resource */
            $resource = $modx->getObject(modResource::class, $resourceId);
            if (!$resource) {
                throw McpException::invalidParams("No resource with id {$resourceId}");
            }
            $newUrl = $resource->get('uri');
        }

        if ($newUrl === null || $newUrl === '') {
            throw McpException::invalidParams('Provide new_url, or resource_id to resolve it from.');
        }

        $oldUrl = ltrim($oldUrl, '/');
        $newUrl = ltrim((string) $newUrl, '/');

        if ($oldUrl === $newUrl) {
            throw McpException::invalidParams(
                'old_url and new_url are identical, which would create a redirect loop.');
        }

        $existing = $modx->getObject(self::CLASS_NAME, ['old_url' => $oldUrl, 'context_key' => $context]);
        if ($existing) {
            throw McpException::invalidParams(
                "A redirect for '{$oldUrl}' already exists (id " . $existing->get('id') . ').');
        }

        $redirect = $modx->newObject(self::CLASS_NAME);
        $redirect->fromArray([
            'context_key'   => $context,
            'resource_id'   => $resourceId,
            'old_url'       => $oldUrl,
            'new_url'       => $newUrl,
            'redirect_type' => (string) $this->arg($arguments, 'redirect_type', '301'),
            'active'        => 1,
            'visits'        => 0,
            'editedon'      => date('Y-m-d H:i:s'),
        ]);

        if (!$redirect->save()) {
            throw McpException::internal('Could not save the redirect');
        }

        return [
            'created'       => true,
            'id'            => (int) $redirect->get('id'),
            'old_url'       => $oldUrl,
            'new_url'       => $newUrl,
            'redirect_type' => $redirect->get('redirect_type'),
            'context_key'   => $context,
        ];
    }
}
