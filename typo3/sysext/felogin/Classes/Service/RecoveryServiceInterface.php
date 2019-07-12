<?php
declare(strict_types = 1);

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

/**
 * Interface RecoveryServiceInterface
 */
interface RecoveryServiceInterface
{
    /**
     * @param string $emailAddress
     * @return void
     */
    public function sendRecoveryEmail(string $emailAddress): void;

    /**
     * @param string $hash
     * @param string $passwordHash
     */
    public function updatePasswordAndInvalidateHash(string $hash, string $passwordHash): void;

    /**
     * @param string $hash
     * @return bool
     */
    public function existsUserWithHash(string $hash): bool;
}
