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

use TYPO3\CMS\Core\Type\Enumeration;

/**
 * Contains the different redirect modes types
 */
final class RedirectMode extends Enumeration
{
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const LOGIN_ERROR = 'loginError';
    public const GETPOST = 'getpost';
    public const USER_LOGIN = 'userLogin';
    public const GROUP_LOGIN = 'groupLogin';
    public const REFERER = 'referer';
    public const REFERER_DOMAINS = 'refererDomains';
}
