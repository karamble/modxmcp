<?php

namespace MODXMCP\Knowledge;

use MODX\Revolution\Transport\modTransportPackage;
use MODX\Revolution\modNamespace;
use MODX\Revolution\modX;

/**
 * Which extras are installed, and at what version.
 *
 * Presence is decided by namespace rather than by files on disk, for the reason
 * AbstractAdapter gives: files survive an uninstall and would report an extra
 * that is no longer wired up.
 *
 * Versions matter because advice changes between major releases of an extra, and
 * an advisory that cannot say which version it applies to is guesswork. One
 * query for all of them, memoised, because several probes ask.
 */
final class ExtraPresence
{
    private modX $modx;
    /** @var array<string,string|null>|null */
    private ?array $packages = null;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public function has(string $namespace): bool
    {
        return (bool) $this->modx->getObject(modNamespace::class, ['name' => $namespace]);
    }

    public function version(string $namespace): ?string
    {
        $this->load();

        foreach ($this->packages as $name => $version) {
            if (strcasecmp($name, $namespace) === 0) {
                return $version;
            }
        }

        return null;
    }

    /** @return array<string,string|null> package name => version */
    public function packages(): array
    {
        $this->load();

        return $this->packages;
    }

    private function load(): void
    {
        if ($this->packages !== null) {
            return;
        }

        $this->packages = [];
        foreach ($this->modx->getIterator(modTransportPackage::class) as $package) {
            if ($package->get('installed') === null) {
                continue;
            }
            $name    = (string) $package->get('package_name');
            $version = implode('.', [
                (int) $package->get('version_major'),
                (int) $package->get('version_minor'),
                (int) $package->get('version_patch'),
            ]);
            $release = trim((string) $package->get('release'));
            $full    = $release === '' ? $version : $version . '-' . $release;

            // MODX keeps every version ever installed; the newest wins.
            if (!isset($this->packages[$name]) || version_compare($full, (string) $this->packages[$name], '>')) {
                $this->packages[$name] = $full;
            }
        }
    }
}
