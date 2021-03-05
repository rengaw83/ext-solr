<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ApacheSolrForTypo3\Solr\EventListener\EnhancedRouting;

use ApacheSolrForTypo3\Solr\Event\Routing\BeforeProcessCachedVariablesEvent;
use ApacheSolrForTypo3\Solr\Routing\RoutingService;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Event listener to handle path elements containing placeholder
 *
 * @author Lars Tode <lars.tode@dkd.de>
 */
class CachedPathVariableModifier
{
    public function __invoke(BeforeProcessCachedVariablesEvent $event): void
    {
        if (!$event->hasRouting()) {
            return;
        }
        $pathVariables = $this->getPathVariablesFromUri($event->getUri());

        // No path variables exists .. skip processing
        if (empty($pathVariables)) {
            return;
        }

        $variableKeys = $event->getVariableKeys();
        $variableValues = $event->getVariableValues();
        $enhancerConfiguration = $event->getRouterConfiguration();

        /* @var RoutingService $routingService */
        $routingService = GeneralUtility::makeInstance(
            RoutingService::class,
            $enhancerConfiguration['solr'],
            (string)$enhancerConfiguration['extensionKey']
        );

        if (!$routingService->isRouteEnhancerForSolr((string)$enhancerConfiguration['type'])) {
            return;
        }

        $multiValue = true;

        $standardizedKeys = $variableKeys;

        for ($i = 0; $i < count($variableKeys); $i++) {
            $standardizedKey = $this->standardizeKey($variableKeys[$i]);
            if (!in_array($standardizedKey, $pathVariables) || empty($variableValues[$standardizedKey])) {
                continue;
            }
            $value = '';
            if ($multiValue) {
                // Note: if the customer configured a + as separator an additional check on the facet value is required!
                $facets = $routingService->pathFacetStringToArray(
                    $this->standardizeKey((string)$variableValues[$standardizedKey])
                );

                $singleValues = [];
                $index = 0;
                foreach ($facets as $facet) {
                    if (mb_strpos($facet, ':') !== false) {
                        [$prefix, $value] = explode(
                            ':',
                            $facet,
                            2
                        );
                        $singleValues[] = $value;
                        $index++;
                    } else {
                        $singleValues[$index - 1] .= ' ' . $facet;
                    }
                }
                $value = $routingService->pathFacetsToString($singleValues);
            } else {
                [$prefix, $value] = explode(
                    ':',
                    $this->standardizeKey((string)$variableValues[$standardizedKey]),
                    2
                );
            }
            $standardizedKeys[$i] = $standardizedKey;
            $variableValues[$standardizedKey] = $value;
        }

        $event->setVariableValues($variableValues);
        $event->setVariableKeys($standardizedKeys);
    }

    /**
     * Extract path variables from URI
     *
     * @param UriInterface $uri
     * @return array
     */
    protected function getPathVariablesFromUri(UriInterface $uri): array
    {
        $elements = explode('/', $uri->getPath());
        $variables = [];

        foreach ($elements as $element) {
            if (empty($element)) {
                continue;
            }
            $element = $this->standardizeKey($element);
            if (substr($element, 0, 3) !== '###') {
                continue;
            }

            $variables[] = $element;
        }

        return $variables;
    }

    /**
     * Standardize a given string in order to reduce the amount of if blocks
     *
     * @param string $key
     * @return string
     */
    protected function standardizeKey(string $key): string
    {
        $map = [
            '%23' => '#',
            '%3A' => ':',
        ];
        return str_replace(array_keys($map), array_values($map), $key);
    }
}
