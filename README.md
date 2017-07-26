Toolforge MySQL
===============

Helpers for working with MySQL databases on Wikimedia's Toolforge service.

Installation
------------

```
$ composer require bd808/toolforge-mysql
```

Usage
-----

### Storing sessions in ToolsDB

Create your session database, table, and encryption key:

```
$ vendor/bin/toolforge-mysql-session-init
```

See `toolforge-mysql-session-init --help` for additional options.

Store sessions in your database:
```php
<?php
use Bd808\Toolforge\Mysql\SessionHandler;

$sessionHandler = new SessionHandler();
$sessionHandler->start();
```

See [SessionHandler.php](src/SessionHandler.php) for additional options.

License
-------

Toolforge MySQL is licensed under the MIT license. See the
[`LICENSE`](LICENSE) file for more details.
