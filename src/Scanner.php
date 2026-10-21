<?php

namespace WpGuard;

final class Scanner {

	private const SKIP_DIRS   = array( 'node_modules', '.git', '.svn', 'build', '.idea', '.vscode' );
	private const MAX_BYTES   = 3000000;
	private const SCAN_EXT    = array( 'php', 'js', 'jsx', 'ts', 'tsx', 'mjs', 'css', 'scss' );
	private const CONTEXT     = 1;
	private const EXCERPT_MAX = 170;

	/** @var string */
	private $root;
	/** @var array */
	private $opts;
	/** @var array */
	private $rules;
	/** @var array */
	public $findings = array();
	/** @var array */
	public $files = array();
	/** @var string[] */
	public $notes = array();

	public function __construct( string $root, array $opts ) {
		$this->root  = rtrim( $root, '/' );
		$this->opts  = $opts;
		$this->rules = array_values(
			array_filter(
				Rules::all(),
				function ( $r ) use ( $opts ) {
					return ! in_array( $r['id'], $opts['ignore_rules'], true );
				}
			)
		);
	}

	public function run(): void {
		$this->files = $this->collect( $this->root );
		$hasSrc      = is_dir( $this->root . '/src' ) && is_file( $this->root . '/package.json' );

		foreach ( $this->files as $rel ) {
			$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::SCAN_EXT, true ) ) {
				continue;
			}
			// Compiled output duplicates src/; report the source, not the bundle.
			if ( $hasSrc && ! $this->opts['include_build'] && 'php' !== $ext && 0 === strpos( $rel, 'assets/' ) ) {
				continue;
			}
			if ( 'php' !== $ext && $this->opts['skip_js'] ) {
				continue;
			}
			$path = $this->root . '/' . $rel;
			if ( filesize( $path ) > self::MAX_BYTES ) {
				$this->notes[] = "Skipped (over 3 MB): $rel";
				continue;
			}
			$lines  = preg_split( '/\R/', (string) file_get_contents( $path ) );
			$before = count( $this->findings );
			$this->scanFile( $rel, $ext, $lines );
			if ( isset( $this->opts['progress'] ) ) {
				$worst = 'none';
				foreach ( array_slice( $this->findings, $before ) as $f ) {
					if ( Reporter::ORDER[ $f['severity'] ] < Reporter::ORDER[ $this->opts['min'] ] || ( $this->opts['only'] && ! in_array( $f['rule'], $this->opts['only'], true ) ) ) {
						continue;
					}
					if ( 'none' === $worst || Reporter::ORDER[ $f['severity'] ] > Reporter::ORDER[ $worst ] ) {
						$worst = $f['severity'];
					}
				}
				( $this->opts['progress'] )( $worst );
			}
		}
		if ( $hasSrc && ! $this->opts['include_build'] ) {
			$this->notes[] = 'assets/ JS and CSS skipped because src/ exists (use --include-build to scan compiled output).';
		}
	}

	private function scanFile( string $rel, string $ext, array $lines ): void {
		$inBlock = false;
		foreach ( $lines as $i => $line ) {
			$trim = ltrim( $line );

			// Track block comments so commented-out code is not reported.
			$isComment = false;
			if ( $inBlock ) {
				$isComment = true;
				if ( false !== strpos( $line, '*/' ) ) {
					$inBlock = false;
				}
			} elseif ( 0 === strpos( $trim, '/*' ) ) {
				$isComment = true;
				$inBlock   = false === strpos( $line, '*/' );
			} elseif ( 0 === strpos( $trim, '//' ) || 0 === strpos( $trim, '#' ) || 0 === strpos( $trim, '*' ) ) {
				$isComment = true;
			}

			foreach ( $this->rules as $rule ) {
				if ( ! in_array( $ext, $rule['ext'], true ) ) {
					continue;
				}
				if ( $isComment && 'WG-PHPCS-IGNORE' !== $rule['id'] ) {
					continue;
				}
				if ( ! preg_match( $rule['pattern'], $line, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				if ( ! empty( $rule['unless'] ) && preg_match( $rule['unless'], $line ) ) {
					continue;
				}
				if ( $this->suppressed( $lines, $i, $rule['id'] ) ) {
					continue;
				}
				$this->add( $rule, $rel, $lines, $i, $m[0][1] );
			}
		}

		if ( 'php' === $ext ) {
			$this->phpFileChecks( $rel, $lines );
		}
	}

	private function phpFileChecks( string $rel, array $lines ): void {
		$src = implode( "\n", $lines );

		if ( ! in_array( 'WG-NO-ABSPATH', $this->opts['ignore_rules'], true ) && '.asset.php' !== substr( $rel, -10 ) && ! preg_match( '/ABSPATH|WPINC/', $src ) ) {
			// Files that only hold declarations are low risk; files with top-level calls are not.
			$this->add(
				array(
					'id'        => 'WG-NO-ABSPATH',
					'severity'  => 'low',
					'guideline' => 'Technical',
					'title'     => 'PHP file has no ABSPATH guard',
					'why'       => 'A file opened directly by URL should exit before running code.',
					'fix'       => "Add `defined( 'ABSPATH' ) || exit;` after the namespace/use lines.",
				),
				$rel,
				$lines,
				0,
				0
			);
		}

		$starts = preg_match_all( '/\bob_start\s*\(/', $src );
		$ends   = preg_match_all( '/\bob_(get_clean|end_clean|end_flush|get_flush)\s*\(/', $src );
		if ( $starts > $ends && ! in_array( 'WG-OB-UNBALANCED', $this->opts['ignore_rules'], true ) ) {
			foreach ( $lines as $i => $line ) {
				if ( preg_match( '/\bob_start\s*\(/', $line ) ) {
					$this->add(
						array(
							'id'        => 'WG-OB-UNBALANCED',
							'severity'  => 'medium',
							'guideline' => 'Technical',
							'title'     => "ob_start() without a matching close in this file ($starts open, $ends close)",
							'why'       => 'An unclosed output buffer breaks other plugins and output.',
							'fix'       => 'Close it on every code path with ob_get_clean() / ob_end_clean().',
						),
						$rel,
						$lines,
						$i,
						0
					);
				}
			}
		}

		if ( ! $this->opts['textdomain'] ) {
			return;
		}
		$re = '/\b(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(?:\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|\$\w+|\d+))*?\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';
		foreach ( $lines as $i => $line ) {
			if ( preg_match_all( $re, $line, $mm, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $mm[1] as $k => $dom ) {
					if ( $dom[0] !== $this->opts['textdomain'] && ! $this->suppressed( $lines, $i, 'WG-TEXTDOMAIN' ) ) {
						$this->add(
							array(
								'id'        => 'WG-TEXTDOMAIN',
								'severity'  => 'medium',
								'guideline' => 'Technical',
								'title'     => "Text domain '{$dom[0]}' differs from '{$this->opts['textdomain']}'",
								'why'       => 'The text domain must match the plugin slug for translations to load.',
								'fix'       => "Use '{$this->opts['textdomain']}' in plugin-owned strings.",
							),
							$rel,
							$lines,
							$i,
							$mm[0][ $k ][1]
						);
					}
				}
			}
		}
	}

	private function suppressed( array $lines, int $i, string $id ): bool {
		foreach ( array( $i, $i - 1 ) as $n ) {
			if ( isset( $lines[ $n ] ) && preg_match( '/wpguard-ignore(?::\s*([A-Z0-9, -]+))?/', $lines[ $n ], $m ) ) {
				if ( empty( $m[1] ) || false !== strpos( $m[1], $id ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Build a finding with a code excerpt around line $i (0-based). */
	public function add( array $rule, string $rel, array $lines, int $i, int $col ): void {
		$this->findings[] = self::finding( $rule, $rel, $lines, $i, $col );
	}

	public static function finding( array $rule, string $rel, array $lines, int $i, int $col ): array {
		$ctx = array();
		$lo  = max( 0, $i - self::CONTEXT );
		$hi  = min( count( $lines ) - 1, $i + self::CONTEXT );
		for ( $n = $lo; $n <= $hi; $n++ ) {
			$text = rtrim( str_replace( "\t", '    ', $lines[ $n ] ) );
			$hit  = ( $n === $i );
			if ( strlen( $text ) > self::EXCERPT_MAX ) {
				// Long (minified) lines: window around the match.
				$start = $hit ? max( 0, $col - 60 ) : 0;
				$text  = ( $start > 0 ? '…' : '' ) . substr( $text, $start, self::EXCERPT_MAX ) . '…';
			}
			$ctx[] = array( $n + 1, $text, $hit );
		}
		$whole = ! empty( $rule['noline'] );
		return array(
			'rule'      => $rule['id'],
			'severity'  => $rule['severity'],
			'guideline' => $rule['guideline'],
			'title'     => $rule['title'],
			'file'      => $rel,
			'line'      => $whole ? 0 : $i + 1,
			'column'    => $whole ? 0 : $col + 1,
			'code'      => $whole ? array( array( 0, $lines[0] ?? '', true ) ) : $ctx,
			'why'       => $rule['why'],
			'fix'       => $rule['fix'],
			'source'    => 'wpguard',
		);
	}

	private function collect( string $dir, string $prefix = '' ): array {
		$out   = array();
		$items = scandir( $dir );
		sort( $items );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full = $dir . '/' . $item;
			$rel  = $prefix . $item;
			if ( is_dir( $full ) ) {
				if ( in_array( $item, self::SKIP_DIRS, true ) || ( 'vendor' === $item && ! $this->opts['include_vendor'] ) ) {
					continue;
				}
				if ( $this->pathIgnored( $rel . '/' ) ) {
					continue;
				}
				$out = array_merge( $out, $this->collect( $full, $rel . '/' ) );
			} elseif ( ! $this->pathIgnored( $rel ) ) {
				$out[] = $rel;
			}
		}
		return $out;
	}

	private function pathIgnored( string $rel ): bool {
		foreach ( $this->opts['ignore_paths'] as $p ) {
			if ( '' !== $p && fnmatch( $p, $rel ) ) {
				return true;
			}
			if ( '' !== $p && 0 === strpos( $rel, rtrim( $p, '*' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
