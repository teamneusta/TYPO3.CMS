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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Felogin\Redirect\RedirectHandler;
use TYPO3\CMS\Felogin\Redirect\ServerRequestHandler;
use TYPO3\CMS\Felogin\Service\TreeUidListProvider;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

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
    protected $loginType = '';

    /**
     * @var TreeUidListProvider
     */
    private $treeUidListProvider;

    /**
     * @var ServerRequestHandler
     */
    private $requestHandler;

    public function __construct(
        RedirectHandler $redirectHandler,
        TreeUidListProvider $treeUidListProvider,
        ServerRequestHandler $requestHandler
    ) {
        $this->redirectHandler = $redirectHandler;
        $this->treeUidListProvider = $treeUidListProvider;
        $this->requestHandler = $requestHandler;
    }

    /**
     * initialize redirects
     */
    public function initializeAction(): void
    {
        $this->loginType = (string)$this->requestHandler->getPropertyFromGetAndPost('logintype');
        $this->redirectHandler->init($this->loginType, $this->settings, $this->request);

        if ($this->isLoginOrLogoutInProgress() && !$this->isRedirectDisabled()) {
            $redirectUrl = $this->redirectHandler->processRedirect();

            if (!$this->getFeUser()->isCookieSet() && $this->isUserLoggedIn()) {
                $this->view->assign('cookieWarning', true);
            } elseif ($redirectUrl !== '') {
                $this->redirectToUri($redirectUrl);
            }
        }
    }

    /**
     * show login form
     */
    public function loginAction(): void
    {
        $this->handleLoginForwards();

        $redirectUrl = $this->requestHandler->getRedirectUrlRequestParam();
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
                'referer'          => $this->requestHandler->getPropertyFromGetAndPost('referer'),
                'noRedirect'       => $this->isRedirectDisabled(),
            ]
        );
    }

    /**
     * user overview for logged in users
     *
     * @param bool $showLoginMessage
     */
    public function overviewAction(bool $showLoginMessage = false): void
    {
        if (!$this->isUserLoggedIn()) {
            $this->forward('login');
        }

        $this->emitLoginConfirmed();

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
        $actionUri = $this->requestHandler->getRedirectUrlRequestParam();
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

    protected function getRedirectReferrer(): string
    {
        return $this->request->hasArgument('redirectReferrer')
            ? (string)$this->request->getArgument('redirectReferrer')
            : '';
    }

    /**
     * returns the parsed storagePid list including recursions
     *
     * @return string
     */
    protected function getStoragePid(): string
    {
        return $this->treeUidListProvider->getListForIdList(
            (string)$this->settings['pages'],
            (int)$this->settings['recursive']
        );
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
        return $permaLogin > 1
            || (int)($this->settings['showPermaLogin'] ?? 0) === 0
            || $GLOBALS['TYPO3_CONF_VARS']['FE']['lifetime'] === 0;
    }

    /**
     * redirect to overview on login successful and setting showLogoutFormAfterLogin disabled
     *
     * @return bool
     */
    protected function shouldRedirectToOverview(): bool
    {
        return $this->isUserLoggedIn()
            && ($this->loginType === LoginType::LOGIN)
            && !($this->settings['showLogoutFormAfterLogin'] ?? 0);
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

    protected function getFeUser(): FrontendUserAuthentication
    {
        return $GLOBALS['TSFE']->fe_user;
    }

    /**
     * Should this controller make use of possibly configured redirects at all?
     *
     * @return bool
     */
    protected function isRedirectDisabled(): bool
    {
        return
            $this->request->hasArgument('noredirect')
            || $this->settings['noredirect']
            || $this->settings['redirectDisable'];
    }

    protected function emitLoginConfirmed(): void
    {
        $this->getSignalSlotDispatcher()->dispatch(__CLASS__, 'login_confirmed');
    }

    protected function getSignalSlotDispatcher(): Dispatcher
    {
        static $signalSlotDispatcher;

        if ($signalSlotDispatcher === null) {
            $signalSlotDispatcher = $this->objectManager->get(Dispatcher::class);
        }

        return $signalSlotDispatcher;
    }

    /**
     * @return bool
     */
    protected function isLoginOrLogoutInProgress(): bool
    {
        return ($this->loginType === LoginType::LOGIN || $this->loginType === LoginType::LOGOUT);
    }
}
