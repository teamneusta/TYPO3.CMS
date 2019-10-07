<?php
declare(strict_types=1);

namespace TYPO3\CMS\Felogin\Tests\Unit\Controller;

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

use Generator;
use Prophecy\Argument;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Request;
use TYPO3\CMS\Felogin\Redirect\RedirectHandler;
use TYPO3\CMS\Felogin\Redirect\RedirectModeHandler;
use TYPO3\CMS\Felogin\Redirect\ServerRequestHandler;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case
 */
class RedirectHandlerTest extends UnitTestCase
{
    /**
     * If set to true, tearDown() will purge singleton instances created by the test.
     *
     * @var bool
     */
    protected $resetSingletonInstances = true;

    /**
     * @var RedirectHandler
     */
    protected $subject;

    /**
     * @var ServerRequestInterface
     */
    protected $typo3Request;

    /**
     * @var ServerRequestHandler
     */
    protected $serverRequestHandler;

    /**
     * @var RedirectModeHandler
     */
    protected $redirectModeHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverRequestHandler = $this->prophesize(ServerRequestHandler::class);
        $this->redirectModeHandler = $this->prophesize(RedirectModeHandler::class);

        $GLOBALS['TSFE'] = $this->prophesize(TypoScriptFrontendController::class)->reveal();

        $this->subject = new RedirectHandler(
            $this->serverRequestHandler->reveal(),
            $this->redirectModeHandler->reveal()
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TSFE'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * @test
     * @dataProvider loginTypeLogoutDataProvider
     * @param string $expect
     * @param array $settings
     */
    public function processShouldReturnStringForLoginTypeLogout(string $expect, array $settings): void
    {
        $request = $this->prophesize(Request::class);

        $this->subject->init(LoginType::LOGOUT, $settings, $request->reveal());

        $this->redirectModeHandler->redirectModeLogout()->willReturn('');

        self::assertEquals($expect, $this->subject->processRedirect());
    }

    public function loginTypeLogoutDataProvider(): Generator
    {
        yield 'empty string on empty redirect mode' => ['', ['redirectMode' => '']];
        yield 'empty string on redirect mode logout' => ['', ['redirectMode' => 'logout']];
    }

    /**
     * @test
     * @dataProvider getLogoutRedirectUrlDataProvider
     * @param string $expected
     * @param array $settings
     * @param array $body
     * @param bool $userLoggedIn
     */
    public function getLogoutRedirectUrlShouldReturnAlternativeRedirectUrl(
        string $expected,
        array $settings,
        array $body,
        bool $userLoggedIn
    ): void {
        $loginType = '';
        $this->setUserLoggedIn($userLoggedIn);

        $this->serverRequestHandler
            ->getRedirectUrlRequestParam()
            ->willReturn($body['return_url'] ?? '');

        $this->subject->init($loginType, $settings, $this->prophesize(Request::class)->reveal());

        self::assertEquals($expected, $this->subject->getLogoutRedirectUrl());
    }

    public function getLogoutRedirectUrlDataProvider(): Generator
    {
        yield 'empty redirect mode should return empty returnUrl' => ['', ['redirectMode' => ''], [], false];
        yield 'redirect mode getpost should return param return_url' => [
            'https://dummy.url',
            ['redirectMode' => 'getpost'],
            ['return_url' => 'https://dummy.url'],
            false
        ];
        yield 'redirect mode getpost,logout should return param return_url on not logged in user' => [
            'https://dummy.url/3',
            ['redirectMode' => 'getpost,logout'],
            ['return_url' => 'https://dummy.url/3'],
            false
        ];
    }

    /**
     * @test
     */
    public function getLogoutRedirectUrlShouldReturnAlternativeRedirectUrlForLoggedInUserAndRedirectPageLogoutSet(
    ): void
    {
        $loginType = '';
        $settings = ['redirectMode' => 'logout'];
        $this->setUserLoggedIn(true);

        $this->serverRequestHandler
            ->getRedirectUrlRequestParam()
            ->willReturn([]);

        $this->redirectModeHandler
            ->redirectModeLogout()
            ->willReturn('https://logout.url');

        $this->redirectModeHandler
            ->init(Argument::type('array'), Argument::type(Request::class))
            ->shouldBeCalled();

        $this->subject->init($loginType, $settings, $this->prophesize(Request::class)->reveal());

        self::assertEquals('https://logout.url', $this->subject->getLogoutRedirectUrl());
    }

    protected function setUserLoggedIn(bool $userLoggedIn): void
    {
        $userAspect = $this->prophesize(UserAspect::class);
        $userAspect
            ->get('isLoggedIn')
            ->willReturn($userLoggedIn);
        GeneralUtility::makeInstance(Context::class)->setAspect('frontend.user', $userAspect->reveal());
    }
}
