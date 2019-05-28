<?php
declare(strict_types=1);

namespace TYPO3\CMS\Felogin\Service;

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

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Class LabelService
 * Uses an in memory cache to store resolved labels. FlexForm values are prioritized higher then LOCAL_LANG and TS.
 *
 * @internal this is a concrete TYPO3 implementation and solely used for EXT:felogin and not part of TYPO3's Core API.
 */
class LabelService
{
    protected const CACHE_IDENTIFIER = 'felogin_labels';

    /**
     * @var \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
     */
    protected $cache;

    /**
     * @var \TYPO3\CMS\Extbase\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    /**
     * LabelService constructor.
     * @param \TYPO3\CMS\Core\Cache\CacheManager $cacheManager
     * @param \TYPO3\CMS\Extbase\Configuration\ConfigurationManager $configurationManager
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function __construct(CacheManager $cacheManager, ConfigurationManager $configurationManager)
    {
        $this->cache = $cacheManager->getCache(static::CACHE_IDENTIFIER);
        $this->configurationManager = $configurationManager;
    }

    /**
     * @param string $identifier
     * @param array|null $arguments
     * @return string
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    public function getLabel(string $identifier, ?array $arguments = null): string
    {
        $uid = $this->configurationManager->getContentObject()->data['uid'];
        $entryIdentifier = md5($uid . $identifier);

        if ($this->cache->has($entryIdentifier)) {
            return $this->cache->get($entryIdentifier);
        }

        $settings = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);
        if (!empty($settings[$identifier])) {
            $identifier = $settings[$identifier];
        }

        $label = $this->translate($identifier, $arguments) ?? $identifier;

        $this->cache->set($entryIdentifier, $label);

        return $label;
    }

    private function translate(string $identifier, ?array $arguments): ?string
    {
        return LocalizationUtility::translate($identifier, 'felogin', $arguments)
            //try again with prefix
            ?? LocalizationUtility::translate('ll_' . $identifier, 'felogin', $arguments);
    }
}

