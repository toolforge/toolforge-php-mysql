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

Create your session database and table:

```
$ vendor/bin/create-session-database
```

See `create-session-database --help` for additional options.

Store sessions in your database:
```php
<?php
use Bd808\Toolforge\Mysql\SessionHandler;

$sessionHandler = new SessionHandler();
session_set_save_handler( $sessionHandler, true );
session_register_shutdown();
session_start();
```

License
-------

Toolforge MySQL is licensed under the MIT license. See the
[`LICENSE`](LICENSE) file for more details.
