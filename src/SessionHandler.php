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

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Defuse\Crypto\Key;
use PDO;
use PDOException;
use SessionHandlerInterface;

/**
 * MySQL/MariaDB session storage backend for Toolforge.
 *
 * Session data is encrypted at rest using an encryption key.
 *
 * @copyright 2017 Wikimedia Foundation and contributors
 * @license MIT
 */
class SessionHandler implements SessionHandlerInterface {

	/**
	 * @var string Default database server
	 */
	const DEFAULT_DBHOST = 'tools.labsdb';

	/**
	 * @var string Default database name excluding ToolsDB "{$user}__" prefix
	 */
	const DEFAULT_DBNAME = 'phpsessions';

	/**
	 * @var string Default session storage table name
	 */
	const DEFAULT_DBTABLE = 'phpsessions';

	/**
	 * @var string Database name
	 */
	private $dbname;

	/**
	 * @var string Session storage table name
	 */
	private $dbtable;

	/**
	 * @var Key Encryption key
	 */
	private $key;

	/**
	 * @var PDO Database connection
	 */
	private $dbh;

	/**
	 * @param string $dbhost Database server.
	 * @param string $dbname Database name excluding ToolsDB "{$user}__"
	 *     prefix.
	 * @param string $dbtable Session storage table name.
	 * @param string $keyFile Encryption key file
	 * @throws \PDOException Raised if connection fails
	 */
	public function __construct(
		$dbhost = null,
		$dbname = null,
		$dbtable = null,
		$keyFile = null
	) {
		$dbhost = $dbhost ?: self::DEFAULT_DBHOST;
		$dbname = $dbname ?: self::DEFAULT_DBNAME;
		$dbtable = $dbtable ?: self::DEFAULT_DBTABLE;

		$creds = Helpers::mysqlCredentials();
		$this->dbname = "{$creds['user']}__{$dbname}";
		$this->dbtable = $dbtable;
		$this->dbh = new PDO(
			"mysql:host={$dbhost};dbname={$this->dbname}",
			$creds['user'], $creds['password'],
			[
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
			]
		);

		$keyFile = $keyFile ?: Helpers::defaultEncryptionKeyPath();
		$this->key = Helpers::loadEncryptionKey( $keyFile );
	}

	/**
	 * Register this handler and start session.
	 *
	 * @param int $sessionLifetime Minimum time sessions should live in seconds
	 * @param int $cookieLifetime Lifetime of the session cookie in seconds
	 * @param string $path Cookie path
	 * @param string $name Cookie name
	 * @param bool $secure Whether cookie only be transmitted over HTTPS
	 *     connections.
	 * @param bool $httponly Marks the cookie as accessible only through the
	 *     HTTP protocol.
	 * @return bool True on success, false on failure
	 */
	public function start(
		$sessionLifetime = 1440,
		$cookieLifetime = 0,
		$path = null,
		$name = null,
		$secure = true,
		$httponly = true
	) {
		$tool = explode( '/', $_SERVER['REQUEST_URI'] )[1];
		$path = $path ?: "/{$tool}";
		$name = $name ?: "{$tool}_s";

		// Only accept session id from cookie
		ini_set( 'session.use_cookies', true );
		ini_set( 'session.use_only_cookies', true );
		ini_set( 'session.use_strict_mode', true );
		ini_set( 'session.use_trans_sid', false );

		// Use long session ids
		ini_set( 'session.hash_function', 'sha256' );
		ini_set( 'session.hash_bits_per_character', 6 );

		// Don't cache pages where sessions are in use
		ini_set( 'session.cache_limiter', 'nocache' );

		// Sessions are eligible for cleanup after this many seconds
		ini_set( 'session.gc_maxlifetime', $sessionLifetime );

		// Cookie settings
		ini_set( 'session.cookie_lifetime', $cookieLifetime );
		ini_set( 'session.cookie_path', $path );
		ini_set( 'session.cookie_secure', $secure );
		ini_set( 'session.cookie_httponly', $httponly );
		session_name( $name );

		session_set_save_handler( $this, true );
		session_register_shutdown();

		return session_start();
	}

	/**
	 * Get create statement for session storage table.
	 *
	 * @param string $dbtable Session storage table name
	 * @return string Table DDL statement
	 */
	public static function createTableStatement( $dbtable ) {
		$sql = <<<ESQL
CREATE TABLE IF NOT EXISTS `{$dbtable}` (
	sess_id VARCHAR(255) NOT NULL,
	data TEXT NOT NULL,
	t_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	t_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (sess_id),
	KEY (t_updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
ESQL;
		return $sql;
	}

	/**
	 * Closes the current session.
	 *
	 * This function is automatically executed when closing the session, or
	 * explicitly via session_write_close().
	 *
	 * @return bool True on success, false on failure
	 */
	public function close() {
		$this->dbh = null;
		return true;
	}

	/**
	 * Destroys a session.
	 *
	 * Called by session_regenerate_id() (with $destroy = TRUE),
	 * session_destroy() and when session_decode() fails.
	 *
	 * @param string $session_id The session ID being destroyed.
	 * @return bool True on success, false on failure
	 */
	public function destroy( $session_id ) {
		$stmt = $this->dbh->prepare(
			"DELETE FROM `{$this->dbtable}` WHERE sess_id = ?;" );
		try {
			$this->dbh->begintransaction();
			$stmt->execute( [ $session_id ] );
			$this->dbh->commit();
			return true;
		} catch ( PDOException $e ) {
			$this->dbh->rollback();
			return false;
		}
	}

	/**
	 * Cleans up expired sessions.
	 *
	 * Called by session_start(), based on session.gc_divisor,
	 * session.gc_probability and session.gc_maxlifetime settings.
	 *
	 * @param int $maxlifetime Sessions that have not updated for the last
	 *     maxlifetime seconds will be removed.
	 * @return bool True on success, false on failure
	 */
	public function gc( $maxlifetime ) {
		$stmt = $this->dbh->prepare(
			"DELETE FROM `{$this->dbtable}` WHERE t_updated < ?;" );
		try {
			$this->dbh->begintransaction();
			$stmt->execute( [ date( 'Y-m-d H:i:s', time() - $maxlifetime ) ] );
			$this->dbh->commit();
			return true;
		} catch ( PDOException $e ) {
			$this->dbh->rollback();
			return false;
		}
	}

	/**
	 * Re-initialize existing session, or creates a new one.
	 *
	 * Called when a session starts or when session_start() is invoked.
	 *
	 * @param string $save_path The path where to store/retrieve the session.
	 * @param string $session_name The session name.
	 * @return bool True on success, false on failure
	 */
	public function open( $save_path, $session_name ) {
		return true;
	}

	/**
	 * Reads the session data from the session storage, and returns the
	 * results.
	 *
	 * Called right after the session starts or when session_start() is
	 * called. Please note that before this method is called
	 * SessionHandlerInterface::open() is invoked.
	 *
	 * This method is called by PHP itself when the session is started. This
	 * method should retrieve the session data from storage by the session ID
	 * provided. The string returned by this method must be in the same
	 * serialized format as when originally passed to the
	 * SessionHandlerInterface::write() If the record was not found, return an
	 * empty string.
	 *
	 * The data returned by this method will be decoded internally by PHP
	 * using the unserialization method specified in
	 * session.serialize_handler. The resulting data will be used to populate
	 * the $_SESSION superglobal.
	 *
	 * Note that the serialization scheme is not the same as unserialize() and
	 * can be accessed by session_decode().
	 *
	 * @param string $session_id The session id.
	 * @return string Returns an encoded string of the read data. If nothing
	 *     was read, it must return an empty string.
	 */
	public function read( $session_id ) {
		$stmt = $this->dbh->prepare(
			"SELECT data FROM `{$this->dbtable}` WHERE sess_id = ?;" );
		try {
			$stmt->execute( [ $session_id ] );
			$res = $stmt->fetch();
			return Crypto::decrypt( $res['data'], $this->key );
		} catch ( PDOException $e ) {
			return '';
		} catch ( WrongKeyOrModifiedCiphertextException $e ) {
			return '';
		}
	}

	/**
	 * Writes the session data to the session storage.
	 *
	 * Called by session_write_close(), when session_register_shutdown()
	 * fails, or during a normal shutdown. Note:
	 * SessionHandlerInterface::close() is called immediately after this
	 * function.
	 *
	 * PHP will call this method when the session is ready to be saved and
	 * closed. It encodes the session data from the $_SESSION superglobal to
	 * a serialized string and passes this along with the session ID to this
	 * method for storage. The serialization method used is specified in the
	 * session.serialize_handler setting.
	 *
	 * Note this method is normally called by PHP after the output buffers
	 * have been closed unless explicitly called by session_write_close().
	 *
	 * @param string $session_id The session id.
	 * @param string $session_data The encoded session data. This data is the
	 *     result of the PHP internally encoding the $_SESSION superglobal to
	 *     a serialized string and passing it as this parameter. Please note
	 *     sessions use an alternative serialization method.
	 * @return bool True on success, false on failure
	 */
	public function write( $session_id, $session_data ) {
		$stmt = $this->dbh->prepare(
			"INSERT INTO `{$this->dbtable}` (sess_id, data) " .
			"VALUES( :id, :data ) ".
			"ON DUPLICATE KEY UPDATE data = :data;" );
		$ciphertext = Crypto::encrypt( $session_data, $this->key );
		try {
			$this->dbh->begintransaction();
			$stmt->execute( [ 'id' => $session_id, 'data' => $ciphertext ] );
			$this->dbh->commit();
			return true;
		} catch ( PDOException $e ) {
			$this->dbh->rollback();
			return false;
		}
	}
}
