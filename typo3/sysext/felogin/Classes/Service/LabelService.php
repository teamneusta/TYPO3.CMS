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
use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Class LabelService
 * Uses an in memory cache to store resolved labels. FlexForm values are prioritized higher then LOCAL_LANG and TS.
 *
 * @internal this is a concrete TYPO3 implementation and solely used for EXT:felogin and not part of TYPO3's Core API.
 */
class LabelService implements LabelServiceInterface
{
    protected const CACHE_IDENTIFIER = 'felogin_labels';

    protected const SESSION_KEY_PREFIX = 'felogin_label_';

    /**
     * @var \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
     */
    protected $cache;

    /**
     * @var \TYPO3\CMS\Extbase\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var string[]
     */
    protected $statusLabels = [];

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
        $entryIdentifier = $this->getEntryIdentifier($identifier);

        switch ($entryIdentifier) {
            case array_key_exists($entryIdentifier, $this->statusLabels):
                $label = $this->statusLabels[$entryIdentifier];
                break;
            case $GLOBALS['TSFE']->fe_user->getSessionData(static::SESSION_KEY_PREFIX . $entryIdentifier):
                $label = $GLOBALS['TSFE']->fe_user->getSessionData(static::SESSION_KEY_PREFIX . $entryIdentifier);
                break;
            case $this->cache->has($entryIdentifier):
                $label = $this->cache->get($entryIdentifier);
                break;
            default:
                $label = $this->resolveLabelByIdentifier($identifier, $arguments);

                $this->cache->set($entryIdentifier, $label);
        }

        return $label;
    }

    /**
     * @param string $identifier
     * @param string $value
     * @param bool $persistInSession
     */
    public function setLabel(string $identifier, string $value, bool $persistInSession = false): void
    {
        $entryIdentifier = $this->getEntryIdentifier($identifier);

        if ($persistInSession) {
            $GLOBALS['TSFE']->fe_user->setAndSaveSessionData(static::SESSION_KEY_PREFIX . $entryIdentifier, $value);
        } else {
            $this->statusLabels[$entryIdentifier] = $value;
        }
    }

    /**
     * @param string $identifier
     * @param array|null $arguments
     * @return string|null
     */
    protected function translate(string $identifier, ?array $arguments): ?string
    {
        return LocalizationUtility::translate($identifier, 'felogin', $arguments)
            //try again with prefix
            ?? LocalizationUtility::translate('ll_' . $identifier, 'felogin', $arguments);
    }

    /**
     * @param string $identifier
     * @return string
     */
    protected function getEntryIdentifier(string $identifier): string
    {
        $uid = $this->configurationManager->getContentObject()->data['uid'];

        return md5($uid . $identifier);
    }

    /**
     * @param string $identifier
     * @param array|null $arguments
     * @return string
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function resolveLabelByIdentifier(string $identifier, ?array $arguments): string
    {
        $settings = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);

        if (!empty($settings[$identifier])) {
            $identifier = $settings[$identifier];
        }

        return $this->translate($identifier, $arguments) ?? $identifier;
    }
}
