<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Discovery\PackageScanner;
use MODXMCP\Protocol\McpException;

/**
 * Resolving and explaining a resource's class_key.
 *
 * MODX stores the resource type in the row itself and instantiates the resource
 * through it, so class_key is not decoration: it decides which class renders the
 * page, which Manager form edits it, and which extras recognise it at all.
 *
 * Resource/Create already defaults this correctly. It resolves class_key to
 * modDocument in both getInstance() and initialize(), and only falls back to the
 * abstract modResource when a caller explicitly asks for it. modxmcp asked for
 * it, on every single create, which is why resources created here did not look
 * like resources created anywhere else on the site.
 *
 * The wider point is that the base class is not merely unusual, it is wrong:
 * the Manager never creates one, Collections and Articles key their behaviour on
 * class_key, and a resource typed as modResource is invisible to them.
 */
trait ResourceClassSupport
{
    /**
     * The class_key MODX itself creates.
     *
     * A method rather than a trait constant on purpose: constants in traits are
     * PHP 8.2, and this package supports 8.1.
     */
    protected function defaultClassKey(): string
    {
        return \MODX\Revolution\modDocument::class;
    }

    /**
     * Resolve a caller-supplied class_key to a validated fully-qualified name.
     *
     * Validation is not politeness here. MODX instantiates a resource by its
     * class_key, so an unloadable value produces a row that errors in the tree
     * and cannot be repaired through this extra at all: ClassGuard blocks
     * generic writes to resource classes, which leaves SQL as the only remedy.
     * A typo must not be able to create something only a DBA can remove.
     *
     * @param mixed       $requested Raw argument. Null, '' or absent means "no opinion".
     * @param string|null $current   Stored class_key when updating; null when creating.
     * @throws McpException when the value does not name a resource type on this site.
     */
    protected function resolveClassKey(modX $modx, $requested, ?string $current = null): string
    {
        if ($requested === null || $requested === '') {
            return $current ?? $this->defaultClassKey();
        }

        $name = ltrim(trim((string) $requested), '\\');
        if ($name === '') {
            return $current ?? $this->defaultClassKey();
        }

        // Registering every installed package is what makes an extra's model
        // classes resolvable at all; SeoRedirectTool calls this for the same
        // side effect. Do it before either lookup below.
        $known = (new PackageScanner($modx))->classes();

        if (strpos($name, '\\') === false) {
            $name = $this->expandShortClassName($name, $known);
        }

        if ($this->isResourceClass($modx, $name, $known)) {
            return $name;
        }

        throw McpException::invalidParams(sprintf(
            "'%s' is not a resource type on this site. class_key must name a class that "
            . 'extends %s: %s for a normal page, %s, %s, %s, or a class an installed extra '
            . 'provides such as Collections\\Model\\CollectionContainer. Use '
            . 'modxmcp_schema_list to see what this site defines. Nothing was written.',
            $requested,
            modResource::class,
            $this->defaultClassKey(),
            \MODX\Revolution\modWebLink::class,
            \MODX\Revolution\modSymLink::class,
            \MODX\Revolution\modStaticResource::class
        ));
    }

    /**
     * Accept the short spellings the MODX documentation still uses.
     *
     * "modDocument" and "modWebLink" are what a model will produce from its
     * training data, and rejecting them for want of a namespace would be a
     * pointless papercut.
     *
     * @param array<string,mixed> $known
     */
    private function expandShortClassName(string $name, array $known): string
    {
        $core = 'MODX\\Revolution\\' . $name;
        if (class_exists($core, true)) {
            return $core;
        }

        $matches = [];
        foreach (array_keys($known) as $candidate) {
            $short = substr((string) strrchr('\\' . $candidate, '\\'), 1);
            if (strcasecmp($short, $name) === 0) {
                $matches[] = $candidate;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            sort($matches);
            throw McpException::invalidParams(sprintf(
                "'%s' is ambiguous on this site: %s. Pass the full class name. Nothing was written.",
                $name,
                implode(', ', $matches)
            ));
        }

        return $name;
    }

    /**
     * Is this a resource type here?
     *
     * Three proofs, because no single one covers every case. Autoloading covers
     * the core types. The scanner covers extras whose model is registered with
     * addPackage rather than a PSR-4 autoloader, which is how Collections and
     * Articles ship. And a site that already holds resources of the type has
     * settled the question whatever the loader thinks.
     *
     * @param array<string,mixed> $known
     */
    private function isResourceClass(modX $modx, string $class, array $known): bool
    {
        if (class_exists($class, true) && is_a($class, modResource::class, true)) {
            return true;
        }

        if (isset($known[$class])) {
            return true;
        }

        return $modx->getCount(modResource::class, ['class_key' => $class]) > 0;
    }

    /**
     * What this class_key implies, for things a caller cannot read off a schema.
     *
     * @return string[]
     */
    protected function classKeyWarnings(modX $modx, string $classKey, bool $changed): array
    {
        $warnings = [];

        if ($classKey === modResource::class) {
            $warnings[] = sprintf(
                '%s is the abstract base resource type. The MODX Manager never creates one, '
                . 'and extras that key on class_key (Collections, Articles, type-specific '
                . 'rendering) will not recognise this resource. Use %s for a normal page.',
                modResource::class,
                $this->defaultClassKey()
            );
        }

        if (stripos($classKey, 'Collection') !== false) {
            $warnings[] = 'This class_key makes the resource a Collections container. '
                . 'Collections keeps its grid configuration outside the resource row, so the '
                . 'container can show an empty grid until a view is configured for it in the '
                . 'Manager.';
        }

        if (in_array($classKey, [
            \MODX\Revolution\modWebLink::class,
            \MODX\Revolution\modSymLink::class,
            \MODX\Revolution\modStaticResource::class,
        ], true)) {
            $warnings[] = 'For this resource type the content field holds the target, not page '
                . 'body HTML: a URL for a weblink, a resource id for a symlink, a file path '
                . 'for a static resource.';
        }

        if ($changed) {
            $warnings[] = 'class_key changed, which changes how MODX renders and edits this '
                . 'resource. No type-specific data is migrated, and any configuration the '
                . 'previous type\'s extra kept elsewhere is left behind.';
        }

        return $warnings;
    }

    /**
     * Flag a resource still carrying the type older modxmcp versions wrote.
     *
     * Deliberately a warning and never an automatic rewrite. Normalising it
     * silently would be a write the caller did not ask for, on a value some site
     * may have set deliberately, and it would erase the evidence of the bug
     * while the operator is trying to work out what happened.
     *
     * @return string[]
     */
    protected function legacyClassKeyWarning(string $storedClassKey): array
    {
        if ($storedClassKey !== modResource::class) {
            return [];
        }

        return [sprintf(
            'This resource has class_key %s. MODX creates pages as %s; resources created by '
            . 'earlier versions of modxmcp carry the wrong type, and code that keys on '
            . 'class_key (Collections, Articles, type-specific rendering) will not recognise '
            . 'them. Pass class_key="%s" to modxmcp_resource_update to normalise it.',
            modResource::class,
            $this->defaultClassKey(),
            $this->defaultClassKey()
        )];
    }
}
