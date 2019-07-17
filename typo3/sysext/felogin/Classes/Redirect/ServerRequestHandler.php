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
use Psr\Http\Message\ServerRequestInterface;
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
 * Handels all kind of
 *
 * @internal
 */
class ServerRequestHandler
{
    /**
     * @var RedirectUrlValidator
     */
    protected $redirectUrlValidator;

    /**
     * @var ServerRequestInterface
     */
    private $request;

    public function __construct()
    {
        // todo: refactor when extbase handles PSR-15 requests
        $this->request = $GLOBALS['TYPO3_REQUEST'];
        $this->redirectUrlValidator = GeneralUtility::makeInstance(
            RedirectUrlValidator::class,
            GeneralUtility::makeInstance(SiteFinder::class),
            (int)$GLOBALS['TSFE']->id
        );
    }

    /**
     * returns a property that exists in post or get context
     *
     * @param string $propertyName
     * @return mixed|null
     */
    public function getPropertyFromGetAndPost(string $propertyName)
    {
        return $this->request->getParsedBody()[$propertyName] ?? $this->request->getQueryParams()[$propertyName] ?? null;
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
}
