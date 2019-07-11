<?php
declare(strict_types = 1);

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
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Felogin\Redirect\RedirectHandler;
use TYPO3\CMS\Felogin\Validation\RedirectUrlValidator;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use function array_merge;

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
     * @var RedirectUrlValidator
     */
    protected $redirectUrlValidator;

    /**
     * @var RedirectHandler
     */
    protected $subject;

    /**
     * @var ServerRequestInterface
     */
    protected $typo3Request;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @var SiteFinder
     */
    protected $siteFinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redirectUrlValidator = $this->prophesize(RedirectUrlValidator::class);
        $this->typo3Request = $this->prophesize(ServerRequestInterface::class);
        $this->uriBuilder = $this->prophesize(UriBuilder::class);
        $this->siteFinder = $this->prophesize(SiteFinder::class);

        GeneralUtility::addInstance(RedirectUrlValidator::class, $this->redirectUrlValidator->reveal());
        GeneralUtility::addInstance(SiteFinder::class, $this->siteFinder->reveal());

        $GLOBALS['TSFE'] = $this->prophesize(TypoScriptFrontendController::class)->reveal();
        $GLOBALS['TYPO3_REQUEST'] = $this->typo3Request->reveal();

        $this->subject = new RedirectHandler();
        $this->subject->injectUriBuilder($this->uriBuilder->reveal());
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
        $this->setLoginType(LoginType::LOGOUT);
        $request = $this->prophesize(Request::class);

        self::assertEquals($expect, $this->subject->process($settings, $request->reveal()));
    }

    public function loginTypeLogoutDataProvider(): Generator
    {
        yield 'empty string on empty redirect mode' => ['', ['redirectMode' => '']];
        yield 'empty string on redirect mode logout' => ['', ['redirectMode' => 'logout']];
//        yield 'empty string on redirect mode logout' => [
//            '',
//            [
//                'redirectMode' => 'logout',
//                'redirectPageLogout' => 10
//            ]
//        ];
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
        $this->setUserLoggedIn($userLoggedIn);
        $this->typo3Request
            ->getParsedBody()
            ->willReturn(
                array_merge(
                    ['logintype' => ''],
                    $body
                )
            );

        $this->redirectUrlValidator
            ->isValid(Argument::type('string'))
            ->willReturn(true);

        self::assertEquals($expected, $this->subject->getLogoutRedirectUrl($settings));
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
        yield 'redirect mode getpost should return param redirect_url on empty return_url' => [
            'https://dummy.url/2',
            ['redirectMode' => 'getpost'],
            ['return_url' => '', 'redirect_url' => 'https://dummy.url/2'],
            false
        ];
        yield 'redirect mode getpost,logout should return param return_url on not logged in user' => [
            'https://dummy.url/3',
            ['redirectMode' => 'getpost,logout'],
            ['return_url' => 'https://dummy.url/3'],
            false
        ];
        yield 'redirect mode logout should return empty url on logged in user and missing setting redirectPageLogout' => [
            '',
            ['redirectMode' => 'logout'],
            [],
            true
        ];
    }

    /**
     * @test
     */
    public function getLogoutRedirectUrlShouldReturnAlternativeRedirectUrlForLoggedInUserAndRedirectPageLogoutSet(
    ): void
    {
        $settings = ['redirectMode' => 'getpost,logout', 'redirectPageLogout' => 10];
        $this->setUserLoggedIn(true);
        $this->typo3Request
            ->getParsedBody()
            ->willReturn(
                [
                    'logintype'  => '',
                    'return_url' => 'https://dummy.url/'
                ]
            );

        $this->redirectUrlValidator
            ->isValid(Argument::type('string'))
            ->willReturn(true);

        $this->uriBuilder->reset()->shouldBeCalled();
        $this->uriBuilder->setTargetPageUid(10)->shouldBeCalled();
        $this->uriBuilder->build()->willReturn('https://valid.url/');

        self::assertEquals('https://valid.url/', $this->subject->getLogoutRedirectUrl($settings));
    }

    /**
     * @test
     * @dataProvider getLoginRedirectUrlDataProvider
     * @param string $expected
     * @param array $settings
     * @param array $body
     */
    public function getLoginRedirectUrlShouldReturnAlternativeRedirectUrl(
        string $expected,
        array $settings,
        array $body
    ): void {
        $this->typo3Request
            ->getParsedBody()
            ->willReturn(
                array_merge(
                    ['logintype' => ''],
                    $body
                )
            );

        $this->redirectUrlValidator
            ->isValid(Argument::type('string'))
            ->willReturn(true);

        self::assertEquals($expected, $this->subject->getLoginRedirectUrl($settings));
    }

    public function getLoginRedirectUrlDataProvider(): Generator
    {
        yield 'empty redirect mode should return empty returnUrl' => ['', ['redirectMode' => ''], [], false];
        yield 'redirect mode getpost should return param return_url' => [
            'https://dummy.url',
            ['redirectMode' => 'getpost'],
            ['return_url' => 'https://dummy.url']
        ];
        yield 'redirect mode login should return empty url on missing setting redirectPageLogin' => [
            '',
            ['redirectMode' => 'login'],
            []
        ];
    }

    /**
     * @test
     */
    public function getLoginRedirectUrlShouldReturnRedirectUrlOnRedirectModeLoginAndValidRedirectPageLogin(): void
    {
        $settings = ['redirectMode' => 'getpost,login', 'redirectPageLogin' => 5];
        $this->typo3Request
            ->getParsedBody()
            ->willReturn(
                ['logintype' => '', 'return_url' => 'https://dummy.url/']
            );

        $this->redirectUrlValidator
            ->isValid(Argument::type('string'))
            ->willReturn(true);

        $this->uriBuilder->reset()->shouldBeCalled();
        $this->uriBuilder->setTargetPageUid(5)->shouldBeCalled();
        $this->uriBuilder->setLinkAccessRestrictedPages(true)->shouldBeCalled();
        $this->uriBuilder->build()->willReturn('https://valid.url/');

        self::assertEquals('https://valid.url/', $this->subject->getLoginRedirectUrl($settings));
    }

    protected function setLoginType(string $loginType = LoginType::LOGIN): void
    {
        $this->typo3Request
            ->getParsedBody()
            ->willReturn(
                [
                    'logintype' => $loginType
                ]
            );
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
