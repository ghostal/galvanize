<?php

namespace Ghostal\Galvanize\Mocks;

class MockMySQLi
{
	public $connect_error, $connect_errno;
	public $error, $errno;
	public $affected_rows;

	public static function init()
	{
	}

	public function __construct()
	{
	}

	public function real_connect($host, $username, $passwd, $dbname)
	{
	}

	public function query($sql, $resultmode = MYSQLI_STORE_RESULT)
	{
	}

	public function close()
	{
	}

	public function set_charset($charset)
	{
	}

	public function escape_string($str)
	{
		return $str;
	}
}