<?php

namespace Ghostal\Galvanize\Tests;

use Ghostal\Galvanize\Galvanize;

class GalvanizeTest extends \PHPUnit_Framework_TestCase
{

	/**
	 * @expectedException \Ghostal\Galvanize\Exceptions\ConnectionException
	 */
	public function testConnectFail()
	{
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(0))
			->method('real_connect')
			->with(
				$this->equalTo('10.1.1.10'),
				$this->equalTo('someuser'),
				$this->equalTo('secret'),
				$this->equalTo('foo')
			);

		$mock_mysqli->connect_error = 'Some mocked error';
		$mock_mysqli->connect_errno = 123;

		$subject = new Galvanize(
			$mock_mysqli,
			[
				'host' => '10.1.1.10',
				'user' => 'someuser',
				'password' => 'secret',
				'database' => 'foo',
				'charset' => 'utf8'
			]
		);

		$subject->connect();
	}

	public function testConnectSuccess()
	{
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(0))
			->method('real_connect')
			->with(
				$this->equalTo('10.1.1.10'),
				$this->equalTo('someuser'),
				$this->equalTo('secret'),
				$this->equalTo('foo')
			);

		$mock_mysqli->expects($this->at(1))
			->method('set_charset')
			->with(
				$this->equalTo('utf8')
			);

		$mock_mysqli->connect_error = '';
		$mock_mysqli->connect_errno = 0;

		$subject = new Galvanize(
			$mock_mysqli,
			[
				'host' => '10.1.1.10',
				'user' => 'someuser',
				'password' => 'secret',
				'database' => 'foo',
				'charset' => 'utf8'
			]
		);

		$subject->connect();

		return $subject;
	}

	/**
	 * @depends testConnectSuccess
	 * @expectedException \LogicException
	 */
	public function testDoubleConnectThrowsException(Galvanize $galvanize)
	{
		$galvanize->connect();
	}

	/**
	 * @expectedException \Ghostal\Galvanize\Exceptions\NotConnectedException
	 */
	public function testQueryWhenNotConnectedThrowsException()
	{
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->query('SELECT * FROM t');
	}

	public function testQuery() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->once())
			->method('query')
			->with($this->equalTo('SELECT * FROM t'));
		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('SELECT * FROM t');
	}

	public function testQueryStringReplacements() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(2))
			->method('escape_string')
			->with(10)
			->willReturn('10');
		$mock_mysqli->expects($this->at(3))
			->method('query')
			->with($this->equalTo('SELECT * FROM t WHERE id="10";'));
		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('SELECT * FROM t WHERE id=:the_id;', ['the_id' => 10]);
	}

	public function testQueryArrayReplacements()
	{
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(2))->method('escape_string')->with(10)->willReturn('10');
		$mock_mysqli->expects($this->at(3))->method('escape_string')->with(20)->willReturn('20');
		$mock_mysqli->expects($this->at(4))->method('escape_string')->with(30)->willReturn('30');
		$mock_mysqli->expects($this->at(5))
			->method('query')
			->with($this->equalTo('SELECT * FROM t WHERE id IN ("10","20","30");'));
		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('SELECT * FROM t WHERE id IN (:the_ids);', ['the_ids' => [10,20,30]]);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testInvalidQueryReplacementName() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('SELECT * FROM t WHERE id IN (:the_ids!);', ['the_ids!' => [10,20,30]]);
	}

	public function testTransaction()
	{
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(3))->method('query')->with($this->equalTo('UPDATE t SET foo="bar" WHERE id = 10;'))->willReturn(true);
		$mock_mysqli->expects($this->at(4))->method('query')->with($this->equalTo('COMMIT;'))->willReturn(true);
		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			$galvanize->query('UPDATE t SET foo="bar" WHERE id = 10;');
		});
	}

	public function testNestedTransactions()
	{
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(3))->method('query')->with($this->equalTo('UPDATE t SET foo="bar" WHERE id = 10;'))->willReturn(true);
		$mock_mysqli->expects($this->at(4))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(5))->method('query')->with($this->equalTo('UPDATE t SET baz="foo" WHERE id = 10;'))->willReturn(true);
		$mock_mysqli->expects($this->at(6))->method('query')->with($this->equalTo('RELEASE SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(7))->method('query')->with($this->equalTo('UPDATE t SET foo="bar" WHERE id = 30;'))->willReturn(true);
		$mock_mysqli->expects($this->at(8))->method('query')->with($this->equalTo('COMMIT;'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			$galvanize->query('UPDATE t SET foo="bar" WHERE id = 10;');
			$galvanize->transaction(function () use ($galvanize) {
				$galvanize->query('UPDATE t SET baz="foo" WHERE id = 10;');
			});
			$galvanize->query('UPDATE t SET foo="bar" WHERE id = 30;');
		});
	}

	public function testTransactionRollbackAndRetry() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(3))->method('query')->with($this->equalTo('UPDATE t SET foo="bar" WHERE id = 10;'))->willReturn(true);
		$mock_mysqli->expects($this->at(4))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(5))->method('query')->with($this->equalTo('UPDATE t SET baz="foo" WHERE id = 10;'))->willReturnCallback(function () use ($mock_mysqli) {$mock_mysqli->error = 'Deadlock'; $mock_mysqli->errno = 1213; return false;});
		$mock_mysqli->expects($this->at(6))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(7))->method('query')->with($this->equalTo('UPDATE t SET foo="bar" WHERE id = 10;'))->willReturn(true);
		$mock_mysqli->expects($this->at(8))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(9))->method('query')->with($this->equalTo('UPDATE t SET baz="foo" WHERE id = 10;'))->willReturn(true);
		$mock_mysqli->expects($this->at(10))->method('query')->with($this->equalTo('RELEASE SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(11))->method('query')->with($this->equalTo('UPDATE t SET foo="bar" WHERE id = 30;'))->willReturn(true);
		$mock_mysqli->expects($this->at(12))->method('query')->with($this->equalTo('COMMIT;'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			$galvanize->query('UPDATE t SET foo="bar" WHERE id = 10;');
			$galvanize->transaction(function () use ($galvanize) {
				$galvanize->query('UPDATE t SET baz="foo" WHERE id = 10;');
			});
			$galvanize->query('UPDATE t SET foo="bar" WHERE id = 30;');
		});
	}

	/**
	 * @expectedException \Ghostal\Galvanize\Exceptions\MaxAttemptsExceededException
	 */
	public function testTransactionAttemptsExceeded() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->any())
			->method('query')
			->willReturnCallback(function ($sql) use ($mock_mysqli) {
				if ($sql == 'COMMIT;') {
					$mock_mysqli->error = 'Deadlock';
					$mock_mysqli->errno = 1213;
					return false;
				} else {
					return true;
				}
			});

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			$galvanize->query('UPDATE t SET foo="bar" WHERE id = 10;');
			$galvanize->transaction(function () use ($galvanize) {
				$galvanize->query('UPDATE t SET baz="foo" WHERE id = 10;');
			});
			$galvanize->query('UPDATE t SET foo="bar" WHERE id = 30;');
		});
	}

	/**
	 * @expectedException \Ghostal\Galvanize\Exceptions\MaxAttemptsExceededException
	 */
	public function testNonTransactionAttemptsExceeded() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->any())
			->method('query')
			->willReturnCallback(function ($sql) use ($mock_mysqli) {
				$mock_mysqli->error = 'Deadlock';
				$mock_mysqli->errno = 1213;
				return false;
			});

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('UPDATE t SET foo="bar" WHERE id = 10;');
	}

	public function testNestedBubbling() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$deadlock_callback = function () use ($mock_mysqli) {$mock_mysqli->error = 'Deadlock'; $mock_mysqli->errno = 1213; return false;};
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(3))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(4))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_2;'))->willReturnCallback($deadlock_callback);
		$mock_mysqli->expects($this->at(5))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(6))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(7))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_2;'))->willReturn(true);
		$mock_mysqli->expects($this->at(8))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturnCallback($deadlock_callback);
		$mock_mysqli->expects($this->at(9))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(10))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(11))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_2;'))->willReturn(true);
		$mock_mysqli->expects($this->at(12))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturn(true);
		$mock_mysqli->expects($this->at(13))->method('query')->with($this->equalTo('RELEASE SAVEPOINT galvanize_sp_2;'))->willReturn(true);
		$mock_mysqli->expects($this->at(14))->method('query')->with($this->equalTo('RELEASE SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(15))->method('query')->with($this->equalTo('COMMIT;'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			$galvanize->transaction(function () use ($galvanize) {
				$galvanize->transaction(function () use ($galvanize) {
					$galvanize->query('UPDATE t SET foo="bar";');
				});
			});
		});
	}

	/**
	 * @expectedException \Exception
	 * @expectedExceptionMessage Something went wrong!
	 * @expectedExceptionCode 999
	 */
	public function testExceptionRollback() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(3))->method('query')->with($this->equalTo('ROLLBACK;'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			throw new \Exception('Something went wrong!', 999);
		});
	}

	/**
	 * @expectedException \Exception
	 * @expectedExceptionMessage Something went wrong!
	 * @expectedExceptionCode 999
	 */
	public function testNestedExceptionRollback() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(3))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(4))->method('query')->with($this->equalTo('SAVEPOINT galvanize_sp_2;'))->willReturn(true);
		$mock_mysqli->expects($this->at(5))->method('query')->with($this->equalTo('ROLLBACK TO SAVEPOINT galvanize_sp_2;'))->willReturn(true);
		$mock_mysqli->expects($this->at(6))->method('query')->with($this->equalTo('ROLLBACK TO SAVEPOINT galvanize_sp_1;'))->willReturn(true);
		$mock_mysqli->expects($this->at(7))->method('query')->with($this->equalTo('ROLLBACK;'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			$galvanize->transaction(function () use ($galvanize) {
				$galvanize->transaction(function () use ($galvanize) {
					throw new \Exception('Something went wrong!', 999);
				});
			});
		});
	}

	public function testServerDisconnectDuringTransaction() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$disconnect_callback = function () use ($mock_mysqli) {$mock_mysqli->error = 'Disconnect'; $mock_mysqli->errno = 2006; return false;};
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(3))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturnCallback($disconnect_callback);
		$mock_mysqli->expects($this->at(4))->method('close');
		$mock_mysqli->expects($this->at(5))->method('real_connect');
		$mock_mysqli->expects($this->at(6))->method('set_charset');
		$mock_mysqli->expects($this->at(7))->method('query')->with($this->equalTo('START TRANSACTION;'))->willReturn(true);
		$mock_mysqli->expects($this->at(8))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturn(true);
		$mock_mysqli->expects($this->at(9))->method('query')->with($this->equalTo('COMMIT;'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->transaction(function () use ($galvanize) {
			$galvanize->query('UPDATE t SET foo="bar";');
		});
	}

	public function testServerDisconnect() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$disconnect_callback = function () use ($mock_mysqli) {$mock_mysqli->error = 'Disconnect'; $mock_mysqli->errno = 2006; return false;};
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturnCallback($disconnect_callback);
		$mock_mysqli->expects($this->at(3))->method('close');
		$mock_mysqli->expects($this->at(4))->method('real_connect');
		$mock_mysqli->expects($this->at(5))->method('set_charset');
		$mock_mysqli->expects($this->at(6))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('UPDATE t SET foo="bar";');
	}

	public function testNodeNotReady() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$disconnect_callback = function () use ($mock_mysqli) {$mock_mysqli->error = 'WSREP Node not ready'; $mock_mysqli->errno = 1047; return false;};
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturnCallback($disconnect_callback);
		$mock_mysqli->expects($this->at(3))->method('close');
		$mock_mysqli->expects($this->at(4))->method('real_connect');
		$mock_mysqli->expects($this->at(5))->method('set_charset');
		$mock_mysqli->expects($this->at(6))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturn(true);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('UPDATE t SET foo="bar";');
	}

	/**
	 * @expectedException \Ghostal\Galvanize\Exceptions\GeneralSQLException
	 */
	public function testSQLSyntaxError() {
		$mock_mysqli = $this->getMockBuilder('Ghostal\Galvanize\Mocks\MockMySQLi')->getMock();
		$syntax_error_callback = function () use ($mock_mysqli) {$mock_mysqli->error = 'Syntax error near xyz'; $mock_mysqli->errno = 1064; return false;};
		$mock_mysqli->expects($this->at(2))->method('query')->with($this->equalTo('UPDATE t SET foo="bar";'))->willReturnCallback($syntax_error_callback);

		$galvanize = new Galvanize($mock_mysqli, []);
		$galvanize->connect();
		$galvanize->query('UPDATE t SET foo="bar";');
	}
}