<?php
namespace Ghostal\Galvanize;

use Ghostal\Galvanize\Exceptions;

class Galvanize implements IGalvanize
{
	const MAX_QUERY_ATTEMPTS = 10;
	const MAX_TRANSACTION_ATTEMPTS = 10;

	private $config = [
		'host' => 'localhost',
		'user' => 'root',
		'password' => '',
		'database' => 'test',
		'charset' => 'utf8'
	];
	private $_connected = false;
	private $_in_transaction = false;
	private $_transaction_savepoints = 0;

	/**
	 * @var \mysqli
	 */
	private $_connection = null;

	/**
	 * @param \mysqli $mysqli
	 * @param array $config
	 */
	public function __construct($mysqli, $config = [])
	{
		$this->_connection = $mysqli;

		foreach ($config as $key => $value) {
			if (isset($this->config[$key])) {
				$this->config[$key] = $value;
			}
		}
	}

	public function connect()
	{
		if ($this->_connected) {
			throw new \LogicException('Already connected');
		}

		$this->_connection->real_connect(
			$this->config['host'],
			$this->config['user'],
			$this->config['password'],
			$this->config['database']
		);

		if ($this->_connection->connect_error) {
			throw new Exceptions\ConnectionException($this->_connection->connect_error, $this->_connection->connect_errno);
		}

		$this->_connected = true;

		$this->_connection->set_charset($this->config['charset']);
	}

	public function close()
	{
		$this->_assert_connected();

		$this->_connection->close();

		$this->_connected = false;
		$this->_reset_transaction_state();
	}

	private function reconnect()
	{
		$this->close();
		$this->connect();
	}

	public function query($sql, $placeholders = [])
	{
		$this->_assert_connected();

		return $this->_execute($sql, $placeholders);
	}

	private function _execute($sql, $placeholders = [], $previous_attempts = 0)
	{
		if ($previous_attempts == self::MAX_QUERY_ATTEMPTS) {
			throw new Exceptions\MaxAttemptsExceededException();
		}

		$prepared_query = $this->_substitute_placeholders($sql, $placeholders);

		$result = $this->_connection->query($prepared_query);

		if ($result !== false) {
			return $result;
		}

		if (
		in_array(
			$this->_connection->errno,
			[
				1213, // Deadlock found when trying to get lock; try restarting transaction
				1205, // Lock wait timeout exceeded; try restarting transaction
			]
		)
		) {
			if ($this->_in_transaction) {
				// Our entire transaction has been rolled back.
				$e = new Exceptions\DeadlockException($this->_transaction_savepoints, $this->_connection->error, $this->_connection->errno);
				$this->_reset_transaction_state();
				throw $e;
			} else {
				return $this->_execute($sql, $placeholders, ++$previous_attempts); // Try again.
			}
		} else if (
		in_array(
			$this->_connection->errno,
			[
				1047, // WSREP has not yet prepared node for application use
				2006, // MySQL server has gone away
			]
		)
		) {
			$this->reconnect();
			if ($this->_in_transaction) {
				// We need to try everything again
				$e = new Exceptions\ServerUnavailableException($this->_transaction_savepoints, $this->_connection->error, $this->_connection->errno);
				$this->_reset_transaction_state();
				throw $e;
			} else {
				return $this->_execute($sql, $placeholders, ++$previous_attempts); // Try again
			}
		} else {
			// Some other problem
			throw new Exceptions\GeneralSQLException($this->_connection->error, $this->_connection->errno);
		}
	}

	private function _substitute_placeholders($sql, $placeholders)
	{
		foreach ($placeholders as $param => $value) {
			if (!preg_match('/^[0-9a-z_]+$/i', $param)) {
				throw new \InvalidArgumentException('"' . $param . '" is invalid');
			}
			if (is_null($value)) {
				$value = 'NULL';
			} else {
				$value = '"' . (is_array($value) ? implode('","', array_map([$this, 'escape'], $value)) : $this->escape($value)) . '"';
			}
			$sql = preg_replace('/:' . $param . '([^0-9a-z_]|$)/i', preg_quote($value) . '$1', $sql);
		}
		return $sql;
	}

	public function escape($value)
	{
		$this->_assert_connected();

		return $this->_connection->escape_string($value);
	}

	private function _assert_connected()
	{
		if (!$this->_connected) {
			throw new Exceptions\NotConnectedException();
		}
	}

	private function _reset_transaction_state()
	{
		$this->_transaction_savepoints = 0;
		$this->_in_transaction = false;
	}

	public function transaction(Callable $work)
	{
		for ($i = 0; $i < self::MAX_TRANSACTION_ATTEMPTS; $i++) {
			try {
				$this->_start_transaction();

				$work();

				$this->_commit_transaction();
				return true;
			} catch (Exceptions\TransactionFailureException $e) {
				$e->rethrow_if_nested();
			} catch (\Exception $e) {
				$this->_rollback_transaction();
				throw $e;
			}
		}

		throw new Exceptions\MaxAttemptsExceededException();
	}

	private function _start_transaction()
	{
		if (!$this->_in_transaction) {
			$this->_execute('START TRANSACTION;');
			$this->_in_transaction = true;
		} else {
			$this->_execute('SAVEPOINT ' . $this->_build_savepoint_name($this->_transaction_savepoints + 1) . ';');
			$this->_transaction_savepoints++;
		}
	}

	private function _commit_transaction()
	{
		$this->_assert_in_transaction();

		if ($this->_transaction_savepoints > 0) {
			$this->_execute('RELEASE SAVEPOINT ' . $this->_build_savepoint_name($this->_transaction_savepoints) . ';');
			$this->_transaction_savepoints--;
		} else {
			$this->_execute('COMMIT;');
			$this->_in_transaction = false;
		}
	}

	private function _rollback_transaction()
	{
		$this->_assert_in_transaction();

		if ($this->_transaction_savepoints > 0) {
			$this->_execute('ROLLBACK TO SAVEPOINT ' . $this->_build_savepoint_name($this->_transaction_savepoints) . ';');
			$this->_transaction_savepoints--;
		} else {
			$this->_execute('ROLLBACK;');
			$this->_in_transaction = false;
		}
	}

	private function _build_savepoint_name($nesting_level)
	{
		return 'galvanize_sp_' . $nesting_level;
	}

	private function _assert_in_transaction()
	{
		if (!$this->_in_transaction) {
			throw new Exceptions\InternalErrorException('Not in a transaction');
		}
	}
}