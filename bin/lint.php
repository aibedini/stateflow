<?php
/**
 * Cross-platform recursive PHP syntax lint.
 *
 * Excludes vendor/ and tools/ (third-party and local toolchain).
 * Exit code 1 when any file fails to parse.
 *
 * @package StateFlow
 */

$root = dirname( __DIR__ );

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

$files = array();

foreach ( $iterator as $file ) {
	if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$path = str_replace( '\\', '/', $file->getPathname() );

	if ( false !== strpos( $path, '/vendor/' ) || false !== strpos( $path, '/tools/' ) ) {
		continue;
	}

	$files[] = $path;
}

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
		echo implode( "\n", $output ), "\n";
	}
}

printf( "Linted %d PHP files, %d failure(s).\n", count( $files ), $failures );
exit( $failures ? 1 : 0 );
