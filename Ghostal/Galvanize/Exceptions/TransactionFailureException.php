<?php

namespace Ghostal\Galvanize\Exceptions;

abstract class TransactionFailureException extends \RuntimeException
{
	private $nested_transaction_depth = null;

	public function __construct($nested_transaction_depth, $message = '', $code = 0, \Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
		$this->nested_transaction_depth = $nested_transaction_depth;
	}

	public function rethrow_if_nested()
	{
		if ($this->nested_transaction_depth > 0) {
			$this->nested_transaction_depth--;
			throw $this;
		}
	}
}