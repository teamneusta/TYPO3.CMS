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
     * Sends email to $emailAddress with recovery instructions
     *
     * @param string $emailAddress
     * @return void
     */
    public function sendRecoveryEmail(string $emailAddress): void;

    /**
     * Change the password for an user based on hash.
     *
     * @param string $hash The hash of the feUser that should be resolved.
     * @param string $passwordHash The new password.
     */
    public function updatePasswordAndInvalidateHash(string $hash, string $passwordHash): void;

    /**
     * Returns true if a user exists with hash as `felogin_forgothash`, otherwise false.
     *
     * @param string $hash The hash of the feUser that should be check for existence.
     * @return bool Either true or false based on the existence of the user.
     */
    public function existsUserWithHash(string $hash): bool;
}
