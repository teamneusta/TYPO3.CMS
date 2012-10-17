<?php
namespace TYPO3\CMS\Fluid\ViewHelpers\Format;

/*                                                                        *
 * This script is backported from the FLOW3 package "TYPO3.Fluid".        *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 *  of the License, or (at your option) any later version.                *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */
/**
 * Formats a given float to a currency representation.
 *
 * = Examples =
 *
 * <code title="Defaults">
 * <f:format.currency>123.456</f:format.currency>
 * </code>
 * <output>
 * 123,46
 * </output>
 *
 * <code title="All parameters">
 * <f:format.currency currencySign="$" decimalSeparator="." thousandsSeparator="," prependCurrency="TRUE" separateCurrency="FALSE">54321</f:format.currency>
 * </code>
 * <output>
 * $54,321.00
 * </output>
 *
 * <code title="Inline notation">
 * {someNumber -> f:format.currency(thousandsSeparator: ',', currencySign: '€')}
 * </code>
 * <output>
 * 54,321,00 €
 * (depending on the value of {someNumber})
 * </output>
 *
 * @api
 */
class CurrencyViewHelper extends \TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper {

	/**
	 * @param string $currencySign (optional) The currency sign, eg $ or €.
	 * @param string $decimalSeparator (optional) The separator for the decimal point.
	 * @param string $thousandsSeparator (optional) The thousands separator.
	 * @param boolean $prependCurrency (optional) Select if the curreny sign should be prepended
	 * @param boolean $separateCurrency (optional) Separate the currency sign from the number by a single space, defaults to true due to backwards compatibility
	 * @return string the formatted amount.
	 * @api
	 */
	public function render($currencySign = '', $decimalSeparator = ',', $thousandsSeparator = '.', $prependCurrency = FALSE, $separateCurrency = TRUE) {
		$floatToFormat = $this->renderChildren();
		if (empty($floatToFormat)) {
			$floatToFormat = 0.0;
		} else {
			$floatToFormat = floatval($floatToFormat);
		}
		$output = number_format($floatToFormat, 2, $decimalSeparator, $thousandsSeparator);
		if ($currencySign !== '') {
			$currencySeparator = $separateCurrency ? ' ' : '';
			if ($prependCurrency === TRUE) {
				$output = ($currencySign . $currencySeparator) . $output;
			} else {
				$output = ($output . $currencySeparator) . $currencySign;
			}
		}
		return $output;
	}

}


?>