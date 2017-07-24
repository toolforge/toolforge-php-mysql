<?php
/**
 * Copyright 2017 Wikimedia Foundation and contributors
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including without
 * limitation the rights to use, copy, modify, merge, publish, distribute,
 * sublicense, and/or sell copies of the Software, and to permit persons to
 * whom the Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace Bd808\Toolforge\Mysql;

/**
 * Utility class that provides helper methods for working with MySQL/MariaDB
 * databases in Wikimedia's Toolforge environment.
 *
 * @copyright 2017 Wikimedia Foundation and contributors
 * @license MIT
 */
class Helpers {
	/**
	 * Get the current shell user's home directory.
	 * @return string Path to home directory
	 */
	public static function homedir() {
		$uid = posix_getuid();
		$userinfo = posix_getpwuid( $uid );
		return $userinfo['dir'];
	}

	/**
	 * Get the current shell user's default mysql credentials as read from
	 * $HOME/replica.my.cnf.
	 * @return array [ "user" => "...", "password" => "..." ]
	 */
	public static function mysqlCredentials() {
		$settings = parse_ini_file(
			static::homedir() . '/replica.my.cnf', true, INI_SCANNER_RAW );
		return $settings['client'];
	}

	private function __construct() {
		// Disallow construction of utility class
	}
}
