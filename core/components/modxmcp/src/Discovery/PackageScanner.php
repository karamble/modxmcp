<?php

namespace MODXMCP\Discovery;

use MODX\Revolution\modNamespace;
use MODX\Revolution\modX;

/**
 * Finds every xPDO model package installed on the site and registers it.
 *
 * Extras are far more introspectable than they look. Each one that ships a model
 * also ships a metadata file naming its namespace, its namespace prefix and
 * every class it defines, and each class has a map giving the table, the fields
 * and their types. That is enough to describe any extra's data without a line of
 * per-extra code, including extras released long after this one.
 *
 * Two layouts coexist on a single MODX 3 install and both must be handled:
 * xPDO 3 PSR-4 packages under src/Model/ (Collections, SeoSuite, GoodNews) and
 * legacy 2.x packages under model/<name>/ (MIGX, FormIt, Login).
 */
final class PackageScanner
{
    private modX $modx;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * Every discovered class, keyed by class name.
     *
     * @return array<string,array{class:string,namespace:string,extra:string,layout:string}>
     */
    public function classes(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $cacheKey = 'modxmcp/discovery';
        $cached   = $this->modx->cacheManager->get($cacheKey);
        if (is_array($cached) && isset($cached['classes'])) {
            $this->register($cached['packages']);
            return $this->cache = $cached['classes'];
        }

        $classes  = [];
        $packages = [];

        foreach ($this->modx->getCollection(modNamespace::class) as $namespace) {
            $name = (string) $namespace->get('name');
            $path = $namespace->getCorePath();
            if ($path === '' || !is_dir($path)) {
                continue;
            }

            foreach ($this->metadataFiles($path) as $metadataFile) {
                $package = $this->readMetadata($metadataFile);
                if ($package === null) {
                    continue;
                }

                $package['extra'] = $name;
                $packages[]       = $package;

                foreach ($package['classes'] as $class) {
                    $classes[$class] = [
                        'class'     => $class,
                        'namespace' => $package['namespace'],
                        'extra'     => $name,
                        'layout'    => $package['layout'],
                    ];
                }
            }
        }

        ksort($classes);

        // Short TTL rather than forever. Installing or removing an extra changes
        // this set, and a stale forever-cache would keep a newly installed
        // extra invisible with no obvious cause. The scan is cheap enough that
        // expiring beats depending on an invalidation hook firing.
        //
        // xPDOCacheManager::set() takes its value by reference, so this must be
        // a variable rather than a literal.
        $payload = ['classes' => $classes, 'packages' => $packages];
        $this->modx->cacheManager->set(
            $cacheKey,
            $payload,
            (int) $this->modx->getOption('modxmcp.discovery_cache_seconds', null, 300)
        );

        $this->register($packages);

        return $this->cache = $classes;
    }

    /**
     * Candidate metadata files for one extra, covering both layouts.
     *
     * @return string[]
     */
    private function metadataFiles(string $componentPath): array
    {
        $patterns = [
            // xPDO 3, PSR-4
            $componentPath . 'src/Model/metadata.*.php',
            $componentPath . 'src/*/Model/metadata.*.php',
            // legacy 2.x
            $componentPath . 'model/*/metadata.*.php',
            $componentPath . 'model/metadata.*.php',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $found[$file] = $file;
            }
        }

        return array_values($found);
    }

    /**
     * Read one metadata file.
     *
     * The path handed to addPackage() is derived from the metadata rather than
     * assumed: xPDO resolves a class by stripping the namespace prefix and
     * treating the remainder as a directory path, so the package root is the
     * metadata's directory minus that remainder. Guessing it instead is exactly
     * how a package silently fails to load.
     *
     * @return array{namespace:string,prefix:string,path:string,classes:string[],layout:string}|null
     */
    private function readMetadata(string $file): ?array
    {
        $xpdo_meta_map = null;
        try {
            // These files only assign $xpdo_meta_map; they are part of the
            // installed codebase, not caller input.
            include $file;
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "modxmcp: unreadable model metadata {$file}: " . $e->getMessage());
            return null;
        }

        if (!is_array($xpdo_meta_map) || $xpdo_meta_map === []) {
            return null;
        }

        // Two formats are in the wild and both are common on one install.
        //
        //   A (xPDO 3): a wrapper with version/namespace/namespacePrefix/class_map
        //   B (older):  the class map itself, keyed by ancestor class, with no
        //               wrapper and therefore no declared namespace
        //
        // Format B covers legacy unnamespaced packages (MIGX) and namespaced
        // ones that simply predate the wrapper (FormIt), so treating "no
        // class_map" as "not a model" silently loses most installed extras.
        $isWrapped = isset($xpdo_meta_map['class_map']);
        $classMap  = $isWrapped ? (array) $xpdo_meta_map['class_map'] : $xpdo_meta_map;

        $classes = [];
        foreach ($classMap as $ancestor => $descendants) {
            if (!is_array($descendants)) {
                continue;
            }
            foreach ($descendants as $class) {
                if (is_string($class) && $class !== '') {
                    $classes[] = $class;
                }
            }
        }
        if ($classes === []) {
            return null;
        }

        $directory = rtrim(dirname($file), '/\\') . '/';

        $namespace = $isWrapped ? (string) ($xpdo_meta_map['namespace'] ?? '') : '';
        if ($namespace === '') {
            // Derive it: a namespaced class carries it, and an unnamespaced
            // package is addressed by its directory name (the classic 2.x form).
            $sample = $classes[0];
            $namespace = strpos($sample, '\\') !== false
                ? substr($sample, 0, (int) strrpos($sample, '\\'))
                : basename(rtrim($directory, '/'));
        }

        $prefix = trim((string) ($xpdo_meta_map['namespacePrefix'] ?? ''), '\\');

        // The package root is the metadata directory minus however much of the
        // namespace is expressed as directories. Matching the namespace against
        // the actual path is more reliable than trusting a declared prefix,
        // which format B does not have at all.
        $segments = explode('\\', str_replace('/', '\\', $namespace));
        $relative = '';
        $path     = $directory;
        for ($take = count($segments); $take > 0; $take--) {
            $candidate = implode('/', array_slice($segments, -$take));
            if (substr($directory, -strlen($candidate) - 1) === $candidate . '/') {
                $relative = implode('\\', array_slice($segments, -$take));
                $path     = substr($directory, 0, -strlen($candidate) - 1);
                break;
            }
        }

        if ($prefix === '' && $relative !== '' && $relative !== $namespace) {
            $prefix = trim(substr($namespace, 0, strlen($namespace) - strlen($relative)), '\\');
        }

        return [
            'namespace' => $namespace,
            'prefix'    => $prefix,
            'path'      => $path,
            'classes'   => $classes,
            'layout'    => strpos($file, '/src/') !== false ? 'psr4' : 'legacy',
        ];
    }

    /**
     * @param array<int,array{namespace:string,prefix:string,path:string}> $packages
     */
    private function register(array $packages): void
    {
        foreach ($packages as $package) {
            $this->modx->addPackage(
                $package['namespace'],
                $package['path'],
                null,
                $package['prefix'] !== '' ? $package['prefix'] . '\\' : ''
            );
        }
    }

    /** Drop the discovery cache, e.g. after a package is installed or removed. */
    public function forget(): void
    {
        $this->cache = null;
        $this->modx->cacheManager->delete('modxmcp/discovery');
    }
}
