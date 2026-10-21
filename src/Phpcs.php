<?php

namespace WpGuard;

/**
 * Runs PHPCS (WordPress Coding Standards) and converts its JSON into findings.
 */
final class Phpcs {

	/** @var string[] */
	public $notes = array();

	public function run( string $root, bool $strict, ?string $standard, bool $tests = false ): array {
		$bin = $this->binary( $root );
		if ( null === $bin ) {
			$this->notes[] = 'PHPCS not found (install squizlabs/php_codesniffer + wp-coding-standards/wpcs, or use --no-phpcs).';
			return array();
		}
		if ( null === $standard ) {
			foreach ( array( 'phpcs.xml', '.phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml.dist' ) as $c ) {
				if ( is_file( "$root/$c" ) ) {
					$standard = "$root/$c";
					break;
				}
			}
			$standard = $standard ?? 'WordPress';
		}

		$cmd = array(
			$bin,
			'--report=json',
			'-q',
			'--extensions=php',
			'--standard=' . $standard,
			'--ignore=*/node_modules/*,*/vendor/*,*/build/*,*/assets/*' . ( $tests ? '' : ',*/tests/*,*/test/*,*/__tests__/*,*Test.php,*TestCase.php' ),
		);
		if ( $strict ) {
			$cmd[] = '--ignore-annotations';
		}
		$cmd[] = $root;

		$proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $proc ) ) {
			$this->notes[] = 'Could not start PHPCS.';
			return array();
		}
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		proc_close( $proc );

		$data = json_decode( (string) $out, true );
		if ( ! is_array( $data ) || ! isset( $data['files'] ) ) {
			$first         = trim( strtok( trim( $err . "\n" . $out ), "\n" ) ?: 'no output' );
			$this->notes[] = 'PHPCS did not produce a report: ' . $first;
			return array();
		}

		$findings = array();
		foreach ( $data['files'] as $path => $info ) {
			$rel   = ltrim( str_replace( $root, '', $path ), '/' );
			$lines = is_file( $path ) ? preg_split( '/\R/', (string) file_get_contents( $path ) ) : array();
			foreach ( $info['messages'] as $m ) {
				$findings[] = Scanner::finding(
					array(
						'id'        => 'PHPCS:' . $m['source'],
						'severity'  => 'ERROR' === $m['type'] ? 'medium' : 'low',
						'guideline' => 'WPCS',
						'title'     => $m['message'],
						'why'       => 'WordPress Coding Standards' . ( $strict ? ' (annotations ignored)' : '' ) . '.',
						'fix'       => $m['fixable'] ? 'Auto-fixable: run phpcbf.' : 'Fix per the sniff message.',
					),
					$rel,
					$lines ?: array( '' ),
					max( 0, (int) $m['line'] - 1 ),
					max( 0, (int) $m['column'] - 1 )
				);
				$findings[ count( $findings ) - 1 ]['source'] = 'phpcs';
			}
		}
		return $findings;
	}

	private function binary( string $root ): ?string {
		foreach ( array( "$root/vendor/bin/phpcs" ) as $p ) {
			if ( is_executable( $p ) ) {
				return $p;
			}
		}
		$found = trim( (string) shell_exec( 'command -v phpcs 2>/dev/null' ) );
		return '' !== $found ? $found : null;
	}
}
