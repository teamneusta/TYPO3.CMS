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
    protected $loginType;

    public function __construct(RedirectHandler $redirectHandler)
    {
        $this->redirectHandler = $redirectHandler;
    }

    public function initializeAction(): void
    {
        $this->redirectHandler->init($this->settings, $this->request);

        $this->loginType = (string)$this->getPropertyFromGetAndPost('logintype');

        if (($this->loginType === LoginType::LOGIN || $this->loginType === LoginType::LOGOUT) && !$this->isRedirectDisabled()) {
            $redirectUrl = $this->redirectHandler->processRedirect();
            if (!$this->getFeUser()->isCookieSet() && $this->isUserLoggedIn()) {
                $this->view->assign('cookieWarning', true);
            } else {
                $this->redirectIfNecessary($redirectUrl);
            }
        }
    }

    /**
     * show login form
     */
    public function loginAction(): void
    {
        $this->handleLoginForwards();

        $redirectUrl = $this->redirectHandler->getRedirectUrlRequestParam();
        if (!$this->isRedirectDisabled()) {
            $redirectUrl = $this->redirectHandler->getLoginRedirectUrl();
        }

        $this->onSubmitFuncsHook();
        $this->view->assignMultiple(
            [
                'messageKey'       => $this->getStatusMessage(),
                'storagePid'       => $this->getStoragePid(),
                'permaloginStatus' => $this->getPermaloginStatus(),
                'redirectURL'      => $redirectUrl,
                'redirectReferrer' => $this->getRedirectReferrer(),
                'referer'          => $this->getReferer(),
                'noRedirect'       => $this->isRedirectDisabled(),

            ]
        );
    }

    protected function getRedirectReferrer():string
    {
        return $this->request->hasArgument('redirectReferrer') ? (string)$this->request->getArgument('redirectReferrer') : '';
    }

    protected function getReferer():string
    {
        return (string)$this->getPropertyFromGetAndPost('referer');
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
                'user'             => $this->getFeUser()->user,
                'showLoginMessage' => $showLoginMessage
            ]
        );
    }

    /**
     * show logout form
     */
    public function logoutAction(): void
    {

        $actionUri = $this->redirectHandler->getRedirectUrlRequestParam();
        if (!$this->isRedirectDisabled()) {
            $actionUri = $this->redirectHandler->getLogoutRedirectUrl();
        }


        $this->view->assignMultiple(
            [
                'user'       => $this->getFeUser()->user,
                'storagePid' => $this->getStoragePid(),
                'noRedirect' => $this->isRedirectDisabled(),
                'actionUri'  => $actionUri
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
     * check if the user is logged in
     *
     * @return bool
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

    /**
     * @param string $redirectUrl
     */
    protected function redirectIfNecessary($redirectUrl):void
    {
        if ($redirectUrl === '') {
            return;
        }
        //@ToDo: Do the redirect. Really ;-)
        die('Leite weiter zu ' . $redirectUrl);
    }

    /**
     * @return \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication
     */
    protected function getFeUser() {
        return $GLOBALS['TSFE']->fe_user;
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