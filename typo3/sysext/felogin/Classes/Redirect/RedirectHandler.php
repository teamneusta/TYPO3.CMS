<?php
declare(strict_types = 1);

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

use PDO;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Felogin\Validation\RedirectUrlValidator;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use function in_array;

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
     * @var FrontendUserAuthentication
     */
    protected $feUser;

    /**
     * @var array
     */
    protected $settings = [];

    /**
     * @var RedirectUrlValidator
     */
    protected $redirectUrlValidator;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @var array
     */
    protected $redirectModes = [];

    public function __construct(UriBuilder $uriBuilder)
    {
        $this->uriBuilder = $uriBuilder;
        $this->redirectUrlValidator = GeneralUtility::makeInstance(
            RedirectUrlValidator::class,
            GeneralUtility::makeInstance(SiteFinder::class),
            (int)$GLOBALS['TSFE']->id
        );
    }

    /**
     * initialize handler
     *
     * @param array $settings
     * @param Request $request
     */
    public function init(array $settings, Request $request): void
    {
        $this->loginType = (string)$this->getPropertyFromGetAndPost('logintype');
        $this->feUser = $GLOBALS['TSFE']->fe_user;
        $this->settings = $settings;
        $this->request = $request;
        $this->userIsLoggedIn = $this->isUserLoggedIn();
        $this->redirectModes = $this->extractRedirectModesFromSettings();
    }

    /**
     * Process redirect modes. The function searches for a redirect url using all configured modes.
     *
     * @return string Redirect URL
     */
    public function processRedirect(): string
    {
        if ($this->isUserLoginFailedAndLoginErrorActive()) {
            return $this->handleRedirectModeLoginError();
        }

        $redirectUrlList = [];
        foreach ($this->redirectModes as $redirectMode) {
            $redirectUrl = '';

            if ($this->loginType === LoginType::LOGIN) {
                $redirectUrl = $this->handleSuccessfulLogin($redirectMode);
            } elseif ($this->loginType === LoginType::LOGOUT) {
                // TODO: after logout signal for general actions after after logout has been confirmed
                $redirectUrl = $this->handleSuccessfulLogout($redirectMode);
            }

            if ($redirectUrl !== '') {
                $redirectUrlList[] = $redirectUrl;
            }
        }

        return $this->fetchReturnUrlFromList($redirectUrlList);
    }

    /**
     * get alternative logout form redirect url if logout and page not accessible
     *
     * @return string
     */
    public function getLogoutRedirectUrl(): string
    {
        $redirectUrl = $this->getGetpostRedirectUrl();
        $redirectPageLogout = (int)($this->settings['redirectPageLogout'] ?? 0);
        if ($redirectPageLogout && $this->isRedirectModeActive('logout') && $this->isUserLoggedIn()) {
            $redirectUrl = $this->generateUri($redirectPageLogout);
        }

        return $redirectUrl;
    }

    /**
     * get alternative login form redirect url
     *
     * @return string
     */
    public function getLoginRedirectUrl(): string
    {
        $redirectUrl = $this->getGetpostRedirectUrl();
        $redirectPageLogin = (int)($this->settings['redirectPageLogin'] ?? 0);
        if ($redirectPageLogin && $this->isRedirectModeActive('login')) {
            $redirectUrl = $this->generateUriWihtRestrictedPages($redirectPageLogin);
        }

        return $redirectUrl;
    }

    /**
     * is used for alternative redirect urls on redirect mode getpost
     * Placeholder for maybe future options
     * Preserve the get/post value
     *
     * @return string
     */
    protected function getGetpostRedirectUrl(): string
    {
        return $this->isRedirectModeActive('getpost')
            ? $this->getRedirectUrlRequestParam()
            : '';
    }

    protected function generateUri(int $pageUid): string
    {
        $this->uriBuilder->reset();
        $this->uriBuilder->setTargetPageUid($pageUid);

        return $this->uriBuilder->build();
    }

    protected function generateUriWihtRestrictedPages(int $pageUid): string
    {
        $this->uriBuilder->reset();
        $this->uriBuilder->setLinkAccessRestrictedPages(true);
        $this->uriBuilder->setTargetPageUid($pageUid);

        return $this->uriBuilder->build();
    }

    /**
     * returns validated redirect url cointained in request param return_url or redirect_url
     *
     * @return string
     */
    public function getRedirectUrlRequestParam(): string
    {
        // If config.typolinkLinkAccessRestrictedPages is set, the var is return_url
        $redirectUrl = (string)$this->getPropertyFromGetAndPost('return_url')
            ?: (string)$this->getPropertyFromGetAndPost('redirect_url');

        return $this->redirectUrlValidator->isValid($redirectUrl) ? $redirectUrl : '';
    }

    protected function getRefererRequestParam(): string
    {
        $referer = '';
        $requestReferer = (string)$this->getPropertyFromGetAndPost('referer');
        if ($this->redirectUrlValidator->isValid($requestReferer)) {
            $referer = $requestReferer;
        }

        return $referer;
    }

    protected function isUserLoggedIn(): bool
    {
        return (bool)GeneralUtility::makeInstance(Context::class)
            ->getPropertyFromAspect('frontend.user', 'isLoggedIn');
    }

    /**
     * handle redirect mode groupLogin
     *
     * @return string
     */
    protected function handleRedirectModeGroupLogin(): string
    {
        // taken from dkd_redirect_at_login written by Ingmar Schlecht; database-field changed
        $redirectUrl = '';
        $groupData = $this->feUser->groupData;
        if (!empty($groupData['uid'])) {
            // take the first group with a redirect page
            $userGroupTable = $this->feUser->usergroup_table;
            $queryBuilder = GeneralUtility::makeInstance(
                ConnectionPool::class
            )->getQueryBuilderForTable($userGroupTable);
            $queryBuilder->getRestrictions()->removeAll();
            $row = $queryBuilder
                ->select('felogin_redirectPid')
                ->from($userGroupTable)
                ->where(
                    $queryBuilder->expr()->neq(
                        'felogin_redirectPid',
                        $queryBuilder->createNamedParameter('', PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->in(
                        'uid',
                        $queryBuilder->createNamedParameter(
                            $groupData['uid'],
                            Connection::PARAM_INT_ARRAY
                        )
                    )
                )
                ->execute()
                ->fetch();

            if ($row) {
                $redirectUrl = $this->generateUri($row['felogin_redirectPid']);
            }
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode userLogin
     *
     * @return string
     */
    protected function handleRedirectModeUserLogin(): string
    {
        $redirectUrl = '';
        $userTable = $this->feUser->user_table;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(
            $userTable
        );
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('felogin_redirectPid')
            ->from($userTable)
            ->where(
                $queryBuilder->expr()->neq(
                    'felogin_redirectPid',
                    $queryBuilder->createNamedParameter('')
                ),
                $queryBuilder->expr()->eq(
                    $this->feUser->userid_column,
                    $queryBuilder->createNamedParameter(
                        $this->feUser->user['uid'],
                        PDO::PARAM_INT
                    )
                )
            )
            ->execute()
            ->fetch();

        if ($row) {
            $redirectUrl = $this->generateUri($row['felogin_redirectPid']);
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode login
     *
     * @return string
     */
    protected function handleRedirectModeLogin(): string
    {
        $redirectUrl = '';
        $redirectPageLogin = (int)($this->settings['redirectPageLogin'] ?? 0);
        if ($redirectPageLogin !== 0) {
            $redirectUrl = $this->generateUri($redirectPageLogin);
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode referer
     *
     * @return string
     */
    protected function handleRedirectModeReferer(): string
    {
        // Avoid redirect when logging in after changing password
        $redirectUrl = '';
        $redirectReferrer = $this->request->hasArgument('redirectReferrer')
            ? $this->request->getArgument('redirectReferrer')
            : '';

        if ($redirectReferrer !== 'off') {
            // Avoid forced logout, when trying to login immediately after a logout
            $redirectUrl = preg_replace('/[&?]logintype=[a-z]+/', '', $this->getRefererRequestParam());
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode refererDomains
     *
     * @return string
     */
    protected function handleRedirectModeRefererDomains(): string
    {
        $redirectReferrer = $this->request->hasArgument('redirectReferrer')
            ? $this->request->getArgument('redirectReferrer')
            : '';

        if ($redirectReferrer !== '') {
            return '';
        }

        $redirectUrl = '';
        // Auto redirect.
        // Feature to redirect to the page where the user came from (HTTP_REFERER).
        // Allowed domains to redirect to, can be configured with plugin.tx_felogin_pi1.domains
        // Thanks to plan2.net / Martin Kutschker for implementing this feature.
        // also avoid redirect when logging in after changing password
        if (isset($this->settings['domains']) && $this->settings['domains']) {
            $url = $this->getRefererRequestParam();
            // Is referring url allowed to redirect?
            $match = [];
            if (preg_match('#^http://([[:alnum:]._-]+)/#', $url, $match)) {
                $redirect_domain = $match[1];
                $found = false;
                foreach (GeneralUtility::trimExplode(',', $this->settings['domains'], true) as $d) {
                    if (preg_match('/(?:^|\\.)' . $d . '$/', $redirect_domain)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $url = '';
                }
            }
            // Avoid forced logout, when trying to login immediately after a logout
            if ($url) {
                $redirectUrl = preg_replace('/[&?]logintype=[a-z]+/', '', $url);
            }
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode loginError
     * after login-error
     *
     * @return string
     */
    protected function handleRedirectModeLoginError(): string
    {
        $redirectUrl = '';
        if ($this->settings['redirectPageLoginError']) {
            $redirectUrl = $this->generateUri((int)$this->settings['redirectPageLoginError']);
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode logout
     *
     * @param string $redirectMode
     * @return string
     */
    protected function handleSuccessfulLogout(string $redirectMode): string
    {
        $redirectUrl = '';
        $redirectPageLogout = (int)($this->settings['redirectPageLogout'] ?? 0);
        if ($redirectMode === 'logout' && $redirectPageLogout) {
            $redirectUrl = $this->generateUri($redirectPageLogout);
        }

        return $redirectUrl;
    }

    /**
     * base on setting redirectFirstMethod get first or last entry from redirect url list.
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

    protected function isRedirectModeActive(string $mode): bool
    {
        return in_array($mode, $this->redirectModes, true);
    }

    protected function extractRedirectModesFromSettings(): array
    {
        return GeneralUtility::trimExplode(',', $this->settings['redirectMode'] ?? '', true);
    }

    /**
     * generate redirect_url for case that the user was successfuly logged in
     *
     * @param string $redirectMode
     * @return string
     */
    protected function handleSuccessfulLogin(string $redirectMode): string
    {
        if ($this->userIsLoggedIn) {
            return '';
        }

        // Logintype is needed because the login-page wouldn't be accessible anymore after a login (would always redirect)
        switch ($redirectMode) {
            case 'groupLogin':
                $redirectUrl = $this->handleRedirectModeGroupLogin();
                break;
            case 'userLogin':
                $redirectUrl = $this->handleRedirectModeUserLogin();
                break;
            case 'login':
                $redirectUrl = $this->handleRedirectModeLogin();
                break;
            case 'getpost':
                $redirectUrl = $this->getRedirectUrlRequestParam();
                break;
            case 'referer':
                $redirectUrl = $this->handleRedirectModeReferer();
                break;
            case 'refererDomains':
                $redirectUrl = $this->handleRedirectModeRefererDomains();
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
            && $this->isRedirectModeActive('loginError');
    }
}
