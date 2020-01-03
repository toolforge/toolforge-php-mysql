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

use Defuse\Crypto\Key;

/**
 * Utility class that provides helper methods for working with MySQL/MariaDB
 * databases in Wikimedia's Toolforge environment.
 *
 * @copyright 2017 Wikimedia Foundation and contributors
 * @license MIT
 */
class Helpers {

	/**
	 * @var string Default encryption key name
	 */
	public const DEFAULT_ENCRYPTION_KEY = '.toolforge_mysql_key';

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
		$cnf = static::homedir() . '/replica.my.cnf';
		$settings = parse_ini_file( $cnf, true, INI_SCANNER_RAW );
		if ( $settings === false ) {
			throw new \RuntimeException( "Error reading {$cnf}" );
		}
		return $settings['client'];
	}

	/**
	 * Get default encryption key file path.
	 * @return string Path to default encryption key
	 */
	public static function defaultEncryptionKeyPath() {
		return static::homedir() . '/' . self::DEFAULT_ENCRYPTION_KEY;
	}

	/**
	 * Load an encryption key from disk.
	 * @param string $file Path to file
	 * @return \Defuse\Crypto\Key Encryption key
	 */
	public static function loadEncryptionKey( $file ) {
		$asciiKey = file_get_contents( $file );
		return Key::loadFromAsciiSafeString( $asciiKey );
	}

	/**
	 * Create a new encryption key file.
	 * @param string $file Path to file
	 * @return \Defuse\Crypto\Key Encryption key
	 */
	public static function createKey( $file ) {
		if ( touch( $file ) === false ) {
			throw new \RuntimeException( "Failed to touch {$file}" );
		}
		if ( chmod( $file, 0600 ) === false ) {
			throw new \RuntimeException( "Failed to chmod {$file}" );
		}
		$key = Key::createNewRandomKey();
		$asciiKey = $key->saveToAsciiSafeString();
		if ( file_put_contents( $file, $asciiKey ) === false ) {
			throw new \RuntimeException( "Failed to save key to {$file}" );
		}
		if ( chmod( $file, 0400 ) === false ) {
			throw new \RuntimeException( "Failed to chmod {$file}" );
		}
		return $key;
	}

	private function __construct() {
		// Disallow construction of utility class
	}
}
