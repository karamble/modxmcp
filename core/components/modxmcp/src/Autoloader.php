<?php

namespace MODXMCP;

/**
 * PSR-4 autoloader for the MODXMCP\ namespace.
 *
 * modxmcp ships no vendor directory and must never add one: MODX's autoload
 * space is already shared by every installed extra, and colliding copies of
 * common packages are a known way to fatal a site after a core upgrade. Forty
 * lines here replaces that entire class of risk.
 */
final class Autoloader
{
    private static bool $registered = false;

    /**
     * @param string $baseDir Directory holding the MODXMCP\ root, i.e. .../components/modxmcp/src/
     */
    public static function register(string $baseDir): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $baseDir = rtrim($baseDir, '/\\') . '/';

        spl_autoload_register(static function (string $class) use ($baseDir): void {
            $prefix = 'MODXMCP\\';
            $len    = strlen($prefix);
            if (strncmp($class, $prefix, $len) !== 0) {
                return;
            }

            $relative = substr($class, $len);
            $path     = $baseDir . str_replace('\\', '/', $relative) . '.php';

            // realpath + prefix check: never let a crafted class name escape the
            // base directory, even though class names reaching here are internal.
            $realBase = realpath($baseDir);
            $real     = realpath($path);
            if ($real !== false && $realBase !== false
                && strncmp($real, $realBase, strlen($realBase)) === 0) {
                require_once $real;
            }
        });
    }
}
