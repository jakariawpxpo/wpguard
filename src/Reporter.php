<?php

namespace WpGuard;

final class Reporter {

	public const ORDER = array(
		'high'   => 3,
		'medium' => 2,
		'low'    => 1,
		'info'   => 0,
	);

	/** @var bool */
	private $color;

	public function __construct( bool $color ) {
		$this->color = $color;
	}

	private function c( string $text, string $code ): string {
		return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
	}

	private function sev( string $s ): string {
		$map = array(
			'high'   => '1;31',
			'medium' => '33',
			'low'    => '36',
			'info'   => '90',
		);
		return $this->c( strtoupper( str_pad( $s, 6 ) ), $map[ $s ] );
	}

	/** One progress character per scanned file: "." clean, "F" for a finding (colored by severity). */
	public function dot( string $worst ): string {
		if ( 'none' === $worst ) {
			return $this->c( '.', '32' );
		}
		$map = array(
			'high'   => '1;31',
			'medium' => '33',
			'low'    => '36',
			'info'   => '90',
		);
		return $this->c( 'F', $map[ $worst ] );
	}

	public static function counts( array $findings ): array {
		$c = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
			'info'   => 0,
		);
		foreach ( $findings as $f ) {
			$c[ $f['severity'] ]++;
		}
		return $c;
	}

	public function text( array $findings, array $meta, bool $brief ): string {
		$o  = $this->c( "wpguard {$meta['version']}", '1' ) . " — {$meta['root']} (slug: {$meta['slug']})\n";
		$o .= "Scanned {$meta['files']} files in " . number_format( $meta['time'], 2 ) . "s.\n\n";

		$byFile = array();
		foreach ( $findings as $f ) {
			$byFile[ $f['file'] ][] = $f;
		}
		ksort( $byFile );

		foreach ( $byFile as $file => $items ) {
			usort(
				$items,
				function ( $a, $b ) {
					return $a['line'] <=> $b['line'];
				}
			);
			$o .= $this->c( $file, '1;4' ) . $this->c( ' (' . count( $items ) . ")\n", '90' );
			foreach ( $items as $f ) {
				$loc = $f['line'] > 0 ? "$file:{$f['line']}:{$f['column']}" : $file;
				$o  .= '  ' . $this->sev( $f['severity'] ) . ' ' . $this->c( $f['rule'], '1' )
					. $this->c( "  [Guideline {$f['guideline']}]", '90' ) . "  {$f['title']}\n";
				$o  .= '    ' . $this->c( $loc, '34' ) . "\n";
				foreach ( $f['code'] as $row ) {
					list( $n, $text, $hit ) = $row;
					$gutter                 = str_pad( $n > 0 ? (string) $n : '', 5, ' ', STR_PAD_LEFT ) . ' | ';
					$mark                   = $hit ? $this->c( '>', '1;31' ) : ' ';
					$o                     .= "    $mark " . $this->c( $gutter, '90' ) . ( $hit ? $this->c( $text, '1' ) : $this->c( $text, '90' ) ) . "\n";
				}
				if ( ! $brief ) {
					$o .= '      why: ' . $f['why'] . "\n";
					$o .= '      fix: ' . $f['fix'] . "\n";
				}
				$o .= "\n";
			}
		}

		$cnt = self::counts( $findings );
		$o  .= str_repeat( '─', 60 ) . "\n";
		$o  .= sprintf(
			"%s  %d high   %d medium   %d low   %d info   (%d total)\n",
			$this->c( 'Summary', '1' ),
			$cnt['high'],
			$cnt['medium'],
			$cnt['low'],
			$cnt['info'],
			count( $findings )
		);
		foreach ( $meta['notes'] as $n ) {
			$o .= $this->c( "note: $n", '90' ) . "\n";
		}
		$o .= $this->c( "Findings are review candidates, not verdicts. Suppress a reviewed line with `// wpguard-ignore: RULE-ID`.\n", '90' );
		return $o;
	}

	public function json( array $findings, array $meta ): string {
		return json_encode(
			array(
				'tool'     => 'wpguard',
				'version'  => $meta['version'],
				'root'     => $meta['root'],
				'slug'     => $meta['slug'],
				'summary'  => self::counts( $findings ),
				'seconds'  => round( $meta['time'], 3 ),
				'notes'    => $meta['notes'],
				'findings' => $findings,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";
	}
}
