MeekroDB (http://www.meekro.com) API:http://www.meekro.com/docs.php
========
MeekroDB is a mysqli wrapper
PHP 5.3+ fork (smaller file size)


Setup
=====

#### Composer
If you are using composer, then add `ronisaha/meekro4php5.3` as a dependency:

```bash
composer require ronisaha/meekro4php5.3
```

In this case, you would include composer's auto-loader at the top of your source files:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
```

#### Manually
If you don't have composer available, then simply download the code and include `autoload.php`:

```bash
git clone https://github.com/ronisaha/meekrodb
```

```php
<?php
require __DIR__ . '/meekrodb/autoload.php';
```

### Configuration

    require_once 'autoload.php';
    use Meekro\DB;
    
    DB::$user = 'my_database_user';
    DB::$password = 'my_database_password';
    DB::$dbName = 'my_database_name';

Quick Doc / example
========
### Selects
    //array
    $accounts = DB::query("SELECT * FROM accounts WHERE type = %s AND age > %i", $type, 15);
    foreach ($accounts as $account) {
      echo $account['username'] . "\n";
    }
	
	//row
	$account = DB::queryFirstRow("SELECT * FROM accounts WHERE username=%s", 'Joe');
	
	//field
	$number_accounts = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
	
### Insert a new row.

    DB::insert('mytable', [
      'name' => $name,
      'rank' => $rank,
      'location' => $location,
      'age' => $age,
      'intelligence' => $intelligence
    ]);
    
    DB::query("INSERT INTO %b %lb VALUES %ll?", 'accounts',
      ['username', 'password', 'last_login_timestamp'],
      [
        ['Joe', 'joes_password', new DateTime('yesterday')],
        ['Frank', 'franks_password', new DateTime('last Monday')]
      ]
    );
	
### Array in WHERE clause
	DB::query("SELECT * FROM tbl WHERE name IN %ls AND age NOT IN %li", ['John', 'Bob'], [12, 15]);

### Easy Nested transactions

	DB::$nested_transactions = true;
    DB::startTransaction(); // outer transaction
	// .. some queries..
	
		$depth = DB::startTransaction(); // inner transaction
		echo $depth . 'transactions are currently active'; // 2
		 
		// .. some queries..
		DB::commit(); // commit inner transaction
		
    // .. some queries..
    DB::commit(); // commit outer transaction

