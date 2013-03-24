<?php

use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Event\SuiteEvent;

require_once 'PHPUnit/Framework/Assert/Functions.php';

require_once __DIR__ . '/../../php/utils.php';

/**
 * Features context.
 */
class FeatureContext extends BehatContext implements ClosuredContextInterface {

	private static $db_settings = array(
		'dbname' => 'wp_cli_test',
		'dbuser' => 'wp_cli_test',
		'dbpass' => 'password1'
	);

	private static $additional_args;

	private $install_dir;

	public $variables = array();

	/**
	 * @BeforeSuite
	 */
	public static function prepare( SuiteEvent $event ) {
		self::$additional_args = array(
			'core config' => self::$db_settings,

			'core install' => array(
				'url' => 'http://example.com',
				'title' => 'WP CLI Site',
				'admin_email' => 'admin@example.com',
				'admin_password' => 'password1'
			),

			'core install-network' => array(
				'title' => 'WP CLI Network'
			)
		);
	}

	/**
	 * Initializes context.
	 * Every scenario gets it's own context object.
	 *
	 * @param array $parameters context parameters (set them up through behat.yml)
	 */
	public function __construct( array $parameters ) {
		$this->drop_db();
	}

	public function getStepDefinitionResources() {
		return array( __DIR__ . '/../steps/basic_steps.php' );
	}

	public function getHookDefinitionResources() {
		return array();
	}

	public function replace_variables( $str ) {
		return preg_replace_callback( '/\{([A-Z_]+)\}/', array( $this, '_replace_var' ), $str );
	}

	private function _replace_var( $matches ) {
		$cmd = $matches[0];

		foreach ( array_slice( $matches, 1 ) as $key ) {
			$cmd = str_replace( '{' . $key . '}', $this->variables[ $key ], $cmd );
		}

		return $cmd;
	}

	public function create_empty_dir() {
		$this->install_dir = sys_get_temp_dir() . '/' . uniqid( "wp-cli-test-", TRUE );
		mkdir( $this->install_dir );
	}

	public function get_path( $file ) {
		return $this->install_dir . '/' . $file;
	}

	public function get_cache_path( $file ) {
		static $path;

		if ( !$path ) {
			$path = sys_get_temp_dir() . '/wp-cli-test-cache';
			system( \WP_CLI\Utils\create_cmd( 'mkdir -p %s', $path ) );
		}

		return $path . '/' . $file;
	}

	public function download_file( $url, $path ) {
		system( \WP_CLI\Utils\create_cmd( 'curl -sSL %s > %s', $url, $path ) );
	}

	private static function run_sql( $sql ) {
		system( \WP_CLI\Utils\create_cmd( 'mysql -u%s -p%s -e %s',
			self::$db_settings['dbuser'], self::$db_settings['dbpass'], $sql ) );
	}

	public function create_db() {
		$dbname = self::$db_settings['dbname'];
		self::run_sql( "CREATE DATABASE $dbname" );
	}

	public function drop_db() {
		$dbname = self::$db_settings['dbname'];
		self::run_sql( "DROP DATABASE IF EXISTS $dbname" );
	}

	private function _run( $command, $assoc_args ) {
		if ( !empty( $assoc_args ) )
			$command .= \WP_CLI\Utils\assoc_args_to_str( $assoc_args );

		if ( false === strpos( $command, '--path' ) ) {
			$command = \WP_CLI\Utils\assoc_args_to_str( array(
				'path' => $this->install_dir
			) ) . ' ' . $command;
		}

		$sh_command = getcwd() . "/bin/wp $command";

		$process = proc_open( $sh_command, array(
			0 => STDIN,
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		), $pipes );

		$STDOUT = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$STDERR = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$return_code = proc_close( $process );

		return (object) compact( 'command', 'return_code', 'STDOUT', 'STDERR' );
	}

	public function run( $command, $assoc_args = array() ) {
		if ( isset( self::$additional_args[ $command ] ) ) {
			$assoc_args = array_merge( self::$additional_args[ $command ],
				$assoc_args );
		}

		return $this->_run( $command, $assoc_args );
	}

	public function move_files( $src, $dest ) {
		rename( $this->get_path( $src ), $this->get_path( $dest ) );
	}

	public function add_line_to_wp_config( &$wp_config_code, $line ) {
		$token = "/* That's all, stop editing!";

		$wp_config_code = str_replace( $token, "$line\n\n$token", $wp_config_code );
	}

	public function download_wordpress_files() {
		// We cache the results of "wp core download" to improve test performance
		// Ideally, we'd cache at the HTTP layer for more reliable tests
		$cache_dir = sys_get_temp_dir() . '/wp-cli-test-core-download-cache';

		$r = $this->_run( 'core download', array(
			'path' => $cache_dir
		) );

		system( \WP_CLI\Utils\create_cmd( "cp -r %s/* %s/", $cache_dir, $this->install_dir ) );
	}
}
