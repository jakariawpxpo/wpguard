<?php

namespace WpGuard;

/**
 * Whole-plugin checks: main file header, readme.txt, and package contents.
 */
final class Checks {

	public static function run( Scanner $s, string $root, array $opts ): void {
		$main = self::mainFile( $root );
		$hdr  = array();
		if ( $main ) {
			$hdr = self::header( $root . '/' . $main, $main, $s, $opts );
		} else {
			$s->notes[] = 'No main plugin file with a "Plugin Name:" header found in the root.';
		}
		self::readme( $s, $root, $opts, $hdr );
		self::package( $s, $root, $opts );
	}

	private static function mainFile( string $root ): ?string {
		foreach ( glob( $root . '/*.php' ) ?: array() as $f ) {
			if ( preg_match( '/^\s*\*?\s*Plugin Name:/mi', (string) file_get_contents( $f, false, null, 0, 8192 ) ) ) {
				return basename( $f );
			}
		}
		return null;
	}

	private static function lineOf( array $lines, string $regex ): int {
		foreach ( $lines as $i => $l ) {
			if ( preg_match( $regex, $l ) ) {
				return $i;
			}
		}
		return 0;
	}

	private static function emit( Scanner $s, array $opts, string $id, string $sev, string $gl, string $title, string $why, string $fix, string $file, array $lines, int $i ): void {
		if ( in_array( $id, $opts['ignore_rules'], true ) ) {
			return;
		}
		$s->add(
			array(
				'id'        => $id,
				'severity'  => $sev,
				'guideline' => $gl,
				'title'     => $title,
				'why'       => $why,
				'fix'       => $fix,
				'noline'    => 0 === $i && ( 1 === count( $lines ) || 'WG-PACKAGE-FILE' === $id ),
			),
			$file,
			$lines,
			$i,
			0
		);
	}

	private static function header( string $path, string $rel, Scanner $s, array $opts ): array {
		$lines = preg_split( '/\R/', (string) file_get_contents( $path ) );
		$head  = implode( "\n", array_slice( $lines, 0, 40 ) );
		$get   = function ( string $k ) use ( $head ) {
			return preg_match( '/^[ \t\/*#@]*' . preg_quote( $k, '/' ) . ':[ \t]*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : '';
		};
		$h     = array(
			'name'    => $get( 'Plugin Name' ),
			'version' => $get( 'Version' ),
			'domain'  => $get( 'Text Domain' ),
			'license' => $get( 'License' ),
		);
		$nameL = self::lineOf( $lines, '/Plugin Name:/i' );

		if ( '' === $h['domain'] ) {
			self::emit( $s, $opts, 'WG-HEADER-TEXTDOMAIN', 'medium', 'Technical', 'Plugin header has no Text Domain', 'WordPress.org requires a text domain that matches the slug.', "Add `Text Domain: {$opts['slug']}`.", $rel, $lines, $nameL );
		} elseif ( $h['domain'] !== $opts['slug'] ) {
			self::emit( $s, $opts, 'WG-HEADER-TEXTDOMAIN', 'medium', 'Technical', "Text Domain '{$h['domain']}' differs from slug '{$opts['slug']}'", 'The text domain must equal the WordPress.org slug.', 'Change the header (and strings) or pass the right --slug.', $rel, $lines, self::lineOf( $lines, '/Text Domain:/i' ) );
		}
		if ( '' === $h['license'] ) {
			self::emit( $s, $opts, 'WG-HEADER-LICENSE', 'medium', '1', 'Plugin header has no License', 'Code must be declared GPL-compatible.', 'Add `License: GPLv2 or later` (or compatible) and a License URI.', $rel, $lines, $nameL );
		} elseif ( ! preg_match( '/GPL|GNU|MIT|BSD|Apache|MPL/i', $h['license'] ) ) {
			self::emit( $s, $opts, 'WG-HEADER-LICENSE', 'medium', '1', "License '{$h['license']}' is not recognisably GPL-compatible", 'All code must be GPL-compatible.', 'Use "GPLv2 or later".', $rel, $lines, self::lineOf( $lines, '/License:/i' ) );
		}
		if ( preg_match( '/^(wordpress|woocommerce|woo\b|wp\b|elementor|yoast|jetpack|gutenberg)/i', $h['name'] ) ) {
			self::emit( $s, $opts, 'WG-TRADEMARK', 'medium', '17', "Plugin name starts with a brand: '{$h['name']}'", 'A name must not begin with another project\'s trademark.', 'Write it as "… for WooCommerce".', $rel, $lines, $nameL );
		}
		return $h;
	}

	private static function readme( Scanner $s, string $root, array $opts, array $hdr ): void {
		$file = null;
		foreach ( array( 'readme.txt', 'README.txt' ) as $cand ) {
			if ( is_file( $root . '/' . $cand ) ) {
				$file = $cand;
				break;
			}
		}
		if ( null === $file ) {
			$s->add(
				array(
					'id'        => 'WG-README-MISSING',
					'severity'  => 'high',
					'guideline' => '12',
					'title'     => 'readme.txt is missing',
					'why'       => 'WordPress.org builds the plugin page from readme.txt.',
					'fix'       => 'Add a readme.txt following the standard format.',
					'noline'    => true,
				),
				'readme.txt',
				array( '(file not found)' ),
				0,
				0
			);
			return;
		}
		$lines = preg_split( '/\R/', (string) file_get_contents( $root . '/' . $file ) );
		$src   = implode( "\n", $lines );
		$field = function ( string $k ) use ( $lines ) {
			foreach ( $lines as $i => $l ) {
				if ( preg_match( '/^' . preg_quote( $k, '/' ) . ':\s*(.*)$/i', $l, $m ) ) {
					return array( trim( $m[1] ), $i );
				}
			}
			return array( '', 0 );
		};

		list( $tags, $tagL ) = $field( 'Tags' );
		$tagList             = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
		if ( count( $tagList ) > 5 ) {
			self::emit( $s, $opts, 'WG-README-TAGS', 'medium', '12', 'More than 5 tags (' . count( $tagList ) . ')', 'Only the first 5 tags count; stuffing is a violation.', 'Keep at most 5 relevant tags.', $file, $lines, $tagL );
		}
		list( $stable, $stableL ) = $field( 'Stable tag' );
		if ( '' === $stable ) {
			self::emit( $s, $opts, 'WG-README-STABLE', 'medium', 'Technical', 'readme.txt has no Stable tag', 'WordPress.org needs it to serve the right version.', 'Add `Stable tag: ' . ( $hdr['version'] ?? 'x.y.z' ) . '`.', $file, $lines, 0 );
		} elseif ( ! empty( $hdr['version'] ) && $stable !== $hdr['version'] ) {
			self::emit( $s, $opts, 'WG-README-STABLE', 'low', 'Technical', "Stable tag $stable differs from plugin Version {$hdr['version']}", 'Versions in readme and header should agree at release.', 'Update the one that is behind.', $file, $lines, $stableL );
		}
		foreach ( array( 'Tested up to', 'License', 'Requires at least' ) as $k ) {
			list( $v ) = $field( $k );
			if ( '' === $v ) {
				self::emit( $s, $opts, 'WG-README-FIELD', 'low', 'Technical', "readme.txt has no '$k'", 'Standard readme field is missing.', "Add `$k:`.", $file, $lines, 0 );
			}
		}
		list( $lic ) = $field( 'License' );
		if ( '' !== $lic && ! preg_match( '/GPL|GNU|MIT|BSD|Apache|MPL/i', $lic ) ) {
			self::emit( $s, $opts, 'WG-README-LICENSE', 'medium', '1', "readme License '$lic' is not recognisably GPL-compatible", 'All code must be GPL-compatible.', 'Use "GPLv2 or later".', $file, $lines, $field( 'License' )[1] );
		}

		// A service is only documented if the readme says so.
		$outbound = array_filter(
			$s->findings,
			function ( $f ) {
				return in_array( $f['rule'], array( 'WG-REMOTE-PHP', 'WG-JS-REMOTE-CALL', 'WG-TRACKING', 'WG-REMOTE-ASSET' ), true );
			}
		);
		if ( $outbound && ! preg_match( '/^==\s*External services\s*==/mi', $src ) ) {
			self::emit( $s, $opts, 'WG-README-SERVICES', 'high', '6/7', 'Outbound calls found but readme has no "External services" section (' . count( $outbound ) . ' call site(s))', 'Every external service must be documented with data sent, when, and terms/privacy links.', 'Add `== External services ==` once each call is confirmed real.', $file, $lines, 0 );
		}

		// Minified/compiled JS needs a pointer to the readable source.
		$minified = false;
		foreach ( $s->files as $f ) {
			if ( preg_match( '#^assets/.*\.(js|css)$#', $f ) && filesize( $root . '/' . $f ) > 20000 ) {
				$minified = true;
				break;
			}
		}
		if ( $minified && ! preg_match( '/source code|github\.com|bitbucket\.org|gitlab\.com/i', $src ) ) {
			self::emit( $s, $opts, 'WG-README-SOURCE', 'medium', '4', 'Compiled assets ship but readme does not link the human-readable source', 'Minified code is allowed only when the source is public and linked.', 'Add a "Source Code" section with the public repository URL.', $file, $lines, 0 );
		}
	}

	private static function package( Scanner $s, string $root, array $opts ): void {
		$bad = '/\.(sh|phar|zip|tar|tgz|gz|log|sql|bak|orig|swp|env|pem|key)$|(^|\/)(\.env|\.DS_Store|Thumbs\.db|composer\.phar)$/i';
		foreach ( $s->files as $f ) {
			$why = null;
			if ( preg_match( $bad, $f ) ) {
				$why = 'Build, backup, or tool file that should not ship in a release ZIP.';
			} elseif ( false !== strpos( basename( $f ), ' ' ) ) {
				$why = 'Spaces in a filename break extraction on some systems.';
			} else {
				continue;
			}
			$size = number_format( filesize( $root . '/' . $f ) / 1024, 1 ) . ' KB';
			self::emit( $s, $opts, 'WG-PACKAGE-FILE', 'medium', 'Technical', "Suspicious file in package: $f", $why, 'Exclude it from the release build (verify your build excludes it).', $f, array( "(file, $size)" ), 0 );
		}
		foreach ( array( 'node_modules', 'build', '.git', 'tests' ) as $d ) {
			if ( is_dir( $root . '/' . $d ) ) {
				$s->notes[] = "$d/ exists in this directory and was not scanned; make sure the release ZIP excludes it.";
			}
		}
	}
}
