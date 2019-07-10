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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
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
     * @var string
     */
    protected $redirectUrl = '';

    /**
     * @var RedirectUrlValidator
     */
    protected $redirectUrlValidator;

    /**
     * @var Request
     */
    protected $request;

    public function injectRedirectUrlValidator(RedirectUrlValidator $redirectUrlValidator): void
    {
        $this->redirectUrlValidator = $redirectUrlValidator;
    }

    public function process(array $settings, Request $request)
    {
        $this->loginType = (string)$this->getPropertyFromGetAndPost('logintype');
        $this->feUser = $GLOBALS['TSFE']->fe_user;
        $this->settings = $settings;
        $this->request = $request;
        $this->userIsLoggedIn = $this->isUserLoggedIn();

        $redirectMethods = GeneralUtility::trimExplode(',', $this->settings['redirectMode'] ?? '', true);

        return $this->processRedirect($redirectMethods);
    }

    /**
     * Process redirect methods. The function searches for a redirect url using all configured methods.
     *
     * @param array $redirectMethods
     * @return string Redirect URL
     */
    protected function processRedirect(array $redirectMethods): string
    {
        $redirectPageLogin = (int)($this->settings['redirectPageLogin'] ?? 0);
        $redirectPageLogout = (int)($this->settings['redirectPageLogout'] ?? 0);
        $isLoginTypeLogin = $this->loginType === LoginType::LOGIN;
        $isLoginTypeLogout = $this->loginType === LoginType::LOGOUT;
        $isLoginTypeEmpty = $this->loginType === '';

        if ($isLoginTypeLogin && $this->userIsLoggedIn === false && in_array('loginError', $redirectMethods, true)) {
            return $this->handleRedirectMethodLoginError();
        }

        $redirectUrlList = [];
        foreach ($redirectMethods as $redirMethod) {
            $isRedirMethodLogin = $redirMethod === 'login';
            $isRedirMethodLogout = $redirMethod === 'logout';
            $redirectUrl = '';

            if ($isLoginTypeLogin) {
                if ($this->userIsLoggedIn) {
                    // Logintype is needed because the login-page wouldn't be accessible anymore after a login (would always redirect)
                    switch ($redirMethod) {
                        case 'groupLogin':
                            $redirectUrl = $this->handleRedirectMethodGroupLogin();
                            break;
                        case 'userLogin':
                            $redirectUrl = $this->handRedirectMethodUserLogin();
                            break;
                        case 'login':
                            $redirectUrl = $this->handleRedirectMethodLogin($redirectPageLogin);
                            break;
                        case 'getpost':
                            $redirectUrl = $this->getRedirectUrlRequestParam();
                            break;
                        case 'referer':
                            $redirectUrl = $this->handleRedirectMethodReferer();
                            break;
                        case 'refererDomains':
                            $redirectUrl = $this->handleRedirectMethodRefererDomains();
                            break;
                    }
                }
            } elseif ($isLoginTypeEmpty) {
                // @todo: will not trigger an redirect because loginType is empty
                if ($isRedirMethodLogin && $redirectPageLogin) {
                    $redirectUrl = $this->createTypoLink($redirectPageLogin);
                } elseif ($isRedirMethodLogout && $redirectPageLogout && $this->userIsLoggedIn) {
                    // If logout and page not accessible
                    $redirectUrl = $this->createLink($redirectPageLogout);
                } elseif ($redirMethod === 'getpost') {
                    $redirectUrl = $this->handleRedirectMethodGetpost();
                }
            } elseif ($isLoginTypeLogout) {
                // TODO: add trigger for signals
                // after logout Hook for general actions after after logout has been confirmed
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['felogin']['logout_confirmed'] ?? [] as $_funcRef) {
                    $_params = [];
                    if ($_funcRef) {
                        GeneralUtility::callUserFunction($_funcRef, $_params, $this);
                    }
                }
                $redirectUrl = $this->handleRedirectMethodLogout($isRedirMethodLogout, $redirectPageLogout);
            }

            if ($redirectUrl !== '') {
                $redirectUrlList[] = '';
            }
        }

        return $this->fetchReturnUrlFromList($redirectUrlList);
    }

    protected function createLink(int $pageUid): string
    {
        return 'https://dummy.url/' . $pageUid;
    }

    protected function createTypoLink(int $redirectPageLogin)
    {
        // If login and page not accessible
        $this->cObj->typoLink(
            '', [
                  'parameter'                 => $redirectPageLogin,
                  'linkAccessRestrictedPages' => true
              ]
        );

        return $this->cObj->lastTypoLinkUrl;
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

    public function getRefererRequestParam(): string
    {
        $referer = '';
        $requestReferer = (string)$this->getPropertyFromGetAndPost('referer');
        if ($this->redirectUrlValidator->isValid($requestReferer)) {
            $referer = $requestReferer;
        }

        return $referer;
    }

    /**
     * Checks if a frontend user is logged in and the session is active.
     *
     * @return bool
     */
    protected static function isFrontendSession(): bool
    {
        return TYPO3_MODE === 'FE'
            && is_object($GLOBALS['TSFE'])
            && $GLOBALS['TSFE']->fe_user instanceof FrontendUserAuthentication
            && isset($GLOBALS['TSFE']->fe_user->user['uid']);
    }

    private function isUserLoggedIn(): bool
    {
        return (bool)GeneralUtility::makeInstance(Context::class)
            ->getPropertyFromAspect('frontend.user', 'isLoggedIn');
    }

    /**
     * handle redirect method groupLogin
     *
     * @return string
     */
    protected function handleRedirectMethodGroupLogin(): string
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
                $redirectUrl = $this->createLink($row['felogin_redirectPid']);
            }
        }

        return $redirectUrl;
    }

    /**
     * handle redirect method userLogin
     *
     * @return string
     */
    protected function handRedirectMethodUserLogin(): string
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
            $redirectUrl = $this->createLink($row['felogin_redirectPid']);
        }

        return $redirectUrl;
    }

    /**
     * handle redirect method login
     *
     * @param int $redirectPageLogin
     * @return string
     */
    protected function handleRedirectMethodLogin(int $redirectPageLogin): string
    {
        $redirectUrl = '';
        if ($redirectPageLogin !== 0) {
            $redirectUrl = $this->createLink($redirectPageLogin);
        }

        return $redirectUrl;
    }

    /**
     * handle redirect method referer
     *
     * @return string
     */
    protected function handleRedirectMethodReferer(): string
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
     * handle redirect method refererDomains
     *
     * @return string
     */
    protected function handleRedirectMethodRefererDomains(): string
    {
        $redirectReferrer = $this->request->hasArgument('redirectReferrer')
            ? $this->request->hasArgument('redirectReferrer')
            : '';

        if($redirectReferrer !== '') {
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
     * handle redirect method loginError
     *
     * @return string
     */
    protected function handleRedirectMethodLoginError(): string
    {
        // after login-error
        $redirectUrl = '';
        if ($this->settings['redirectPageLoginError']) {
            $redirectUrl = $this->createLink((int)$this->settings['redirectPageLoginError']);
        }

        return $redirectUrl;
    }

    /**
     * handle redirect method getpost
     *
     * @return string
     */
    protected function handleRedirectMethodGetpost(): string
    {
        // not logged in
        // Placeholder for maybe future options
        // Preserve the get/post value
        return $this->redirectUrl;
    }

    /**
     * handle redirect method logout
     *
     * @param bool $isRedirMethodLogout
     * @param int $redirectPageLogout
     * @return string
     */
    protected function handleRedirectMethodLogout(bool $isRedirMethodLogout, int $redirectPageLogout): string
    {
        $redirectUrl = '';
        if ($isRedirMethodLogout && $redirectPageLogout) {
            $redirectUrl = $this->createLink($redirectPageLogout);
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
}
