<?php

namespace rdx\behatvars;

use Behat\Behat\Definition\Call\DefinitionCall;
use Behat\Behat\Transformation\Transformer\ArgumentTransformer;
use Behat\Gherkin\Node\PyStringNode;

class BehatVariablesArgumentTransformer implements ArgumentTransformer {

	const SLOT_NAME_OPEN = '<<';
	const SLOT_NAME_CLOSE = '>>';
	const SLOT_NAME_REGEX = '#<<([a-z]\w*)>>#i';

	protected $context;
	protected $matches;

	/**
	 *
	 */
	static public function validSlotName($slot) {
		// Empty is allowed, and must be handled by the caller.
		if ($slot === '') {
			return true;
		}

		$slot = self::SLOT_NAME_OPEN . $slot . self::SLOT_NAME_CLOSE;
		return preg_match(self::SLOT_NAME_REGEX, $slot);
	}

	/**
	 *
	 */
	public function supportsDefinitionAndArgument(DefinitionCall $definitionCall, $argumentIndex, $argumentValue) {
		return
            ($argumentValue instanceof PyStringNode || is_scalar($argumentValue)) &&
			preg_match_all(self::SLOT_NAME_REGEX, $argumentValue, $this->matches, PREG_SET_ORDER);
	}

	/**
	 *
	 */
	public function transformArgument(DefinitionCall $definitionCall, $argumentIndex, $argumentValue) {
		$replacements = array();
		foreach ($this->matches as $match) {
			$replacements[ $match[0] ] = BehatVariablesDatabase::get($match[1]);
		}

		$newArgumentValue = strtr((string) $argumentValue, $replacements);

		if ($argumentValue instanceof PyStringNode) {
			$newArgumentValue = explode("\n", $newArgumentValue);
			$newArgumentValue = new PyStringNode($newArgumentValue, count($newArgumentValue));
		}

		return $newArgumentValue;
	}

}
