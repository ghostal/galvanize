<?php

namespace Ghostal\Galvanize;

interface IGalvanize
{
	public function __construct($mysqli, $config = []);

	public function connect();

	public function close();

	public function transaction(Callable $work);

	public function query($sql, $placeholders = []);
}