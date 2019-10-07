<?php
declare(strict_types=1);

namespace TYPO3\CMS\Felogin\Redirect;

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
use TYPO3\CMS\Extbase\Mvc\Request;

/**
 * Do felogin related redirects
 *
 * @internal
 */
class RedirectHandler
{
    /**
     * @var bool
     */
    protected $userIsLoggedIn = false;

    /**
     * @var string
     */
    protected $loginType = '';

    /**
     * @var array
     */
    protected $settings = [];

    /**
     * @var array
     */
    protected $redirectModes = [];

    /**
     * @var ServerRequestHandler
     */
    protected $requestHandler;

    /**
     * @var RedirectModeHandler
     */
    protected $redirectModeHandler;

    public function __construct(
        ServerRequestHandler $requestHandler,
        RedirectModeHandler $redirectModeHandler
    ) {
        $this->requestHandler = $requestHandler;
        $this->redirectModeHandler = $redirectModeHandler;
    }

    /**
     * Initialize handler
     *
     * @param string $loginType
     * @param array $settings
     * @param Request $request
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     */
    public function init(string $loginType, array $settings, Request $request): void
    {
        $this->loginType = $loginType;
        $this->settings = $settings;
        $this->userIsLoggedIn = (bool)GeneralUtility::makeInstance(Context::class)
            ->getPropertyFromAspect('frontend.user', 'isLoggedIn');
        $this->redirectModes = $this->extractRedirectModesFromSettings();

        $this->redirectModeHandler->init($settings, $request);
    }

    /**
     * Process redirect modes. The function searches for a redirect url using all configured modes.
     *
     * @return string Redirect URL
     */
    public function processRedirect(): string
    {
        if ($this->isUserLoginFailedAndLoginErrorActive()) {
            return $this->redirectModeHandler->redirectModeLoginError();
        }

        $redirectUrlList = [];
        foreach ($this->redirectModes as $redirectMode) {
            $redirectUrl = '';

            if ($this->loginType === LoginType::LOGIN) {
                $redirectUrl = $this->handleSuccessfulLogin($redirectMode);
            } elseif ($this->loginType === LoginType::LOGOUT) {
                $redirectUrl = $this->handleSuccessfulLogout($redirectMode);
            }

            if ($redirectUrl !== '') {
                $redirectUrlList[] = $redirectUrl;
            }
        }

        return $this->fetchReturnUrlFromList($redirectUrlList);
    }

    /**
     * Get alternative logout form redirect url if logout and page not accessible
     *
     * @return string
     */
    public function getLogoutRedirectUrl(): string
    {
        $redirectUrl = $this->getGetpostRedirectUrl();
        if ($this->isRedirectModeActive(RedirectMode::LOGOUT) && $this->userIsLoggedIn) {
            $redirectUrl = $this->redirectModeHandler->redirectModeLogout();
        }

        return $redirectUrl;
    }

    /**
     * Get alternative login form redirect url
     *
     * @return string
     */
    public function getLoginRedirectUrl(): string
    {
        $redirectUrl = $this->getGetpostRedirectUrl();
        if ($this->isRedirectModeActive(RedirectMode::LOGIN)) {
            $redirectUrl = $this->redirectModeHandler->redirectModeLogin();
        }

        return $redirectUrl;
    }

    /**
     * Is used for alternative redirect urls on redirect mode getpost
     * Preserve the get/post value
     *
     * @return string
     */
    protected function getGetpostRedirectUrl(): string
    {
        return $this->isRedirectModeActive(RedirectMode::GETPOST)
            ? $this->requestHandler->getRedirectUrlRequestParam()
            : '';
    }

    /**
     * Handle redirect mode logout
     *
     * @param string $redirectMode
     * @return string
     */
    protected function handleSuccessfulLogout(string $redirectMode): string
    {
        if ($redirectMode === RedirectMode::LOGOUT) {
            $redirectUrl = $this->redirectModeHandler->redirectModeLogout();
        }

        return $redirectUrl ?? '';
    }

    /**
     * Base on setting redirectFirstMethod get first or last entry from redirect url list.
     *
     * @param array $redirectUrlList
     * @return string
     */
    protected function fetchReturnUrlFromList(array $redirectUrlList): string
    {
        if (count($redirectUrlList) === 0) {
            return '';
        }

        // Remove empty values, but keep "0" as value (that's why "strlen" is used as second parameter)
        $redirectUrlList = array_filter($redirectUrlList, 'strlen');

        return $this->settings['redirectFirstMethod']
            ? array_shift($redirectUrlList)
            : array_pop($redirectUrlList);
    }

    protected function extractRedirectModesFromSettings(): array
    {
        return GeneralUtility::trimExplode(',', $this->settings['redirectMode'] ?? '', true);
    }

    /**
     * Generate redirect_url for case that the user was successfuly logged in
     *
     * @param string $redirectMode
     * @return string
     */
    protected function handleSuccessfulLogin(string $redirectMode): string
    {
        if (!$this->userIsLoggedIn) {
            return '';
        }

        // Logintype is needed because the login-page wouldn't be accessible anymore after a login (would always redirect)
        switch ($redirectMode) {
            case RedirectMode::GROUP_LOGIN:
                $redirectUrl = $this->redirectModeHandler->redirectModeGroupLogin();
                break;
            case RedirectMode::USER_LOGIN:
                $redirectUrl = $this->redirectModeHandler->redirectModeUserLogin();
                break;
            case RedirectMode::LOGIN:
                $redirectUrl = $this->redirectModeHandler->redirectModeLogin();
                break;
            case RedirectMode::GETPOST:
                $redirectUrl = $this->requestHandler->getRedirectUrlRequestParam();
                break;
            case RedirectMode::REFERER:
                $redirectUrl = $this->redirectModeHandler->redirectModeReferrer();
                break;
            case RedirectMode::REFERER_DOMAINS:
                $redirectUrl = $this->redirectModeHandler->redirectModeRefererDomains();
                break;
            default:
                $redirectUrl = '';
        }

        return $redirectUrl;
    }

    protected function isUserLoginFailedAndLoginErrorActive(): bool
    {
        return $this->loginType === LoginType::LOGIN
            && $this->userIsLoggedIn === false
            && $this->isRedirectModeActive(RedirectMode::LOGIN_ERROR);
    }

    public function isRedirectModeActive(string $mode): bool
    {
        return in_array($mode, $this->redirectModes, true);
    }
}
