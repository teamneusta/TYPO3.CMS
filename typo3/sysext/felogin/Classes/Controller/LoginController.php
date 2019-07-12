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

use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Felogin\Service\TreeUidListProvider;

/**
 * Used for plugin login
 */
class LoginController extends ActionController
{
    public const MESSAGEKEY_DEFAULT = 'welcome';
    public const MESSAGEKEY_ERROR = 'error';
    public const MESSAGEKEY_LOGOUT = 'logout';

    /**
     * show login form
     */
    public function loginAction(): void
    {
        $loginType = (string)$this->getPropertyFromGetAndPost('logintype');
        $isLoggedInd = $this->isUserLoggedIn();

        $this->handleForwards($isLoggedInd, $loginType);
        $this->onSubmitFuncsHook();

        $this->view->assignMultiple(
            [
                'messageKey' => $this->getStatusMessage($loginType, $isLoggedInd),
                'storagePid' => $this->getStoragePid(),
                'permaloginStatus' => $this->getPermaloginStatus(),
            ]
        );
    }

    /**
     * returns a property that exists in post or get context
     *
     * @param string $propertyName
     * @return mixed|null
     */
    protected function getPropertyFromGetAndPost(string $propertyName)
    {
        // todo: refactor when extbase handles PSR-15 requests
        $request = $GLOBALS['TYPO3_REQUEST'];

        return $request->getParsedBody()[$propertyName] ?? $request->getQueryParams()[$propertyName] ?? null;
    }

    /**
     * check if the user is logged in
     *
     * @return bool
     * @throws AspectNotFoundException
     */
    protected function isUserLoggedIn(): bool
    {
        return (bool)GeneralUtility::makeInstance(Context::class)
            ->getPropertyFromAspect('frontend.user', 'isLoggedIn');
    }

    /**
     * handle forwards to overview and logout actions
     *
     * @param bool $userLoggedIn
     * @param string $loginType
     * @throws StopActionException
     */
    protected function handleForwards(bool $userLoggedIn, string $loginType): void
    {
        if ($this->shouldRedirectToOverview($userLoggedIn, $loginType === LoginType::LOGIN)) {
            $this->forward('overview', null, null, ['showLoginMessage' => true]);
        }

        if ($userLoggedIn) {
            $this->forward('logout');
        }
    }

    /**
     * redirect to overview on login successful and setting showLogoutFormAfterLogin disabled
     *
     * @param bool $userLoggedIn
     * @param bool $isLoginTypeLogin
     * @return bool
     */
    protected function shouldRedirectToOverview(bool $userLoggedIn, bool $isLoginTypeLogin): bool
    {
        return $userLoggedIn && $isLoginTypeLogin && !($this->settings['showLogoutFormAfterLogin'] ?? 0);
    }

    /**
     * Handle loginFormOnSubmitFuncs hook
     *
     * @return void
     */
    protected function onSubmitFuncsHook(): void
    {
        foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['felogin']['loginFormOnSubmitFuncs'] ?? [] as $funcRef) {
            [$onSub, $hid] = GeneralUtility::callUserFunction($funcRef, $_params, $this);
            $onSubmit[] = $onSub;
            $extraHidden[] = $hid;
        }

        if (!empty($onSubmit)) {
            $onSubmit = implode('; ', $onSubmit) . '; return true;';
            $this->view->assign('onSubmit', $onSubmit);
        }

        if (!empty($extraHidden)) {
            $extraHidden = implode(LF, $extraHidden);
            $this->view->assign('extraHidden', $extraHidden);
        }
    }

    /**
     * return message key based on user login status
     *
     * @param string $loginType
     * @param bool $isLoggedInd
     * @return string
     */
    protected function getStatusMessage(string $loginType, bool $isLoggedInd): string
    {
        $messageKey = self::MESSAGEKEY_DEFAULT;
        if ($loginType === LoginType::LOGIN && !$isLoggedInd) {
            $messageKey = self::MESSAGEKEY_ERROR;
        } elseif ($loginType === LoginType::LOGOUT) {
            $messageKey = self::MESSAGEKEY_LOGOUT;
        }

        return $messageKey;
    }

    /**
     * provides list of storage pids
     *
     * @return string
     */
    protected function getStoragePid(): string
    {
        $storageProvider = new TreeUidListProvider($this->configurationManager->getContentObject());

        return $storageProvider->getListForIdList((string)$this->settings['pages'], (int)$this->settings['recursive']);
    }

    /**
     * The permanent login checkbox should only be shown if permalogin is not deactivated (-1),
     * not forced to be always active (2) and lifetime is greater than 0
     *
     * @return int
     */
    protected function getPermaloginStatus(): int
    {
        $permaLogin = (int)$GLOBALS['TYPO3_CONF_VARS']['FE']['permalogin'];

        return $this->isPermaloginDisabled($permaLogin) ? -1 : $permaLogin;
    }

    protected function isPermaloginDisabled(int $permaLogin): bool
    {
        return $permaLogin > 1 || (int)($this->settings['showPermaLogin'] ?? 0) === 0 || $GLOBALS['TYPO3_CONF_VARS']['FE']['lifetime'] === 0;
    }

    /**
     * user overview for logged in users
     *
     * @param bool $showLoginMessage
     * @throws StopActionException
     * @throws AspectNotFoundException
     */
    public function overviewAction(bool $showLoginMessage = false): void
    {
        if (!$this->isUserLoggedIn()) {
            $this->forward('login');
        }

        $this->view->assignMultiple(
            [
                'user' => $GLOBALS['TSFE']->fe_user->user ?? [],
                'showLoginMessage' => $showLoginMessage,
            ]
        );
    }

    /**
     * show logout form
     */
    public function logoutAction(): void
    {
        $this->view->assignMultiple(
            [
                'user' => $GLOBALS['TSFE']->fe_user->user ?? [],
                'storagePid' => $this->getStoragePid(),
            ]
        );
    }
}
