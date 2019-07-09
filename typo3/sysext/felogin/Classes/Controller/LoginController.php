<?php
declare(strict_types=1);

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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

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

        $this->view->assignMultiple(
            [
                'messageKey'       => $this->getStatusMessage($loginType, $isLoggedInd),
                'storagePid'       => $this->getStoragePid(),
                'permaloginStatus' => $this->getPermaloginStatus()
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

        $this->view->assignMultiple(
            [
                'user'             => $GLOBALS['TSFE']->fe_user->user ?? [],
                'showLoginMessage' => $showLoginMessage
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
                'user'       => $GLOBALS['TSFE']->fe_user->user ?? [],
                'storagePid' => $this->getStoragePid(),
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
        return (string)($this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK
            )['persistence']['storagePid'] ?? '');
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
     * handle forwards to overview and logout actions
     *
     * @param bool $userLoggedIn
     * @param string $loginType
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
        return $permaLogin > 1 || (int)($this->settings['showPermaLogin'] ?? 0) === 0 || $GLOBALS['TYPO3_CONF_VARS']['FE']['lifetime'] === 0;
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
     *
     * REDIRECT RELATED STUFF
     *
     */

    /**
     * Processes options for redirect after login and returns url
     * @ToDo: Refactor stuff from old piBase Extension, i.e. $this->logintype
     * @return string
     */
    private function processLoginRedirects():string
    {

        if ($this->dontRedirect()) {
            return '';
        }

        // Login error
        if ($this->logintype === LoginType::LOGIN && !$this->isUserLoggedIn()) {
            if (in_array('loginError', GeneralUtility::trimExplode(',', $this->conf['redirectMode'], true))) {
                return $this->conf['redirectPageLoginError'];
            }
        }

        // Successful login

        $redirectUrls = [];
        $modes =  $this->fetchRedirectModesFromConf();

        foreach($modes as $mode) {
            switch ($mode) {
                case 'groupLogin':
                    $redirectUrls[] = $this->fetchGroupRedirect();
                    break;
                case 'userLogin':
                    $redirectUrls[] = $this->fetchUserRedirect();
                    break;
                case 'login':
                    if ($this->conf['redirectPageLogin']) {
                        $redirectUrls[] = $this->pi_getPageLink((int)$this->conf['redirectPageLogin']);
                    }
                    break;
                case 'getpost':
                    $redirectUrls[] = $this->fetchGPRedirect();
                    break;
                case 'referer':
                case 'refererDomains':
                    $redirectUrls[] = $this->fetchRefererRedirect();
                    break;
            }
        }

        // Remove empty entries
        // What's that strlen f***?
        // @ToDo: Do we really want '0' as a valid return value?
        array_filter($redirectUrls, 'strlen');

        if (sizeof($redirectUrls) == 0) {
            return '';
        }

        // Return first or last entry
        return $this->conf['redirectFirstMethod'] ? array_shift($redirectUrls) : array_pop($redirectUrls);
    }



    /**
     * Processes options for redirect after logout and returns url
     * @return string
     */
    private function processLogoutRedirects():string
    {
        if ($this->dontRedirect()) {
            return '';
        }

        // Fill me in ;-)

        return '';
    }


    /**
     * Should this controller make use of possibly configured redirects at all?
     * @ToDo: Refactor! I.e. no piVars any more ;-)
     * @return bool
     */
    private function dontRedirect():bool
    {
        return $this->piVars['noredirect'] || $this->conf['redirectDisable'];
    }


    /**
     * Check if redirect url was sent via get/post vars
     * @ToDo: Refactor, replace piBase stuff
     * @return string
     */
    private function fetchGPRedirect(): string
    {

        if ($this->urlValidator->isValid((string)GeneralUtility::_GP('return_url'))) {
            return GeneralUtility::_GP('return_url');
        }
        if ($this->urlValidator->isValid((string)GeneralUtility::_GP('redirect_url'))) {
            return GeneralUtility::_GP('return_url');
        }

        return '';
    }

    /**
     * Directly taken from old pibase controller.
     * @ToDo: Refactor! I.e. $this->frontendController
     * @return string
     */
    private function fetchGroupRedirect():string
    {
        // taken from dkd_redirect_at_login written by Ingmar Schlecht; database-field changed
        $groupData = $this->frontendController->fe_user->groupData;
        if (!empty($groupData['uid'])) {

            // take the first group with a redirect page
            $userGroupTable = $this->frontendController->fe_user->usergroup_table;
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($userGroupTable);
            $queryBuilder->getRestrictions()->removeAll();
            $row = $queryBuilder
                ->select('felogin_redirectPid')
                ->from($userGroupTable)
                ->where(
                    $queryBuilder->expr()->neq(
                        'felogin_redirectPid',
                        $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
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
                return $this->pi_getPageLink($row['felogin_redirectPid']);
            }
        }

        return '';
    }

    /**
     * Directly taken from old pibase controller.
     * @ToDo: Refactor! I.e. $this->frontendController
     * @return string
     */
    private function fetchUserRedirect():string
    {
        $userTable = $this->frontendController->fe_user->user_table;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($userTable);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('felogin_redirectPid')
            ->from($userTable)
            ->where(
                $queryBuilder->expr()->neq(
                    'felogin_redirectPid',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    $this->frontendController->fe_user->userid_column,
                    $queryBuilder->createNamedParameter(
                        $this->frontendController->fe_user->user['uid'],
                        \PDO::PARAM_INT
                    )
                )
            )
            ->execute()
            ->fetch();

        if ($row) {
            return $this->pi_getPageLink($row['felogin_redirectPid']);
        }

        return '';
    }

    /**
     * @ToDo: Refactor! I.e. no more piVars ;-)
     * @return string
     */
    private function fetchRefererRedirect():string
    {
        // Don't redirect if 'redirectReferrer' is set (only possible value='off')
        if (isset($this->piVars['redirectReferrer'])) {
            return '';
        }

        $url = GeneralUtility::_GP('referer') ?: GeneralUtility::getIndpEnv('HTTP_REFERER');

        // @ToDo: Check, if we need explicit query of mode refererDomains here?
        if ($this->conf['domains']) {
            // Is referring url allowed to redirect?
            $match = [];
            if (preg_match('#^https?://([[:alnum:]._-]+)/#', $url, $match)) {
                $referer_domain = $match[1];
                $domainFound = false;
                foreach (GeneralUtility::trimExplode(',', $this->conf['domains'], true) as $domain) {
                    if (preg_match('/(?:^|\\.)' . $domain . '$/', $referer_domain)) {
                        $domainFound = true;
                        break;
                    }
                }
                if (!$domainFound) {
                    $url = '';
                }
            }
        }

        return  preg_replace('/[&?]logintype=[a-z]+/', '', $url);
    }


    /**
     * @ToDo: Replace piBase stuff
     * @return array
     */
    private function fetchRedirectModesFromConf():array
    {
        $modes = GeneralUtility::trimExplode(',', $this->conf['redirectMode'], true);

        // Clean array from referer, if both methods referer and refererDomain are set. Both do basically the same.
        if (in_array('referer', $modes) && in_array('refererDomains', $modes)) {
            if (($key = array_search('referer', $modes)) !== false) {
                unset($modes[$key]);
            }
        }

        return $modes;
    }
}
