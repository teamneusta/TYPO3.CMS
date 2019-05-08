<?php
declare(strict_types = 1);

namespace TYPO3\CMS\Felogin\Tests\Unit\Service;

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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Felogin\Service\RecoveryService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class RecoveryServiceTest extends UnitTestCase
{
    /**
     * @var \TYPO3\CMS\Felogin\Service\RecoveryService
     */
    protected $subject;

    protected function setUp(): void
    {
        $this->subject = new RecoveryService();

        parent::setUp();
    }

    /**
     * @test
     */
    public function existsUserWithHashShouldReturnFalseIfNoUserIsFound(): void
    {
        $expressionBuilder = $this->prophesize(ExpressionBuilder::class);
        $expressionBuilder->eq('felogin_forgotHash', ':dc1')->willReturn('felogin_forgotHash = :dc1');

        $statement = $this->prophesize(Statement::class);

        $queryBuilder = $this->prophesize(QueryBuilder::class);
        $queryBuilder->execute()->willReturn($statement->reveal());
        $queryBuilder->where('felogin_forgotHash = :dc1')->willReturn($queryBuilder->reveal());
        $queryBuilder->from('fe_users')->willReturn($queryBuilder->reveal());
        $queryBuilder->count('uid')->willReturn($queryBuilder->reveal());
        $queryBuilder->createNamedParameter('some hash')->willReturn(':dc1');
        $queryBuilder->expr()->willReturn($expressionBuilder->reveal());

        $this->mockGetQueryBuilder($queryBuilder);

        static::assertFalse($this->subject->existsUserWithHash('some hash'));
    }

    /**
     * @test
     */
    public function updatePasswordAndInvalidateHashShould(): void
    {
        $expressionBuilder = $this->prophesize(ExpressionBuilder::class);
        $expressionBuilder->eq('felogin_forgotHash', ':dc3')->willReturn('felogin_forgotHash = :dc3');

        $queryBuilder = $this->prophesize(QueryBuilder::class);

        $queryBuilder->execute()->shouldBeCalled();
        $queryBuilder->where('felogin_forgotHash = :dc3')->willReturn($queryBuilder->reveal());
        $queryBuilder->createNamedParameter('some hash')->willReturn(':dc3');
        $queryBuilder->expr()->willReturn($expressionBuilder);
        $queryBuilder->set('tstamp', Argument::any())->willReturn($queryBuilder->reveal());
        $queryBuilder->set('felogin_forgotHash', '""', false)->willReturn($queryBuilder->reveal());
        $queryBuilder->set('password', 'some hashed password')->willReturn($queryBuilder->reveal());
        $queryBuilder->update('fe_users')->willReturn($queryBuilder->reveal());

        $this->mockGetQueryBuilder($queryBuilder);

        $this->subject->updatePasswordAndInvalidateHash('some hash', 'some hashed password');
    }

    /**
     * @param \Prophecy\Prophecy\ObjectProphecy $queryBuilder
     */
    protected function mockGetQueryBuilder(\Prophecy\Prophecy\ObjectProphecy $queryBuilder): void
    {
        $connection = $this->prophesize(Connection::class);
        $connection->createQueryBuilder()->willReturn($queryBuilder->reveal());

        $connectionPool = $this->prophesize(ConnectionPool::class);
        $connectionPool->getConnectionForTable(Argument::any())->willReturn($connection->reveal());
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool->reveal());
    }
}
