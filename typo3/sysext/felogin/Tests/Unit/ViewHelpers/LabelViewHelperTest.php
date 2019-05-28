<?php
declare(strict_types=1);

namespace TYPO3\CMS\Felogin\Tests\Unit\ViewHelpers;

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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Felogin\Service\LabelService;
use TYPO3\CMS\Felogin\ViewHelpers\LabelViewHelper;
use TYPO3\TestingFramework\Fluid\Unit\ViewHelpers\ViewHelperBaseTestcase;

class LabelViewHelperTest extends ViewHelperBaseTestcase
{
    protected $resetSingletonInstances = true;

    /**
     * @test
     */
    public function renderShouldCallLabelServiceWithIdentifierAndReturnTheResult(): void
    {
        $viewHelper = new LabelViewHelper();
        $this->injectDependenciesIntoViewHelper($viewHelper);
        $this->setArgumentsUnderTest($viewHelper, ['identifier' => 'my identifier']);

        $labelService = $this->prophesize(LabelService::class);
        $labelService->getLabel('my identifier')->willReturn('my label');
        GeneralUtility::addInstance(LabelService::class, $labelService->reveal());

        static::assertSame('my label', $viewHelper->initializeArgumentsAndRender());
    }
}
