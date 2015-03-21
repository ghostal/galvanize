Galvanize
=========

Galvanize is a Galera Cluster-safe MySQL database class

Features
--------

# Galera Cluster-safe
When using PHP’s mysqli library with a Galera cluster, various nasty transaction-related bugs can easily creep in and cause havoc with your application code, and, consequently, your database. These bugs stem from differences between the assumptions made by the PHP library about how MySQL works, and the way Galera’s implementation of the WSREP interface actually works. Galvanize aims to protect you from these bugs.

# Automatic rollback-and-retry in the event of transaction deadlocks
Any code inside a function passed to Galvanize's `transaction()` method will be re-tried in the event of a deadlock, up to `Galvanize::MAX_TRANSACTION_ATTEMPTS` times. The default setting is `10`.

		$galvanize->transaction(function () use ($galvanize) {
			// ...
			// If a deadlock is encountered, execution of this block will be retried.
			// The number of transaction retries defaults to 10.
			// ...
		});

**Warning:** Be careful when using caching mechanisms or re-using variable names, as this could cause unintended behaviour in a deadlock/retry scenario. For example:

	$iterations = 0;
	$values = [4,8,15,16,23,42];
	foreach ($values as $value) {
		$galvanize->transaction(function () use ($galvanize, $value, $iterations) {
			$iterations++;
			$galvanize->query('UPDATE t SET foo = :value', ['value' => $value]);
			$galvanize->query('UPDATE rev SET revision = revision + 1');
		});
	}
	echo $iterations;

The value of `$iterations` might be larger than the number of elements in `$values`.

# "Nested" transaction-like functionality (using SAVEPOINTs)
Calls to `transaction()` can be nested, so blocks of work can be applied and rolled back within other blocks of work.

	$galvanize->transaction(function () use ($galvanize) {
		// ...
		$galvanize->transaction(function () use ($galvanize) {
			// ...
			$galvanize->transaction(function () use ($galvanize) {
				// ...
			});
			// ...
		});
		// ...
	});

This functionality is implemented using SAVEPOINTs. A deadlock will cause execution to return to the beginning of the outermost call to `transaction()`.

# Uncaught exceptions trigger an automatic ROLLBACK
Any uncaught exception occurring within a call to `transaction()` will cause Galvanize to issue a `ROLLBACK` to the database - failing code does not leave transactions open.

# Prepared query-like syntax for automatic escaping to protect against SQL injection attacks
	$galvanize->query('SELECT * FROM t WHERE id=:the_id;', ['the_id' => 10]);

This results in a query being sent as follows:

	SELECT * FROM t WHERE id="10";

Passing an array of values for a given replacement results in a comma-separated, escaped list. This is useful for `IN(...)` and similar clauses.

	$galvanize->query('SELECT * FROM t WHERE id IN (:the_ids);', ['the_ids' => [10,20,30]]);

Results in a query as follows:

	SELECT * FROM t WHERE id IN ("10","20","30");

# > 90% unit test coverage
You can feel safe using and modifying Galvanize, as it is supplied with a comprehensive suite of unit tests.

Extending Galvanize
-------------------

Galvanize aims to be easily extended, but many methods and properties in the class are private for a reason. All SQL sent to MySQL should go through the `query()` function in order for deadlocks to be properly handled. All transactions should use the `transaction()` "execute-around" function. If you think you need to break these conventions, consider first whether you could solve your problem by refactoring your calling code. This will almost always be a better solution.

Requirements
------------

* PHP >= 5.4