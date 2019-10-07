.. include:: ../../Includes.txt

===================================================
Feature: #88110 - Felogin extbase password recovery
===================================================

See :issue:`88110`

Description
===========

As part of the felogin extbase plugin, a password recovery form has been added. 

FE users are able to reset their passwords via email. A mail with a forgot hash will be send to the depending user. If that hash is found valid a form reset password form is shown. If all validators are met valid the users password will be updated.

There is a way to define and override default validators. Configured as default are two validators: NotEmptyValidator and StringLengthValidator.

They can be overridden by overwriting :ts:`plugin.tx_felogin_login.settings.passwordValidators`.
Default is as follows:

.. code-block:: typoscript

   passwordValidators {
      10 = TYPO3\CMS\Extbase\Validation\Validator\NotEmptyValidator
      20 {
         className = TYPO3\CMS\Extbase\Validation\Validator\StringLengthValidator
         options {
            minimum = {$styles.content.loginform.newPasswordMinLength}
         }
      }
   }

A custom configuration can look like this:

.. code-block:: typoscript

   passwordValidators {
      10 = TYPO3\CMS\Extbase\Validation\Validator\AlphanumericValidator
      20 {
         className = TYPO3\CMS\Extbase\Validation\Validator\StringLengthValidator
         options {
            minimum = {$styles.content.loginform.newPasswordMinLength}
            maximum = 32
         }
      }
      30 = \Vendor\MyExt\Validation\Validator\MyCustomPasswordPolicyValidator
   }

Impact
======

No direct impact. Only used, if feature toggle "felogin.extbase" is explicitly turned on.

.. index:: Database, FlexForm, Fluid, Frontend, TypoScript, ext:felogin
