<?php
/**
 * Composer scripts: portable QA entry points.
 *
 * Every gate resolves its PHP binary explicitly (repo-local toolchain first,
 * then PHP_BINARY), so the commands work identically on Windows (where the
 * vendor/bin .bat shims need a system PHP) and on CI runners.
 *
 * @package StateFlow
 */

if ( ! function_exists( 'stateflow_php_binary' ) ) {
	/**
	 * Resolve the PHP binary used for the QA tools.
	 *
	 * @return string
	 */
	function stateflow_php_binary(): string {
		$from_env = (string) getenv( 'PHP_BINARY_ENV' );

		if ( '' !== $from_env && is_executable( $from_env ) ) {
			return $from_env;
		}

		$local = __DIR__ . '/tools/php/php.exe';

		if ( is_executable( $local ) ) {
			return $local;
		}

		return (string) PHP_BINARY;
	}
}

if ( ! function_exists( 'stateflow_run_tool' ) ) {
	/**
	 * Run one vendored QA tool with the resolved PHP binary, inheriting the
	 * environment (proc_open passes the parent env through untouched).
	 *
	 * @param string             $tool Relative path inside vendor/bin (no extension).
	 * @param array<int, string> $args Tool arguments.
	 * @return int Exit code.
	 */
	function stateflow_run_tool( string $tool, array $args = array() ): int {
		$root = dirname( __DIR__ );

		$command = array_merge(
			array( stateflow_php_binary(), $root . '/vendor/bin/' . $tool ),
			$args
		);

		$pipes = array();

		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root
		);

		if ( ! is_resource( $process ) ) {
			fwrite( STDERR, "Failed to start: {$tool}\n" );

			return 1;
		}

		fclose( $pipes[0] );

		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );

		$out = is_string( $out ) ? $out : '';
		$err = is_string( $err ) ? $err : '';

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		echo $out;
		fwrite( STDERR, $err );

		return (int) proc_close( $process );
	}
}

if ( ! function_exists( 'stateflow_suite' ) ) {
	/**
	 * Run a PHPUnit suite by config file.
	 *
	 * @param string $config PHPUnit config file.
	 * @return int Exit code.
	 */
	function stateflow_suite( string $config ): int {
		return stateflow_run_tool( 'phpunit', array( '-c', $config ) );
	}
}

if ( ! function_exists( 'stateflow_matrix' ) ) {
	/**
	 * Compatibility matrix: the present-process suite against several
	 * simulated WooCommerce versions (SF-001.1 item 7). Each version runs
	 * in its own PHPUnit process with a fresh WC_VERSION constant.
	 *
	 * @return int
	 */
	function stateflow_matrix(): int {
		$versions = array( '8.0.0', '8.7.0', '9.2.0', '10.1.0', '11.0.1' );

		$worst = 0;

		foreach ( $versions as $version ) {
			echo "--- WooCommerce {$version}: present-process suite", PHP_EOL;

			putenv( 'WC_TEST_VERSION=' . $version );
			$_ENV['WC_TEST_VERSION']    = $version;
			$_SERVER['WC_TEST_VERSION'] = $version;

			$code = stateflow_run_tool( 'phpunit', array( '-c', 'phpunit-present.xml.dist' ) );

			putenv( 'WC_TEST_VERSION' );

			$worst = max( $worst, $code );
		}

		return $worst;
	}
}

$script = $argv[1] ?? '';

exit(
	match ( $script ) {
		'lint'             => exit( ( include __DIR__ . '/lint.php' ) === false ? 1 : 0 ),
		'cs'               => stateflow_run_tool( 'phpcs' ),
		'cs:fix'           => stateflow_run_tool( 'phpcbf' ),
		'stan'             => stateflow_run_tool( 'phpstan', array( 'analyse', '--memory-limit=1G', '--no-progress' ) ),
		'test:unit'        => stateflow_suite( 'phpunit.xml.dist' ),
		'test:present'     => stateflow_suite( 'phpunit-present.xml.dist' ),
		'test:integration' => stateflow_suite( 'phpunit-integration.xml.dist' ),
		'test:matrix'      => stateflow_matrix(),
		default            => ( fwrite( STDERR, "Unknown script: {$script}" . PHP_EOL ) ? 1 : 1 ),
	}
);
