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
		$standard = $this->standard( $root, $standard );

		$cmd = array(
			$bin,
			'--report=json',
			'-q',
			'--extensions=php',
			'--standard=' . $standard,
			'--ignore=' . $this->ignore( $root, $tests ),
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
						'fix'       => $this->fix_hint( $m ),
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

	/**
	 * Sniffs whose fix would turn == into === (a behaviour change), so --fix never runs them.
	 */
	private const NO_AUTOFIX = array(
		'Universal.Operators.StrictComparisons',
		'WordPress.PHP.StrictComparisons',
	);

	/** Runs phpcbf with the same standard and ignores as run(), minus the sniffs in NO_AUTOFIX. Returns phpcbf's exit code, or null if it is unavailable. */
	public function fix( string $root, bool $strict, ?string $standard, bool $tests = false ): ?int {
		$bin = $this->binary( $root );
		$fix = null === $bin ? '' : dirname( $bin ) . '/phpcbf';
		if ( ! is_executable( $fix ) ) {
			$fix = trim( (string) shell_exec( 'command -v phpcbf 2>/dev/null' ) );
		}
		if ( '' === $fix ) {
			$this->notes[] = 'phpcbf not found (it ships with squizlabs/php_codesniffer); --fix skipped.';
			return null;
		}

		$cmd = array(
			$fix,
			'--extensions=php',
			'--standard=' . $this->standard( $root, $standard ),
			'--exclude=' . implode( ',', self::NO_AUTOFIX ),
			'--ignore=' . $this->ignore( $root, $tests ),
		);
		if ( $strict ) {
			$cmd[] = '--ignore-annotations';
		}
		$cmd[] = $root;

		$proc = proc_open( $cmd, array( 1 => STDERR, 2 => STDERR ), $pipes );
		return is_resource( $proc ) ? proc_close( $proc ) : null;
	}

	/** Loose-comparison sniffs are fixable by phpcbf, but --fix skips them (see NO_AUTOFIX), so they must not be advertised as auto-fixable. */
	private function fix_hint( array $m ): string {
		foreach ( self::NO_AUTOFIX as $sniff ) {
			if ( 0 === strpos( $m['source'], $sniff ) ) {
				return 'Fix by hand: use === / !== after checking both sides have the same type. Not auto-fixed because it can change behaviour.';
			}
		}
		return $m['fixable'] ? 'Auto-fixable: run wpguard --fix.' : 'Fix per the sniff message.';
	}

	private function standard( string $root, ?string $standard ): string {
		if ( null === $standard ) {
			foreach ( array( 'phpcs.xml', '.phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml.dist' ) as $c ) {
				if ( is_file( "$root/$c" ) ) {
					return "$root/$c";
				}
			}
			return 'WordPress';
		}
		return $standard;
	}

	/** Patterns are anchored to the scanned root: PHPCS matches them against absolute paths, so an unanchored pattern would drop every file of a plugin that lives under a folder named test. */
	private function ignore( string $root, bool $tests ): string {
		$root = rtrim( (string) ( realpath( $root ) ?: $root ), '/' );
		$dirs = array( 'node_modules', 'vendor', 'build', 'assets' );
		if ( ! $tests ) {
			$dirs = array_merge( $dirs, array( 'tests', 'test', '__tests__' ) );
		}
		$patterns = array();
		foreach ( $dirs as $d ) {
			$patterns[] = "$root/$d/*";
			$patterns[] = "$root/*/$d/*";
		}
		if ( ! $tests ) {
			$patterns[] = '*Test.php';
			$patterns[] = '*TestCase.php';
		}
		return implode( ',', $patterns );
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
