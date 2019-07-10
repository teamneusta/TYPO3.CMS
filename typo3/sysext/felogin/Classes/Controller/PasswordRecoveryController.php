<?php
declare(strict_types = 1);

namespace TYPO3\CMS\Felogin\Controller;

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

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Error\Result;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Felogin\Service\RecoveryServiceInterface;
use TYPO3\CMS\Felogin\Service\ValidatorResolverService;

/**
 * Class PasswordRecoveryController
 *
 * @internal this is a concrete TYPO3 implementation and solely used for EXT:felogin and not part of TYPO3's Core API.
 */
class PasswordRecoveryController extends ActionController
{
    /**
     * @var \TYPO3\CMS\Felogin\Service\RecoveryServiceInterface
     */
    protected $recoveryService;

    /**
     * @param \TYPO3\CMS\Felogin\Service\RecoveryServiceInterface $recoveryService
     */
    public function injectRecoveryService(RecoveryServiceInterface $recoveryService): void
    {
        $this->recoveryService = $recoveryService;
    }

    /**
     * Shows the recovery form. If $userIdentifier is set an email will be sent, if the corresponding user exists
     *
     * @param string|null $userIdentifier
     *
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException
     */
    public function recoveryAction(string $userIdentifier = null): void
    {
        if (empty($userIdentifier)) {
            return;
        }

        $email = $this->fetchEmailFromUser($userIdentifier);
        if ($email) {
            $this->recoveryService->sendRecoveryEmail($email);
        }

        $this->addFlashMessage($this->getTranslation('ll_change_password_done_message'));

        $this->redirect('login', 'Login', 'felogin');
    }

    /**
     * Validate hash and make sure it's not expired. If it is not in the correct format or not set at all, a redirect
     * to recoveryAction() is made, without further information.
     *
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException
     */
    public function initializeShowChangePasswordAction(): void
    {
        $hash = $this->request->hasArgument('hash') ? $this->request->getArgument('hash') : '';

        if (!$this->hasValidHash($hash)) {
            $this->redirect('recovery', 'PasswordRecovery', 'felogin');
        }

        $timestamp = (int)GeneralUtility::trimExplode('|', $hash)[0];
        $currentTimestamp = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');

        //timestamp is expired or hash can not be assigned to a user
        if ($currentTimestamp > $timestamp || !$this->recoveryService->existsUserWithHash($hash)) {
            $result = $this->request->getOriginalRequestMappingResults();
            $result->addError(new Error($this->getTranslation('password_recovery_link_expired'), 1554994253));
            $this->request->setOriginalRequestMappingResults($result);
            $this->forward('recovery', 'PasswordRecovery', 'felogin');
        }
    }

    /**
     * Show the change password form but only if a $hash exists (from get parameters). $hash is sent to the
     * user per email.
     *
     * @param string $hash
     */
    public function showChangePasswordAction(string $hash): void
    {
        $this->view->assign('hash', $hash);
    }

    /**
     * Validate entered password and passwordRepeat values. If they are invalid a forward() to
     * showChangePasswordAction() takes place. All validation errors are put into the request mapping results.
     *
     * Used validators are configured via TypoScript settings.
     *
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     */
    public function initializeChangePasswordAction(): void
    {
        //Exit early if newPass or newPassRepeat is not set.
        $originalResult = $this->request->getOriginalRequestMappingResults();
        $argumentsExist = $this->request->hasArgument('newPass') && $this->request->hasArgument('newPassRepeat');
        $argumentsEmpty = empty($this->request->getArgument('newPass')) || empty($this->request->getArgument('newPassRepeat'));
        if (!$argumentsExist || $argumentsEmpty) {
            $originalResult->addError(new Error(
                $this->getTranslation('empty_password_and_password_repeat'),
                1554971665
            ));
            $this->request->setOriginalRequestMappingResults($originalResult);
            $this->forward(
                'showChangePassword',
                'PasswordRecovery',
                'felogin',
                ['hash' => $this->request->getArgument('hash')]
            );
        }

        $this->validateNewPassword($originalResult);

        //if an error exists, forward with all messages to the change password form
        if ($originalResult->hasErrors()) {
            $this->forward(
                'showChangePassword',
                'PasswordRecovery',
                'felogin',
                ['hash' => $this->request->getArgument('hash')]
            );
        }
    }

    /**
     * Change actual password. Hash $newPass and update the user with the corresponding $hash.
     *
     * @param string $newPass
     * @param string $hash
     *
     * @throws \TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException
     */
    public function changePasswordAction(string $newPass, string $hash): void
    {
        $passwordHash = GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->getDefaultHashInstance('FE')
            ->getHashedPassword($newPass);

        $this->recoveryService->updatePasswordAndInvalidateHash($hash, $passwordHash);

        $this->redirect('login', 'Login', 'felogin');
    }

    /**
     * Check if a fe user exists with passed $emailOrUsername
     *
     * @param string $emailOrUsername
     *
     * @return bool|string
     */
    protected function fetchEmailFromUser(string $emailOrUsername)
    {
        $queryBuilder = $this->getQueryBuilder();

        return $queryBuilder->select('email')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($emailOrUsername)),
                    $queryBuilder->expr()->eq('email', $queryBuilder->createNamedParameter($emailOrUsername))
                ),
                // respect storage pid
                $queryBuilder->expr()->in('pid', $this->settings['pages'])
            )
            ->execute()
            ->fetchColumn();
    }

    /**
     * @param \TYPO3\CMS\Extbase\Error\Result $originalResult
     *
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException
     */
    protected function validateNewPassword(Result $originalResult): void
    {
        $newPass = $this->request->getArgument('newPass');

        //make sure the user entered the password twice
        if ($newPass !== $this->request->getArgument('newPassRepeat')) {
            $originalResult->addError(new Error($this->getTranslation('password_must_match_repeated'), 1554912163));
        }

        // Resolve validators from TypoScript configuration
        $validators = GeneralUtility::makeInstance(ValidatorResolverService::class)
            ->resolve($this->settings['passwordValidators']);

        // Call each validator on $newPass
        foreach ($validators as $validator) {
            $result = $validator->validate($newPass);
            $originalResult->merge($result);
        }

        //set the result from all validators
        $this->request->setOriginalRequestMappingResults($originalResult);
    }

    /**
     * @return \TYPO3\CMS\Core\Database\Query\QueryBuilder
     */
    protected function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('fe_users')
            ->createQueryBuilder();
    }

    /**
     * Wrapper to mock LocalizationUtility::translate
     *
     * @param string $key
     *
     * @return string|null
     */
    protected function getTranslation(string $key): ?string
    {
        return LocalizationUtility::translate($key, 'felogin');
    }

    /**
     * Validates that $hash is in the expected format (timestamp|forgot_hash)
     *
     * @param string $hash
     *
     * @return bool
     */
    protected function hasValidHash($hash): bool
    {
        return !empty($hash) && is_string($hash) && strpos($hash, '|') === 10;
    }
}
