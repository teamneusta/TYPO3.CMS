<?php
declare(strict_types=1);

namespace TYPO3\CMS\Felogin\Tests\Unit\Service;

use Prophecy\Argument;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Felogin\Service\LabelService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class LabelServiceTest extends UnitTestCase
{
    /**
     * @var \TYPO3\CMS\Felogin\Service\LabelService
     */
    protected $subject;

    /**
     * @var \Prophecy\Prophecy\ObjectProphecy|\TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
     */
    protected $cache;

    /**
     * @var \Prophecy\Prophecy\ObjectProphecy|\TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer
     */
    protected $cObj;

    /**
     * @var \Prophecy\Prophecy\ObjectProphecy|\TYPO3\CMS\Extbase\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    protected function setUp(): void
    {
        $this->cache = $this->prophesize(FrontendInterface::class);
        $cacheManager = $this->prophesize(CacheManager::class);
        $cacheManager->getCache(Argument::any())->willReturn($this->cache->reveal());

        $this->configurationManager = $this->prophesize(ConfigurationManager::class);
        $this->cObj = $this->prophesize(ContentObjectRenderer::class);
        $this->cObj->data = ['uid' => '42'];

        $this->configurationManager->getContentObject()->willReturn($this->cObj->reveal());

        $this->subject = new LabelService($cacheManager->reveal(), $this->configurationManager->reveal());
    }

    /**
     * @test
     */
    public function getLabelShouldExitEarlyIfCacheHasAnEntry(): void
    {
        $this->cache->has(Argument::any())->willReturn(true);
        $this->cache->get(Argument::any())->willReturn('some label');

        $this->subject->getLabel('label');

        $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS)->shouldNotHaveBeenCalled();
    }

//    public function getLabelShouldPrioritizeFlexFormValues(): void
//    {
//        $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS)
//            ->willReturn(['label' => 'my flexform value']);
//
//        $this->subject->getLabel('label');
//    }
}
