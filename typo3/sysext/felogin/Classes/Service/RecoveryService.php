<?php
declare(strict_types = 1);

namespace TYPO3\CMS\Felogin\Service;

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

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\NamedAddress;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Security\Cryptography\HashService;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Class RecoveryService
 *
 * @internal this is a concrete TYPO3 implementation and solely used for EXT:felogin and not part of TYPO3's Core API.
 */
class RecoveryService implements RecoveryServiceInterface, SingletonInterface
{
    /**
     * Sends an email to $emailAddress based on the sender TypoScript configuration.
     *
     * @param string $emailAddress Email address of the user that requested a recovery mail.
     *
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Security\Exception\InvalidArgumentForHashGenerationException
     * @throws \TYPO3\CMS\Felogin\Service\IncompleteConfigurationException
     */
    public function sendRecoveryEmail(string $emailAddress): void
    {
        $this->validateTypoScriptSettings();

        $hash = $this->getLifeTimeTimestamp() . '|' . $this->generateHash();
        $this->setForgotHashForUserByEmail($emailAddress, $hash);
        $userInformation = $this->fetchUserInformationByEmail($emailAddress);
        $receiver = $this->getReceiverName($userInformation);

        $email = $this->prepareMail($receiver, $emailAddress, $hash);
        $this->emitForgotPasswordMailSignal($email);

        GeneralUtility::makeInstance(Mailer::class)->send($email);
    }

    /**
     * @throws \TYPO3\CMS\Felogin\Service\IncompleteConfigurationException
     */
    protected function validateTypoScriptSettings(): void
    {
        if (empty($this->getSenderMailAddress()) || empty($this->getSenderName())) {
            throw new IncompleteConfigurationException(
                'Keys "email_from" and "email_fromName" of "plugin.tx_felogin_login.settings" cannot be empty!',
                1557765301
            );
        }

        if (empty($this->getHtmlMailTemplatePath())) {
            throw new IncompleteConfigurationException(
                'Key "plugin.tx_felogin_login.settings.email_htmlTemplatePath" cannot be empty!',
                1562665927
            );
        }

        if (empty($this->getTxtMailTemplatePath())) {
            throw new IncompleteConfigurationException(
                'Key "plugin.tx_felogin_login.settings.email_htmlTemplatePath" cannot be empty!',
                1562665945
            );
        }
    }

    protected function getSenderMailAddress(): string
    {
        return $this->getSettings()['email_from'] ?: $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'];
    }

    /**
     * @return array
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function getSettings(): array
    {
        static $settings;

        if ($settings === null) {
            $settings = GeneralUtility::makeInstance(ConfigurationManager::class)
                ->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);
        }

        return $settings;
    }

    protected function getSenderName(): string
    {
        return $this->getSettings()['email_fromName'] ?: $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'];
    }

    protected function getHtmlMailTemplatePath(): string
    {
        return $this->getSettings()['email_htmlTemplatePath'];
    }

    protected function getTxtMailTemplatePath(): string
    {
        return $this->getSettings()['email_plainTemplatePath'];
    }

    /**
     * @return int
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function getLifeTimeTimestamp(): int
    {
        static $timestamp;

        if ($timestamp === null) {
            $lifetimeInHours = $this->getSettings()['forgotLinkHashValidTime'] ?: 12;
            $currentTimestamp = GeneralUtility::makeInstance(Context::class)
                ->getPropertyFromAspect('date', 'timestamp');
            $timestamp = $currentTimestamp + 3600 * $lifetimeInHours;
        }

        return $timestamp;
    }

    /**
     * @return string
     * @throws \TYPO3\CMS\Extbase\Security\Exception\InvalidArgumentForHashGenerationException
     */
    protected function generateHash(): string
    {
        $randomString = GeneralUtility::makeInstance(Random::class)->generateRandomHexString(16);

        return GeneralUtility::makeInstance(HashService::class)->generateHmac($randomString);
    }

    /**
     * @param string $emailAddress
     * @param string $hash
     */
    protected function setForgotHashForUserByEmail(string $emailAddress, string $hash): void
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->update('fe_users')
            ->where(
                $queryBuilder->expr()->eq('email', $queryBuilder->createNamedParameter($emailAddress))
            )
            ->set('felogin_forgotHash', $hash)
            ->execute();
    }

    /**
     * @return \TYPO3\CMS\Core\Database\Query\QueryBuilder
     */
    protected function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('fe_users')
            ->createQueryBuilder();
    }

    /**
     * @param string $emailAddress
     *
     * @return array
     */
    protected function fetchUserInformationByEmail(string $emailAddress): array
    {
        $queryBuilder = $this->getQueryBuilder();

        return $queryBuilder->select('username', 'email', 'first_name', 'middle_name', 'last_name')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq('email', $queryBuilder->createNamedParameter($emailAddress))
            )
            ->execute()
            ->fetch();
    }

    /**
     * Get display name from values. Fallback to username if none of the "_name" fields is set.
     *
     * @param array $userInformation
     *
     * @return string
     */
    protected function getReceiverName(array $userInformation): string
    {
        $displayName = trim(
            sprintf(
                '%s%s%s',
                $userInformation['first_name'],
                $userInformation['middle_name'] ? " {$userInformation['middle_name']}" : '',
                $userInformation['last_name'] ? " {$userInformation['last_name']}" : ''
            )
        );

        return $displayName ?: $userInformation['username'];
    }

    /**
     * @param string $receiverName
     * @param string $emailAddress
     * @param string $hash
     *
     * @return \TYPO3\CMS\Core\Mail\MailMessage
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function prepareMail(string $receiverName, string $emailAddress, string $hash): MailMessage
    {
        $uriBuilder = GeneralUtility::makeInstance(ObjectManager::class)->get(UriBuilder::class);
        $url = $uriBuilder->setCreateAbsoluteUri(true)
            ->uriFor(
                'showChangePassword',
                ['hash' => $hash],
                'PasswordRecovery',
                'felogin',
                'Login'
            );

        $variables = [
            'receiverName' => $receiverName,
            'url' => $url,
            'validUntil' => date($this->getSettings()['dateFormat'], $this->getLifeTimeTimestamp()),
        ];

        $htmlMailTemplate = $this->getMailTemplate($this->getHtmlMailTemplatePath());
        $htmlMailTemplate->assignMultiple($variables);

        $plainMailTemplate = $this->getMailTemplate($this->getTxtMailTemplatePath());
        $plainMailTemplate->assignMultiple($variables);

        $subject = GeneralUtility::makeInstance(LanguageService::class)
            ->sL('LLL:EXT:felogin/Resources/Private/Language/locallang.xlf:password_recovery_mail_header');
        $mail = GeneralUtility::makeInstance(MailMessage::class);
        $mail
            ->subject($subject)
            ->from($this->getSender())
            ->to(new NamedAddress($emailAddress, $receiverName))
            ->html($htmlMailTemplate->render())
            ->text($plainMailTemplate->render());

        $replyTo = $this->getReplyTo();
        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }

        return $mail;
    }

    /**
     * @param string $templatePathAndFilename
     *
     * @return \TYPO3\CMS\Fluid\View\StandaloneView
     */
    protected function getMailTemplate(string $templatePathAndFilename): StandaloneView
    {
        $mailTemplate = GeneralUtility::makeInstance(StandaloneView::class);
        $mailTemplate->setTemplatePathAndFilename($templatePathAndFilename);

        return $mailTemplate;
    }

    protected function getSender(): NamedAddress
    {
        return new NamedAddress($this->getSenderMailAddress(), $this->getSenderName());
    }

    protected function getReplyTo(): ?Address
    {
        $address = null;
        $replyToAddress = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailReplyToAddress'];

        if (!empty($replyToAddress)) {
            $replyToName = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailReplyToName'];
            $address = new NamedAddress($replyToAddress, $replyToName);
        }

        return $address;
    }

    /**
     * Change the password for an user based on hash.
     *
     * @param string $hash The hash of the feUser that should be resolved.
     * @param string $passwordHash The new password.
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     */
    public function updatePasswordAndInvalidateHash(string $hash, string $passwordHash): void
    {
        $queryBuilder = $this->getQueryBuilder();

        $currentTimestamp = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
        $queryBuilder->update('fe_users')
            ->set('password', $passwordHash)
            ->set('felogin_forgotHash', '""', false)
            ->set('tstamp', $currentTimestamp)
            ->where(
                $queryBuilder->expr()->eq('felogin_forgotHash', $queryBuilder->createNamedParameter($hash))
            )
            ->execute();
    }

    /**
     * Returns true if an user exists with hash as `felogin_forgothash`, otherwise false.
     *
     * @param string $hash The hash of the feUser that should be check for existence.
     * @return bool Either true or false based on the existence of the user.
     */
    public function existsUserWithHash(string $hash): bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $predicates = $queryBuilder->expr()->eq('felogin_forgotHash', $queryBuilder->createNamedParameter($hash));

        return (bool)$queryBuilder->count('uid')
            ->from('fe_users')
            ->where($predicates)
            ->execute()
            ->fetchColumn();
    }

    protected function emitForgotPasswordMailSignal(MailMessage $email): void
    {
        GeneralUtility::makeInstance(ObjectManager::class)
            ->get(Dispatcher::class)
            ->dispatch(__CLASS__, 'forgotPasswordMail', [$email]);
    }
}
