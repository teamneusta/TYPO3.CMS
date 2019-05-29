<?php
declare(strict_types=1);

namespace TYPO3\CMS\Felogin\ViewHelpers;

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
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Felogin\Service\LabelServiceInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class LabelViewHelper
 * @internal this is a concrete TYPO3 implementation and solely used for EXT:felogin and not part of TYPO3's Core API.
 */
class LabelViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('identifier', 'string', 'Label identifier', true);
    }

    public function render(): string
    {
        $labelService = GeneralUtility::makeInstance(ObjectManager::class)->get(LabelServiceInterface::class);

        return $labelService->getLabel($this->arguments['identifier']);
    }
}
