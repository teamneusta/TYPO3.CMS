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
use TYPO3\CMS\Felogin\Redirect\RedirectHandler;
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
     * @var RedirectHandler
     */
    protected $redirectHandler;

    /**
     * @var string
     */
    protected $redirectUrl;

    /**
     * @var string
     */
    protected $loginType;

    /**
     * @var bool
     */
    protected $cookieWarning = false;

    public function __construct(RedirectHandler $redirectHandler)
    {
        $this->redirectHandler = $redirectHandler;
    }

    public function initializeAction(): void
    {
        $this->loginType = (string)$this->getPropertyFromGetAndPost('logintype');

        if (!$this->isRedirectDisabled()) {
            $this->redirectUrl = $this->redirectHandler->process($this->settings, $this->request);
        }

        if (($this->loginType === LoginType::LOGIN || $this->loginType === LoginType::LOGOUT) && $this->redirectUrl && !$this->isRedirectDisabled()) {
            // Das geht nicht: isCookieSet hängt nicht am fe_user sondern an der fe_authentication...
            if (!$this->getFeUser()->isCookieSet() && $this->isUserLoggedIn()) {
                $this->cookieWarning = true;
            }
        }
    }

    /**
     * show login form
     */
    public function loginAction(): void
    {
        $this->handleLoginForwards();

        $this->redirectIfNecessary();

        $this->view->assignMultiple(
            [
                'messageKey'       => $this->getStatusMessage(),
                'storagePid'       => $this->getStoragePid(),
                'permaloginStatus' => $this->getPermaloginStatus(),
                'redirectURL'      => $this->getLoginRedirectURL(),
                'redirectReferrer' => $this->getRedirectReferrer(),
                'noRedirect'       => $this->isRedirectDisabled(),
                'cookieWarning'    => $this->cookieWarning
            ]
        );
    }

    protected function getRedirectReferrer():string
    {
        return $this->request->hasArgument('redirectReferrer') ? (string)$this->request->getArgument('redirectReferrer') : '';
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
                'user'             => $this->getFeUser(),
                'showLoginMessage' => $showLoginMessage,
                'cookieWarning'    => $this->cookieWarning
            ]
        );
    }

    /**
     * show logout form
     */
    public function logoutAction(): void
    {
        //@todo: noredirect params
        $this->view->assignMultiple(
            [
                'user'       => $this->getFeUser(),
                'storagePid' => $this->getStoragePid(),
                'cookieWarning'    => $this->cookieWarning,
            ]
        );
    }

    /**
     * returns the parsed storagePid list including recursions
     *
     * @return string
     */
    protected function getStoragePid(): string
    {
        $storageProvider = new TreeUidListProvider($this->configurationManager->getContentObject());

        return $storageProvider->getListForIdList((string)$this->settings['pages'], (int)$this->settings['recursive']);
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
     * handle forwards to overview and logout actions from login action
     *
     * @throws StopActionException
     */
    protected function handleLoginForwards(): void
    {
        if ($this->shouldRedirectToOverview()) {
            $this->forward('overview', null, null, ['showLoginMessage' => true]);
        }

        if ($this->isUserLoggedIn()) {
            $this->forward('logout');
        }
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
     * Get RedirURL for Login Form from GP vars
     *
     * @return string
     */
    protected function getLoginRedirectURL():string
    {
        return (string)$this->getPropertyFromGetAndPost('redirect_url');
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
     * redirect to overview on login successful and setting showLogoutFormAfterLogin disabled
     *
     * @return bool
     */
    protected function shouldRedirectToOverview(): bool
    {
        return $this->isUserLoggedIn() && ($this->loginType === LoginType::LOGIN) && !($this->settings['showLogoutFormAfterLogin'] ?? 0);
    }

    /**
     * return message key based on user login status
     *
     * @return string
     */
    protected function getStatusMessage(): string
    {
        $messageKey = self::MESSAGEKEY_DEFAULT;
        if ($this->loginType === LoginType::LOGIN && !$this->isUserLoggedIn()) {
            $messageKey = self::MESSAGEKEY_ERROR;
        } elseif ($this->loginType === LoginType::LOGOUT) {
            $messageKey = self::MESSAGEKEY_LOGOUT;
        }

        return $messageKey;
    }

    protected function redirectIfNecessary():void
    {
        if ($this->redirectUrl === '') {
            return;
        }
        //@ToDo: Do the redirect. Really ;-)
        die('Leite weiter zu ' . $this->redirectUrl);
    }

    protected function getFeUser() {
        return $GLOBALS['TSFE']->fe_user->user ?? [];
    }

    /**
     * Should this controller make use of possibly configured redirects at all?
     * @return bool
     */
    public function isRedirectDisabled():bool
    {
        return $this->request->hasArgument('noredirect') || $this->settings['noredirect'] || $this->settings['redirectDisable'];
    }

}
