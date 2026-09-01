<?php
/**
 * Cross-platform PHP syntax lint over first-party code (src, tests, bin, stateflow.php).
 *
 * Vendor, tools and environment directories (.smoke, .git) are never scanned:
 * the lint scope is the code this repository owns. Exit code 1 on any parse
 * failure.
 *
 * @package StateFlow
 */

$root = dirname( __DIR__ );

$targets = array( 'src', 'tests', 'bin' );

$files = array();

foreach ( $targets as $target ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . $target, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		$files[] = str_replace( chr( 92 ), '/', $file->getPathname() );
	}
}

$files[] = str_replace( chr( 92 ), '/', $root ) . '/stateflow.php';

$files = array_values( array_unique( $files ) );
sort( $files );

$failures = 0;

foreach ( $files as $path ) {
	$output = array();
	exec(
		escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1',
		$output,
		$exit
	);

	if ( 0 !== $exit ) {
		++$failures;
		echo implode( PHP_EOL, $output ), PHP_EOL;
	}
}

printf( 'Linted %d PHP files, %d failure(s)%s', count( $files ), $failures, PHP_EOL );
exit( $failures ? 1 : 0 );
