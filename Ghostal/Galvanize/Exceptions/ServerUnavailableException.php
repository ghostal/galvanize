<?php

namespace Ghostal\Galvanize\Exceptions;

class ServerUnavailableException extends TransactionFailureException
{
	public function __construct($nested_transaction_depth, $message = '', $code = 0, \Exception $previous = null)
	{
		parent::__construct($nested_transaction_depth, $message, $code, $previous);
	}
}