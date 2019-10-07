<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Frontend\Tests\Functional\Configuration\TypoScript\ConditionMatching;

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

use Prophecy\Argument;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Configuration\TypoScript\ConditionMatching\ConditionMatcher;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional test for the ConditionMatcher of EXT:frontend
 */
class ConditionMatcherTest extends FunctionalTestCase
{
    /**
     * Sets up this test case.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest();

        $this->importDataSet('PACKAGE:typo3/testing-framework/Resources/Core/Functional/Fixtures/pages.xml');
        $this->setupFrontendController(3);
    }

    /**
     * Tests whether usergroup comparison matches.
     *
     * @test
     */
    public function usergroupConditionMatchesSingleGroupId(): void
    {
        $this->setupFrontendUserContext([13]);
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[usergroup(13)]'));
        $this->assertTrue($subject->match('[usergroup("13")]'));
        $this->assertTrue($subject->match('[usergroup(\'13\')]'));
    }

    /**
     * Tests whether usergroup comparison matches.
     *
     * @test
     */
    public function usergroupConditionMatchesMultipleUserGroupId(): void
    {
        $this->setupFrontendUserContext([13, 14, 15]);
        $subject = $this->getConditionMatcher();
        $this->assertFalse($subject->match('[usergroup(999,15,14,13)]'));
        $this->assertTrue($subject->match('[usergroup("999,15,14,13")]'));
        $this->assertTrue($subject->match('[usergroup(\'999,15,14,13\')]'));
    }

    /**
     * Tests whether usergroup comparison matches.
     *
     * @test
     */
    public function usergroupConditionDoesNotMatchDefaulUserGroupIds(): void
    {
        $this->setupFrontendUserContext([0, -1]);
        $subject = $this->getConditionMatcher();
        $this->assertFalse($subject->match('[usergroup("0,-1")]'));
        $this->assertFalse($subject->match('[usergroup(\'0,-1\')]'));
    }

    /**
     * Tests whether user comparison matches.
     *
     * @test
     */
    public function loginUserConditionMatchesAnyLoggedInUser(): void
    {
        $this->setupFrontendUserContext([13]);
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[loginUser("*")]'));
        $this->assertTrue($subject->match('[loginUser(\'*\')]'));
    }

    /**
     * Tests whether user comparison matches.
     *
     * @test
     */
    public function loginUserConditionMatchesSingleLoggedInUser(): void
    {
        $this->setupFrontendUserContext([13, 14, 15]);
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[loginUser(13)]'));
        $this->assertTrue($subject->match('[loginUser("13")]'));
        $this->assertTrue($subject->match('[loginUser(\'13\')]'));
    }

    /**
     * Tests whether user comparison matches.
     *
     * @test
     */
    public function loginUserConditionMatchesMultipleLoggedInUsers(): void
    {
        $this->setupFrontendUserContext([13, 14, 15]);
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[loginUser("999,13")]'));
        $this->assertTrue($subject->match('[loginUser(\'999,13\')]'));
    }

    /**
     * Tests whether user comparison matches.
     *
     * @test
     */
    public function loginUserConditionDoesNotMatchIfNotUserIsLoggedId(): void
    {
        $this->setupFrontendUserContext();
        $subject = $this->getConditionMatcher();
        $this->assertFalse($subject->match('[loginUser("*")]'));
        $this->assertTrue($subject->match('[loginUser("*") == false]'));
        $this->assertFalse($subject->match('[loginUser("13")]'));
        $this->assertFalse($subject->match('[loginUser(\'*\')]'));
        $this->assertFalse($subject->match('[loginUser(\'13\')]'));
    }

    /**
     * Tests whether user is not logged in
     *
     * @test
     */
    public function loginUserConditionMatchIfUserIsNotLoggedIn(): void
    {
        $this->setupFrontendUserContext();
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[loginUser(\'*\') == false]'));
        $this->assertTrue($subject->match('[loginUser("*") == false]'));
    }

    /**
     * Tests whether treeLevel comparison matches.
     *
     * @test
     */
    public function treeLevelConditionMatchesSingleValue(): void
    {
        $this->assertTrue($this->getConditionMatcher()->match('[tree.level == 2]'));
    }

    /**
     * Tests whether treeLevel comparison matches.
     *
     * @test
     */
    public function treeLevelConditionMatchesMultipleValues(): void
    {
        $this->assertTrue($this->getConditionMatcher()->match('[tree.level in [999,998,2]]'));
    }

    /**
     * Tests whether treeLevel comparison matches.
     *
     * @test
     */
    public function treeLevelConditionDoesNotMatchFaultyValue(): void
    {
        $this->assertFalse($this->getConditionMatcher()->match('[tree.level == 999]'));
    }

    /**
     * Tests whether a page Id is found in the previous rootline entries.
     *
     * @test
     */
    public function PIDupinRootlineConditionMatchesSinglePageIdInRootline(): void
    {
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[2 in tree.rootLineIds]'));
        $this->assertTrue($subject->match('["2" in tree.rootLineIds]'));
        $this->assertTrue($subject->match('[\'2\' in tree.rootLineIds]'));
    }

    /**
     * Tests whether a page Id is found in the previous rootline entries.
     *
     * @test
     */
    public function PIDupinRootlineConditionDoesNotMatchPageIdNotInRootline(): void
    {
        $this->assertFalse($this->getConditionMatcher()->match('[999 in tree.rootLineIds]'));
    }

    /**
     * Tests whether a page Id is found in all rootline entries.
     *
     * @test
     */
    public function PIDinRootlineConditionMatchesSinglePageIdInRootline(): void
    {
        $this->setupFrontendController(3);
    }

    /**
     * Tests whether a page Id is found in all rootline entries.
     *
     * @test
     */
    public function PIDinRootlineConditionMatchesLastPageIdInRootline(): void
    {
        $this->assertTrue($this->getConditionMatcher()->match('[3 in tree.rootLineIds]'));
    }

    /**
     * Tests whether a page Id is found in all rootline entries.
     *
     * @test
     */
    public function PIDinRootlineConditionDoesNotMatchPageIdNotInRootline(): void
    {
        $this->assertFalse($this->getConditionMatcher()->match('[999 in tree.rootLineIds]'));
    }

    /**
     * Tests whether the compatibility version can be evaluated.
     * (e.g. 7.9 is compatible to 7.0 but not to 15.0)
     *
     * @test
     */
    public function compatVersionConditionMatchesOlderRelease(): void
    {
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[compatVersion(7.0)]'));
        $this->assertTrue($subject->match('[compatVersion("7.0")]'));
        $this->assertTrue($subject->match('[compatVersion(\'7.0\')]'));
    }

    /**
     * Tests whether the compatibility version can be evaluated.
     * (e.g. 7.9 is compatible to 7.0 but not to 15.0)
     *
     * @test
     */
    public function compatVersionConditionMatchesSameRelease(): void
    {
        $this->assertTrue($this->getConditionMatcher()->match('[compatVersion(' . TYPO3_branch . ')]'));
    }

    /**
     * Tests whether the compatibility version can be evaluated.
     * (e.g. 7.9 is compatible to 7.0 but not to 15.0)
     *
     * @test
     */
    public function compatVersionConditionDoesNotMatchNewerRelease(): void
    {
        $subject = $this->getConditionMatcher();
        $this->assertFalse($subject->match('[compatVersion(15.0)]'));
        $this->assertFalse($subject->match('[compatVersion("15.0")]'));
        $this->assertFalse($subject->match('[compatVersion(\'15.0\')]'));
    }

    /**
     * Tests whether the generic fetching of variables works with the namespace 'TSFE'.
     *
     * @test
     */
    public function genericGetVariablesSucceedsWithNamespaceTSFE(): void
    {
        $GLOBALS['TSFE']->id = 1234567;
        $GLOBALS['TSFE']->testSimpleObject = new \stdClass();
        $GLOBALS['TSFE']->testSimpleObject->testSimpleVariable = 'testValue';

        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[getTSFE().id == 1234567]'));
        $this->assertTrue($subject->match('[getTSFE().testSimpleObject.testSimpleVariable == "testValue"]'));
    }

    /**
     * Tests whether the generic fetching of variables works with the namespace 'session'.
     *
     * @test
     */
    public function genericGetVariablesSucceedsWithNamespaceSession(): void
    {
        $prophecy = $this->prophesize(FrontendUserAuthentication::class);
        $prophecy->getSessionData(Argument::exact('foo'))->willReturn(['bar' => 1234567]);
        $GLOBALS['TSFE']->fe_user = $prophecy->reveal();

        $this->assertTrue($this->getConditionMatcher()->match('[session("foo|bar") == 1234567]'));
    }

    /**
     * Tests whether the generic fetching of variables works with the namespace 'ENV'.
     *
     * @test
     */
    public function genericGetVariablesSucceedsWithNamespaceENV(): void
    {
        $testKey = $this->getUniqueId('test');
        putenv($testKey . '=testValue');
        $this->assertTrue($this->getConditionMatcher()->match('[getenv("' . $testKey . '") == "testValue"]'));
    }

    /**
     * Tests whether any property of a site language matches the request
     *
     * @test
     */
    public function siteLanguageMatchesCondition(): void
    {
        $site = new Site('angelo', 13, [
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'United States',
                    'locale' => 'en_US.UTF-8',
                ],
                [
                    'languageId' => 2,
                    'title' => 'UK',
                    'locale' => 'en_UK.UTF-8',
                ]
            ]
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $GLOBALS['TYPO3_REQUEST']->withAttribute('language', $site->getLanguageById(0));
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[siteLanguage("locale") == "en_US.UTF-8"]'));
        $this->assertTrue($subject->match('[siteLanguage("locale") in ["de_DE", "en_US.UTF-8"]]'));
    }

    /**
     * Tests whether any property of a site language does NOT match the request
     *
     * @test
     */
    public function siteLanguageDoesNotMatchCondition(): void
    {
        $site = new Site('angelo', 13, [
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'United States',
                    'locale' => 'en_US.UTF-8',
                ],
                [
                    'languageId' => 2,
                    'title' => 'UK',
                    'locale' => 'en_UK.UTF-8',
                ]
            ]
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $GLOBALS['TYPO3_REQUEST']->withAttribute('language', $site->getLanguageById(0));
        $subject = $this->getConditionMatcher();
        $this->assertFalse($subject->match('[siteLanguage("locale") == "en_UK.UTF-8"]'));
        $this->assertFalse($subject->match('[siteLanguage("locale") == "de_DE" && siteLanguage("title") == "UK"]'));
    }

    /**
     * Tests whether any property of a site matches the request
     *
     * @test
     */
    public function siteMatchesCondition(): void
    {
        $site = new Site('angelo', 13, ['languages' => [], 'base' => 'https://typo3.org/']);
        $GLOBALS['TYPO3_REQUEST'] = $GLOBALS['TYPO3_REQUEST']->withAttribute('site', $site);
        $subject = $this->getConditionMatcher();
        $this->assertTrue($subject->match('[site("identifier") == "angelo"]'));
        $this->assertTrue($subject->match('[site("rootPageId") == 13]'));
        $this->assertTrue($subject->match('[site("base") == "https://typo3.org/"]'));
    }

    /**
     * Tests whether any property of a site that does NOT match the request
     *
     * @test
     */
    public function siteDoesNotMatchCondition(): void
    {
        $site = new Site('angelo', 13, [
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'United States',
                    'locale' => 'en_US.UTF-8',
                ],
                [
                    'languageId' => 2,
                    'title' => 'UK',
                    'locale' => 'en_UK.UTF-8',
                ]
            ]
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $GLOBALS['TYPO3_REQUEST']->withAttribute('site', $site);
        $subject = $this->getConditionMatcher();
        $this->assertFalse($subject->match('[site("identifier") == "berta"]'));
        $this->assertFalse($subject->match('[site("rootPageId") == 14 && site("rootPageId") == 23]'));
    }

    /**
     * @return ConditionMatcher
     */
    protected function getConditionMatcher(): ConditionMatcher
    {
        $conditionMatcher = new ConditionMatcher();
        $conditionMatcher->setLogger($this->prophesize(Logger::class)->reveal());

        return $conditionMatcher;
    }

    /**
     * @param array $groups
     */
    protected function setupFrontendUserContext(array $groups = []): void
    {
        $frontendUser = new FrontendUserAuthentication();
        $frontendUser->user['uid'] = 13;
        $frontendUser->groupData['uid'] = $groups;

        GeneralUtility::makeInstance(Context::class)->setAspect('frontend.user', new UserAspect($frontendUser, $groups));
    }

    /**
     * @param int $pageId
     */
    protected function setupFrontendController(int $pageId): void
    {
        $site = new Site('angelo', 13, [
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'United States',
                    'locale' => 'en_US.UTF-8',
                ],
                [
                    'languageId' => 2,
                    'title' => 'UK',
                    'locale' => 'en_UK.UTF-8',
                ]
            ]
        ]);
        $GLOBALS['TSFE'] = GeneralUtility::makeInstance(
            TypoScriptFrontendController::class,
            GeneralUtility::makeInstance(Context::class),
            $site,
            $site->getLanguageById(0),
            new PageArguments($pageId, '0', [])
        );
        $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(PageRepository::class);
        $GLOBALS['TSFE']->tmpl = GeneralUtility::makeInstance(TemplateService::class);
        $GLOBALS['TSFE']->tmpl->rootLine = [
            2 => ['uid' => 3, 'pid' => 2],
            1 => ['uid' => 2, 'pid' => 1],
            0 => ['uid' => 1, 'pid' => 0]
        ];
    }
}
