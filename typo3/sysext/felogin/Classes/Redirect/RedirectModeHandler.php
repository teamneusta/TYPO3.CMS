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

use PDO;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Felogin\Validation\RedirectUrlValidator;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Do felogin related redirects
 *
 * @internal
 */
class RedirectModeHandler
{
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

    /**
     * @var ServerRequestHandler
     */
    private $serverRequestHandler;

    public function __construct(UriBuilder $uriBuilder, ServerRequestHandler $serverRequestHandler)
    {
        $this->uriBuilder = $uriBuilder;
        $this->redirectUrlValidator = GeneralUtility::makeInstance(
            RedirectUrlValidator::class,
            GeneralUtility::makeInstance(SiteFinder::class),
            (int)$GLOBALS['TSFE']->id
        );
        $this->serverRequestHandler = $serverRequestHandler;
    }

    /**
     * initialize handler
     *
     * @param array $settings
     * @param Request $request
     */
    public function init(array $settings, Request $request): void
    {
        $this->feUser = $GLOBALS['TSFE']->fe_user;
        $this->settings = $settings;
        $this->request = $request;
    }

    /**
     * handle redirect mode groupLogin
     *
     * @return string
     */
    public function redirectModeGroupLogin(): string
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
                $redirectUrl = $this->generateUri((int)$row['felogin_redirectPid']);
            }
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode userLogin
     *
     * @return string
     */
    public function redirectModeUserLogin(): string
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
            $redirectUrl = $this->generateUri((int)$row['felogin_redirectPid']);
        }

        return $redirectUrl;
    }

    /**
     * handle redirect mode login
     *
     * @return string
     */
    public function redirectModeLogin(): string
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
    public function redirectModeReferer(): string
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
    public function redirectModeRefererDomains(): string
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
    public function redirectModeLoginError(): string
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
     * @return string
     */
    public function redirectModeLogout(): string
    {
        $redirectUrl = '';
        $redirectPageLogout = (int)($this->settings['redirectPageLogout'] ?? 0);
        if ($redirectPageLogout) {
            $redirectUrl = $this->generateUri($redirectPageLogout);
        }

        return $redirectUrl;
    }

    protected function generateUri(int $pageUid): string
    {
        $this->uriBuilder->reset();
        $this->uriBuilder->setTargetPageUid($pageUid);

        return $this->uriBuilder->build();
    }

    protected function getRefererRequestParam(): string
    {
        $referer = '';
        $requestReferer = (string)$this->serverRequestHandler->getPropertyFromGetAndPost('referer');
        if ($this->redirectUrlValidator->isValid($requestReferer)) {
            $referer = $requestReferer;
        }

        return $referer;
    }
}
