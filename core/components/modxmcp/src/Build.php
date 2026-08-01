<?php

namespace MODXMCP;

/**
 * Which build of this version is actually running.
 *
 * The version number alone cannot answer that, and assuming it could has cost
 * real time. modxmcp reaches a site by two routes that both preserve the
 * version string while changing the code:
 *
 * 1. dev/deploy.sh rsyncs the working tree over whatever is installed. The
 *    files change, the transport package MODX believes is installed does not.
 *
 * 2. A rebuilt transport package keeps its signature. MODX keys packages by
 *    signature, and modTransportPackage::getTransport() decides whether to
 *    unzip by asking whether the extracted directory already exists:
 *
 *        $targetDir = basename($sourceFile, '.transport.zip');
 *        $state = is_dir($packageDir . $targetDir)
 *            ? $this->get('state')
 *            : xPDOTransport::STATE_PACKED;
 *
 *    So installing a freshly built modxmcp-1.0.0-pl over a site that already
 *    has core/packages/modxmcp-1.0.0-pl/ extracted from a previous build
 *    installs the previous build. No error, no warning, and the version
 *    afterwards reads exactly as expected. That is how a site was reverted to
 *    beta2 during the 1.0.0 release while reporting 1.0.0.
 *
 * Bumping the version on every rebuild would paper over this, at the cost of
 * burning a version number per iteration and still saying nothing about an
 * rsynced tree. Hashing the code says what is true regardless of how it
 * arrived.
 *
 * The hash covers file paths as well as contents, so adding or removing a file
 * changes it even if no existing file is edited. It deliberately does not cover
 * assets/: the Manager UI is not what a caller is talking to, and including it
 * would make the fingerprint change for reasons invisible over MCP.
 */
final class Build
{
    /** Enough to distinguish builds; the full 32 would only be noise. */
    private const LENGTH = 12;

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * Version and build fingerprint of the running code.
     *
     * Computed rather than cached across requests, on purpose. A stored value
     * survives an rsync and would then assert the wrong build with total
     * confidence, which is worse than not reporting one. Within a request it is
     * memoised, since nothing can change underneath it.
     *
     * @return array{version:string,build:string,files:int}
     */
    public static function describe(): array
    {
        if (self::$cache !== null) {
            /** @var array{version:string,build:string,files:int} */
            return self::$cache;
        }

        $files = self::sources();

        $hash = hash_init('md5');
        hash_update($hash, Server::VERSION);
        foreach ($files as $relative => $absolute) {
            hash_update($hash, $relative);
            hash_update_file($hash, $absolute);
        }

        return self::$cache = [
            'version' => Server::VERSION,
            'build'   => substr(hash_final($hash), 0, self::LENGTH),
            'files'   => count($files),
        ];
    }

    /**
     * Every PHP source file that makes up the server, keyed by relative path.
     *
     * Sorted, because directory iteration order is filesystem-dependent and an
     * unsorted hash would differ between two identical trees.
     *
     * @return array<string,string>
     */
    private static function sources(): array
    {
        $root = __DIR__;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $files[substr($path, strlen($root) + 1)] = $path;
        }

        ksort($files);

        return $files;
    }
}
