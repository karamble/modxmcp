<?php

namespace MODXMCP\Knowledge;

use MODX\Revolution\modX;
use MODXMCP\Knowledge\Advisories\CollectionsChildrenAdvisory;
use MODXMCP\Knowledge\Advisories\ElementCacheAdvisory;
use MODXMCP\Knowledge\Advisories\PackageUpdateAdvisory;
use MODXMCP\Knowledge\Advisories\ReservedTvNameAdvisory;
use MODXMCP\Knowledge\Advisories\SeoSuiteSitemapAdvisory;

/**
 * Runs every probe and caches the result.
 *
 * Three behaviours are deliberate.
 *
 * A probe that throws costs its own advisory and nothing else, the same bargain
 * Runtime::registerAdapters() makes for adapters. These run on the orientation
 * call that clients are told to make first, and one misbehaving probe must not
 * be able to take that call down.
 *
 * The result is cached, because the SeoSuite probe counts across every published
 * resource and site_info is called at the start of every session. It reuses
 * PackageScanner's conventions rather than inventing new ones, including
 * assigning the payload to a variable first: xPDOCacheManager::set() takes its
 * value by reference and will not accept an expression.
 *
 * OnMCPCollectAdvisories lets an extra contribute a rule about itself, which is
 * the only way rules that live in someone else's event handler can ever reach a
 * caller.
 */
final class AdvisoryCollector
{
    private const CACHE_KEY = 'modxmcp/advisories';

    private modX $modx;
    /** @var AdvisoryInterface[] */
    private array $probes;
    /** @var array<int,array<string,mixed>>|null */
    private ?array $memo = null;

    public function __construct(modX $modx)
    {
        $this->modx   = $modx;
        $this->probes = [
            new CollectionsChildrenAdvisory(),
            new SeoSuiteSitemapAdvisory(),
            new ReservedTvNameAdvisory(),
            new PackageUpdateAdvisory(),
            new ElementCacheAdvisory(),
        ];
    }

    public function add(AdvisoryInterface $probe): void
    {
        $this->probes[] = $probe;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collect(bool $refresh = false): array
    {
        if (!$refresh && $this->memo !== null) {
            return $this->memo;
        }

        if (!$refresh) {
            $cached = $this->modx->cacheManager->get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $this->memo = $cached;
            }
        }

        $this->invokeCollectionEvent();

        $advisories = [];
        foreach ($this->probes as $probe) {
            try {
                $advisory = $probe->detect($this->modx);
                if ($advisory instanceof Advisory) {
                    $advisories[] = $advisory->toArray();
                }
            } catch (\Throwable $e) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    'modxmcp: advisory probe ' . $probe->id() . ' failed: ' . $e->getMessage()
                );
            }
        }

        // By reference, so it has to be a variable.
        $payload = $advisories;
        $this->modx->cacheManager->set(
            self::CACHE_KEY,
            $payload,
            (int) $this->modx->getOption('modxmcp.discovery_cache_seconds', null, 300)
        );

        return $this->memo = $advisories;
    }

    public function forget(): void
    {
        $this->memo = null;
        $this->modx->cacheManager->delete(self::CACHE_KEY);
    }

    /**
     * The string form, kept so site_info's existing warnings[] does not change
     * type for callers that read it.
     *
     * @param array<int,array<string,mixed>> $advisories
     * @return string[]
     */
    public function summaries(array $advisories): array
    {
        return array_values(array_filter(array_map(
            static fn(array $a): string => (string) ($a['summary'] ?? ''),
            $advisories
        )));
    }

    private function invokeCollectionEvent(): void
    {
        try {
            $this->modx->invokeEvent('OnMCPCollectAdvisories', [
                'collector' => $this,
                'modx'      => $this->modx,
            ]);
        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                'modxmcp: a plugin on OnMCPCollectAdvisories threw: ' . $e->getMessage()
            );
        }
    }
}
