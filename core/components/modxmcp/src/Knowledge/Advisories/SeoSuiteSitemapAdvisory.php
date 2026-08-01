<?php

namespace MODXMCP\Knowledge\Advisories;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Discovery\PackageScanner;
use MODXMCP\Knowledge\Advisory;
use MODXMCP\Knowledge\AdvisoryInterface;
use MODXMCP\Knowledge\ExtraPresence;

/**
 * Resources with no SeoSuite row are silently missing from sitemap.xml.
 *
 * An audit rather than a presence check: it counts the published resources that
 * have no companion row. SeoSuite's sitemap snippet inner joins its own table,
 * so a resource without one drops out of the join with no error anywhere.
 *
 * Emitted at note severity even when the count is zero, which is a deliberate
 * departure from the "omit when absent" rule the other probes follow. Absence of
 * this advisory would otherwise be ambiguous between "SeoSuite is not installed"
 * and "checked, and clean", and that difference is exactly what an operator
 * wants from an orientation call.
 */
final class SeoSuiteSitemapAdvisory implements AdvisoryInterface
{
    private const MODEL = 'Sterc\\SeoSuite\\Model\\SeoSuiteResource';

    public function id(): string
    {
        return 'seosuite.sitemap_inner_join';
    }

    public function detect(modX $modx): ?Advisory
    {
        $presence = new ExtraPresence($modx);
        if (!$presence->has('seosuite')) {
            return null;
        }

        // SeoSuite's model is not loaded until the scanner registers its
        // package; SeoRedirectTool calls this for the same side effect.
        (new PackageScanner($modx))->classes();

        if (!class_exists(self::MODEL)) {
            return Advisory::gotcha(
                $this->id(),
                'SeoSuite is installed but its model could not be loaded, so whether any '
                . 'resource is missing from sitemap.xml could not be checked.',
                ['orphan_count' => null, 'checked_class' => self::MODEL],
                ['require' => 'write through the processor-backed resource tools'],
            )->withExtra('seosuite', $presence->version('SEO Suite'));
        }

        $registered = [];
        foreach ($modx->getIterator(self::MODEL) as $row) {
            $registered[(int) $row->get('resource_id')] = true;
        }

        $orphans = [];
        $query   = $modx->newQuery(modResource::class);
        $query->where(['published' => 1, 'deleted' => 0, 'searchable' => 1]);
        foreach ($modx->getIterator(modResource::class, $query) as $resource) {
            $id = (int) $resource->get('id');
            if (!isset($registered[$id])) {
                $orphans[] = $id;
            }
        }

        $count = count($orphans);

        $rule = [
            'symptom' => 'the resource is absent from sitemap.xml with no error anywhere',
            'cause'   => 'the SeoSuite sitemap snippet inner joins its own table, and a resource '
                . 'with no row drops out of the join',
            'require' => 'write through modxmcp_resource_create or modxmcp_resource_update so '
                . 'OnDocFormSave fires; never modxmcp_object_save against a resource class',
            'verify'  => '/sitemap.xml',
        ];

        if ($count === 0) {
            return Advisory::note(
                $this->id(),
                'Checked: every published, searchable resource has a SeoSuite row, so none is '
                . 'silently missing from sitemap.xml.',
                ['orphan_count' => 0, 'checked_class' => self::MODEL],
                $rule
            )->withExtra('seosuite', $presence->version('SEO Suite'));
        }

        $rule['remediation'] = 're-save each listed resource with modxmcp_resource_update';

        return Advisory::blocker(
            $this->id(),
            sprintf(
                '%d published resource%s no SeoSuite row and %s silently absent from '
                . 'sitemap.xml.',
                $count,
                $count === 1 ? ' has' : 's have',
                $count === 1 ? 'is' : 'are'
            ),
            [
                'orphan_count'        => $count,
                'sample_resource_ids' => array_slice($orphans, 0, 20),
                'checked_class'       => self::MODEL,
                'check'               => 'published=1 AND deleted=0 AND searchable=1 with no companion row',
            ],
            $rule
        )->withExtra('seosuite', $presence->version('SEO Suite'));
    }
}
