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

use Doctrine\DBAL\Driver\Statement;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Error\Result;
use TYPO3\CMS\Extbase\Mvc\Controller\Arguments;
use TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\Web\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Response;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Service\CacheService;
use TYPO3\CMS\Extbase\Validation\Validator\NotEmptyValidator;
use TYPO3\CMS\Extbase\Validation\Validator\StringLengthValidator;
use TYPO3\CMS\Felogin\Controller\PasswordRecoveryController;
use TYPO3\CMS\Felogin\Service\RecoveryServiceInterface;
use TYPO3\CMS\Felogin\Service\TreeUidListProvider;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case
 */
class PasswordRecoveryControllerTest extends UnitTestCase
{
    protected $resetSingletonInstances = true;

    /**
     * @var \TYPO3\CMS\Felogin\Controller\PasswordRecoveryController
     */
    protected $subject;

    /**
     * @var array
     */
    protected $settings = [
        'passwordValidators' => [
            NotEmptyValidator::class,
            ['className' => StringLengthValidator::class, 'options' => ['minimum' => 6]],
        ],
        'pages' => 123,
        'recursive' => 1,
    ];

    protected $storagePidList = '123';

    protected function setUp(): void
    {
        $this->subject = $this->getAccessibleMock(PasswordRecoveryController::class, ['getTranslation']);

        $this->subject->method('getTranslation')->willReturn('any translation');
    }

    /**
     * @test
     */
    public function changePasswordActionShouldRedirectAfterSettingNewPassword(): void
    {
        $newPassword = 'Pa$$w0rd!';
        $passwordHash = 'password hash!';
        $hash = 'some hash';

        $this->mockRedirect('login', 'Login');

        $fePasswordHash = $this->prophesize(PasswordHashInterface::class);
        $fePasswordHash->getHashedPassword($newPassword)->willReturn($passwordHash);

        $passwordHashFactory = $this->prophesize(PasswordHashFactory::class);
        $passwordHashFactory->getDefaultHashInstance('FE')->willReturn($fePasswordHash->reveal());

        GeneralUtility::addInstance(PasswordHashFactory::class, $passwordHashFactory->reveal());

        $recoveryService = $this->prophesize(RecoveryServiceInterface::class);

        $this->subject->injectRecoveryService($recoveryService->reveal());
        $this->subject->changePasswordAction($newPassword, $hash);

        $recoveryService->updatePasswordAndInvalidateHash($hash, $passwordHash)->shouldHaveBeenCalled();
    }

    /**
     * @test
     */
    public function showChangePasswordActionShouldAssignPassedHashToView(): void
    {
        $view = $this->prophesize(ViewInterface::class);
        $this->inject($this->subject, 'view', $view->reveal());

        $hash = 'some hash';
        $this->subject->showChangePasswordAction($hash);

        $view->assign('hash', $hash)->shouldHaveBeenCalled();
    }

    /**
     * @test
     * @param string $hash
     * @dataProvider invalidHashDataProvider
     */
    public function initializeShowChangePasswordActionShouldExitEarlyIfRequestDoesNotContainAValidHash($hash): void
    {
        $this->mockRedirect('recovery', 'PasswordRecovery');

        $request = $this->prophesize(Request::class);
        $request->hasArgument('hash')->willReturn(true);
        $request->getArgument('hash')->willReturn($hash);
        $this->inject($this->subject, 'request', $request->reveal());

        $this->subject->initializeShowChangePasswordAction();
    }

    /**
     * @test
     */
    public function initializeShowChangePasswordActionShouldForwardToRecoveryAndInformUserThatTheHashIsExpired(): void
    {
        $hash = '1558453676|some cool hash';

        $result = $this->prophesize(Result::class);

        $request = $this->prophesize(Request::class);
        $request->hasArgument('hash')->willReturn(true);
        $request->getArgument('hash')->willReturn($hash);
        $request->getOriginalRequestMappingResults()->willReturn($result->reveal());
        $request->setOriginalRequestMappingResults($result->reveal())->shouldBeCalled();

        $this->mockForward($request);

        $this->inject($this->subject, 'request', $request->reveal());

        $this->subject->initializeShowChangePasswordAction();

        $error = new Error('Your password recovery link is expired.', 1554994253);
        $result->addError($error)->shouldHaveBeenCalled();
    }

    /**
     * @test
     */
    public function initializeShowChangePasswordActionShouldNotStopWorkflowIfValidHashIsPassedAndUserExists(): void
    {
        $hash = '9999999999|somevalidhash';

        $request = $this->prophesize(Request::class);
        $request->hasArgument('hash')->willReturn(true);
        $request->getArgument('hash')->willReturn($hash); // some timestamp from waaaaay in the future
        $this->inject($this->subject, 'request', $request->reveal());

        $recoveryService = $this->prophesize(RecoveryServiceInterface::class);
        $recoveryService->existsUserWithHash($hash)->willReturn(true);

        $this->subject->injectRecoveryService($recoveryService->reveal());
        $this->subject->initializeShowChangePasswordAction();
    }

    /**
     * @test
     */
    public function recoveryActionShouldOnlyRenderViewIfNoIdentifierIsPassed(): void
    {
        $this->subject->recoveryAction();
    }

    /**
     * @test
     */
    public function recoveryActionShouldTryToFetchEmailFromUserAndDoNothingIfEmailIsNotFound(): void
    {
        $emailOrUsername = 'mycoolusername';

        $this->mockFetchEmailFromUser($emailOrUsername);
        $this->mockAddFlashMessage();
        $this->mockRedirect('login', 'Login');

        $recoveryService = $this->prophesize(RecoveryServiceInterface::class);

        $this->subject->injectRecoveryService($recoveryService->reveal());
        $this->subject->recoveryAction($emailOrUsername);

        $recoveryService->sendRecoveryEmail()->shouldNotHaveBeenCalled();
    }

    /**
     * @test
     */
    public function recoveryActionShouldTryToFetchEmailFromUserAndSendMailIfEmailIsFound(): void
    {
        $emailOrUsername = 'mycoolusername';
        $email = 'my@coolemail.com';

        $this->mockFetchEmailFromUser($emailOrUsername, $email);
        $this->mockAddFlashMessage();
        $this->mockRedirect('login', 'Login');

        $recoveryService = $this->prophesize(RecoveryServiceInterface::class);
        $this->subject->injectRecoveryService($recoveryService->reveal());
        $this->subject->recoveryAction($emailOrUsername);

        $recoveryService->sendRecoveryEmail($email)->shouldHaveBeenCalled();
    }

    public function invalidHashDataProvider(): array
    {
        return [
            'no hash parameter' => [
                'hash' => null
            ],
            'empty hash parameter' => [
                'hash' => ''
            ],
            'hash is an int' => [
                'hash' => 1337
            ],
            'hash is an array' => [
                'hash' => ['foo']
            ],
            'hash is a boolean' => [
                'hash' => false
            ],
            'hash does not contain a "pipe"' => [
                'hash' => 'some hash without a pipe'
            ]
        ];
    }

    /**
     * @test
     */
    public function initializeChangePasswordActionShouldExitEarlyIfNewPasswordOrPasswordRepeatIsNotSet(): void
    {
        $result = $this->prophesize(Result::class);

        $request = $this->prophesize(Request::class);
        $request->getOriginalRequestMappingResults()->willReturn($result->reveal());
        $request->hasArgument('newPass')->willReturn(true);
        $request->hasArgument('newPassRepeat')->willReturn(false);

        $request->getArgument('newPass')->willReturn('');
        $request->getArgument('newPassRepeat')->willReturn(null);
        $request->setOriginalRequestMappingResults($result->reveal())->shouldBeCalled();
        $request->getArgument('hash')->willReturn('hash');

        $this->mockForward($request, 'showChangePassword', 'PasswordRecovery', ['hash' => 'hash']);

        $this->inject($this->subject, 'request', $request->reveal());
        $this->subject->initializeChangePasswordAction();
    }

    /**
     * @test
     */
    public function initializeChangePasswordActionShouldValidateNewPasswordAndForwardOnError(): void
    {
        $result = new Result();
        $request = new Request();
        $request->setOriginalRequestMappingResults($result);
        $request->setArguments([
            'hash' => 'some hash',
            'newPass' => 'new password',
            'newPassRepeat' => 'new password repeated'
        ]);

        $this->inject($this->subject, 'request', $request);
        $this->inject($this->subject, 'settings', $this->settings);

        try {
            $this->subject->initializeChangePasswordAction();
            $this->fail();
        } catch (StopActionException $e) {
        }

        $errors = $request->getOriginalRequestMappingResults()->getErrors();

        self::assertEquals([new Error('any translation', 1554912163)], $errors);
    }

    /**
     * @test
     */
    public function initializeChangePasswordActionShouldNotStopWorkflowIfNewPasswordIsValid()
    {
        $request = new Request();
        $request->setArguments([
            'hash' => 'some hash',
            'newPass' => 'newPassword',
            'newPassRepeat' => 'newPassword',
        ]);

        $this->inject($this->subject, 'request', $request);
        $this->inject($this->subject, 'settings', $this->settings);

        $this->subject->initializeChangePasswordAction();

        static::assertEmpty($request->getOriginalRequestMappingResults()->getErrors());
    }

    /**
     * @param string $emailOrUsername
     * @param string $email
     */
    protected function mockFetchEmailFromUser(string $emailOrUsername, string $email = ''): void
    {
        $treeUidListProvider = $this->prophesize(TreeUidListProvider::class);
        $treeUidListProvider->getListForIdList($this->settings['pages'], $this->settings['recursive'])
            ->willReturn($this->storagePidList);

        $expressionBuilder = $this->prophesize(ExpressionBuilder::class);
        $expressionBuilder->orX(Argument::any(), Argument::any())->shouldBeCalled();
        $expressionBuilder->eq('username', ':dc1')->shouldBeCalled();
        $expressionBuilder->eq('email', ':dc2')->shouldBeCalled();
        $expressionBuilder->in('pid', $this->settings['pages'])->shouldBeCalled();

        $statement = $this->prophesize(Statement::class);
        $statement->fetchColumn()->willReturn($email);

        $queryBuilder = $this->prophesize(QueryBuilder::class);
        $queryBuilder->execute()->willReturn($statement->reveal())->shouldBeCalled();
        $queryBuilder->where(Argument::any(), Argument::any())->willReturn($queryBuilder->reveal());
        $queryBuilder->createNamedParameter($emailOrUsername)->willReturn(':dc1', ':dc2');
        $queryBuilder->expr()->willReturn($expressionBuilder->reveal());
        $queryBuilder->from('fe_users')->willReturn($queryBuilder->reveal());
        $queryBuilder->select('email')->willReturn($queryBuilder->reveal());

        $connection = $this->prophesize(Connection::class);
        $connection->createQueryBuilder()->willReturn($queryBuilder->reveal());

        $connectionPool = $this->prophesize(ConnectionPool::class);
        $connectionPool->getConnectionForTable('fe_users')->willReturn($connection->reveal());

        GeneralUtility::addInstance(TreeUidListProvider::class, $treeUidListProvider->reveal());
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool->reveal());
    }

    protected function mockAddFlashMessage(): void
    {
        $flashMessageQueue = $this->prophesize(FlashMessageQueue::class);
        $flashMessageQueue->enqueue(Argument::any());
        $controllerContext = $this->prophesize(ControllerContext::class);
        $controllerContext->getFlashMessageQueue()->willReturn($flashMessageQueue->reveal());

        $this->inject($this->subject, 'controllerContext', $controllerContext->reveal());
    }

    protected function mockRedirect(
        $actionName,
        $controllerName = '',
        $arguments = null,
        $extensionName = 'felogin',
        $pageUid = null
    ): void {
        $contentObject = $this->prophesize(ContentObjectRenderer::class);
        $contentObject->getUserObjectType()->willReturn('foo');

        $configurationManager = $this->prophesize(ConfigurationManager::class);
        $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS)->willReturn($this->settings);
        $configurationManager->getContentObject()->willReturn($contentObject->reveal());

        $this->subject->injectConfigurationManager($configurationManager->reveal());

        $response = $this->prophesize(Response::class);
        $response->setHeader(Argument::any(), Argument::any())->shouldBeCalled();
        $response->setStatus(303)->shouldBeCalled();
        $response->setContent(Argument::any())->shouldBeCalled();
        $this->inject($this->subject, 'response', $response->reveal());

        $cacheService = $this->prophesize(CacheService::class);
        $cacheService->clearCachesOfRegisteredPageIds()->shouldBeCalled();

        $objectManager = $this->prophesize(ObjectManager::class);
        $objectManager->get(Arguments::class)->willReturn(new Arguments());
        $objectManager->get(CacheService::class)->willReturn($cacheService->reveal());

        $this->subject->injectObjectManager($objectManager->reveal());

        $request = $this->prophesize(Request::class);
        $this->inject($this->subject, 'request', $request->reveal());

        $uriBuilder = $this->prophesize(UriBuilder::class);
        $uriBuilder->uriFor($actionName, null, $controllerName, $extensionName)->shouldBeCalled();
        $uriBuilder->setCreateAbsoluteUri(true)->shouldBeCalled();
        $uriBuilder->setTargetPageUid($pageUid)->willReturn($uriBuilder->reveal());
        $uriBuilder->reset()->willReturn($uriBuilder->reveal());
        $this->inject($this->subject, 'uriBuilder', $uriBuilder->reveal());

        $this->expectException(StopActionException::class);
    }

    /**
     * @param \Prophecy\Prophecy\ObjectProphecy $request
     * @param string $actionName
     * @param string $controllerName
     * @param array|null $arguments
     */
    protected function mockForward(
        ObjectProphecy $request,
        string $actionName = 'recovery',
        string $controllerName = 'PasswordRecovery',
        array $arguments = null
    ): void {
        $this->expectException(StopActionException::class);

        $request->setDispatched(false)->shouldBeCalled();
        $request->setControllerActionName($actionName)->shouldBeCalled();
        $request->setControllerName($controllerName)->shouldBeCalled();
        $request->setControllerExtensionName('felogin')->shouldBeCalled();

        if ($arguments) {
            $request->setArguments($arguments)->shouldBeCalled();
        }
    }
}
