<?php
/**
 * Lecteur PDF minimal pour les feuilles de match FDME : texte positionné.
 *
 * Les FDME sont chiffrées (AES-256, révision 5 ou 6) avec un mot de passe
 * utilisateur vide, et écrites en Helvetica (WinAnsi) : pas besoin d'une
 * bibliothèque complète. Pour chaque page : liste d'éléments
 * array( 'x' => float, 'y' => float, 't' => texte UTF-8 ), y croissant vers le bas.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Pdf {

	/** @var string */
	private $raw;
	/** @var string|null clé de déchiffrement du fichier, null si non chiffré */
	private $key = null;
	/** @var array<int,array{dict:string,stream:?string}> */
	private $objs = array();

	/**
	 * @param string $raw contenu du fichier PDF
	 * @throws RuntimeException fichier illisible ou chiffrement non pris en charge
	 */
	public function __construct( $raw ) {
		if ( ! is_string( $raw ) || '%PDF' !== substr( $raw, 0, 4 ) ) {
			throw new RuntimeException( 'Ce fichier n\'est pas un PDF.' );
		}
		$this->raw = $raw;
		$this->index();
		$this->encryption();
	}

	/** Repère les objets « n g obj … endobj » (le dernier l'emporte, comme une mise à jour incrémentale). */
	private function index() {
		if ( ! preg_match_all( '/(\d+)\s+(\d+)\s+obj\b/', $this->raw, $m, PREG_OFFSET_CAPTURE ) ) {
			throw new RuntimeException( 'PDF sans objet lisible.' );
		}
		$n = count( $m[0] );
		for ( $i = 0; $i < $n; $i++ ) {
			$num   = (int) $m[1][ $i ][0];
			$start = $m[0][ $i ][1] + strlen( $m[0][ $i ][0] );
			$end   = strpos( $this->raw, 'endobj', $start );
			if ( false === $end ) {
				continue;
			}
			$body   = substr( $this->raw, $start, $end - $start );
			$stream = null;
			$sp     = strpos( $body, 'stream' );
			$dict   = $body;
			if ( false !== $sp ) {
				$dict = substr( $body, 0, $sp );
				$data = substr( $body, $sp + 6 );
				$data = preg_replace( '/^\r?\n/', '', $data );
				$len  = preg_match( '#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dict, $lm ) ? (int) $lm[1] : -1;
				if ( $len >= 0 && $len <= strlen( $data ) ) {
					$stream = substr( $data, 0, $len );
				} else {
					$e      = strrpos( $data, 'endstream' );
					$stream = false !== $e ? rtrim( substr( $data, 0, $e ), "\r\n" ) : $data;
				}
			}
			$this->objs[ $num ] = array(
				'dict'   => $dict,
				'stream' => $stream,
			);
		}
	}

	/** Chaîne PDF d'un dictionnaire : hexadécimale <…> ou littérale (…). */
	private static function dict_string( $dict, $key ) {
		if ( preg_match( '#/' . $key . '\s*<([0-9A-Fa-f\s]*)>#', $dict, $m ) ) {
			return (string) hex2bin( preg_replace( '/\s+/', '', $m[1] ) );
		}
		if ( preg_match( '#/' . $key . '\s*\(#', $dict, $m, PREG_OFFSET_CAPTURE ) ) {
			$p = $m[0][1] + strlen( $m[0][0] ) - 1;
			return self::literal( $dict, $p );
		}
		return '';
	}

	private function encryption() {
		if ( ! preg_match( '#/Encrypt\s+(\d+)\s+\d+\s+R#', $this->raw, $m ) ) {
			return;
		}
		$dict = '';
		foreach ( $this->objs as $o ) {
			if ( preg_match( '#/Filter\s*/Standard#', $o['dict'] ) ) {
				$dict = $o['dict'];
			}
		}
		if ( '' === $dict ) {
			throw new RuntimeException( 'Chiffrement du PDF introuvable.' );
		}
		$r = preg_match( '#/R\s+(\d+)#', $dict, $rm ) ? (int) $rm[1] : 0;
		if ( 5 !== $r ) {
			if ( 6 !== $r ) {
				throw new RuntimeException( 'Chiffrement PDF non pris en charge (révision ' . $r . ').' );
			}
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			throw new RuntimeException( 'L\'extension PHP OpenSSL est nécessaire pour lire les feuilles de match.' );
		}
		$u  = self::dict_string( $dict, 'U' );
		$ue = self::dict_string( $dict, 'UE' );
		if ( strlen( $u ) < 48 || strlen( $ue ) < 32 ) {
			throw new RuntimeException( 'Chiffrement du PDF incomplet.' );
		}
		$pw   = ''; // mot de passe utilisateur vide
		$salt = substr( $u, 32, 8 );
		$ksal = substr( $u, 40, 8 );
		if ( 5 === $r ) {
			if ( hash( 'sha256', $pw . $salt, true ) !== substr( $u, 0, 32 ) ) {
				throw new RuntimeException( 'Ce PDF est protégé par un mot de passe.' );
			}
			$ik = hash( 'sha256', $pw . $ksal, true );
		} else {
			if ( self::hash6( $pw, $salt, '' ) !== substr( $u, 0, 32 ) ) {
				throw new RuntimeException( 'Ce PDF est protégé par un mot de passe.' );
			}
			$ik = self::hash6( $pw, $ksal, '' );
		}
		$key = openssl_decrypt( substr( $ue, 0, 32 ), 'aes-256-cbc', $ik, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat( "\0", 16 ) );
		if ( false === $key || 32 !== strlen( $key ) ) {
			throw new RuntimeException( 'Déchiffrement du PDF impossible.' );
		}
		$this->key = $key;
	}

	/** Algorithme 2.B (PDF 2.0, révision 6). */
	private static function hash6( $pw, $salt, $udata ) {
		$k = hash( 'sha256', $pw . $salt . $udata, true );
		for ( $i = 0; ; $i++ ) {
			$k1 = str_repeat( $pw . $k . $udata, 64 );
			$e  = openssl_encrypt( $k1, 'aes-128-cbc', substr( $k, 0, 16 ), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr( $k, 16, 16 ) );
			$s  = 0;
			for ( $j = 0; $j < 16; $j++ ) {
				$s += ord( $e[ $j ] );
			}
			$algo = array( 'sha256', 'sha384', 'sha512' )[ $s % 3 ];
			$k    = hash( $algo, $e, true );
			if ( $i >= 63 ) {
				if ( ord( substr( $e, -1 ) ) <= $i - 32 ) {
					break;
				}
			}
		}
		return substr( $k, 0, 32 );
	}

	/** Contenu décodé d'un flux (déchiffré puis décompressé). */
	private function stream( $num ) {
		if ( ! isset( $this->objs[ $num ] ) || null === $this->objs[ $num ]['stream'] ) {
			return '';
		}
		$o = $this->objs[ $num ];
		$s = $o['stream'];
		if ( null !== $this->key ) {
			if ( strlen( $s ) < 32 ) {
				return '';
			}
			$s = openssl_decrypt( substr( $s, 16 ), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, substr( $s, 0, 16 ) );
			if ( false === $s ) {
				return '';
			}
		}
		if ( preg_match( '#/FlateDecode#', $o['dict'] ) ) {
			$d = @gzuncompress( $s ); // phpcs:ignore
			if ( false === $d ) {
				$d = @gzinflate( substr( $s, 2 ) ); // phpcs:ignore
			}
			$s = false === $d ? '' : $d;
		}
		return $s;
	}

	/** Numéros des objets page, dans l'ordre du document. */
	private function pages() {
		$root = preg_match( '#/Root\s+(\d+)\s+\d+\s+R#', $this->raw, $m ) ? (int) $m[1] : 0;
		$top  = ( $root && isset( $this->objs[ $root ] ) && preg_match( '#/Pages\s+(\d+)\s+\d+\s+R#', $this->objs[ $root ]['dict'], $pm ) ) ? (int) $pm[1] : 0;
		$out  = array();
		$walk = function ( $n, $depth ) use ( &$walk, &$out ) {
			if ( $depth > 10 || ! isset( $this->objs[ $n ] ) ) {
				return;
			}
			$d = $this->objs[ $n ]['dict'];
			if ( preg_match( '#/Kids\s*\[([^\]]*)\]#', $d, $km ) ) {
				preg_match_all( '#(\d+)\s+\d+\s+R#', $km[1], $kids );
				foreach ( $kids[1] as $k ) {
					$walk( (int) $k, $depth + 1 );
				}
			} elseif ( preg_match( '#/Type\s*/Page\b#', $d ) ) {
				$out[] = $n;
			}
		};
		if ( $top ) {
			$walk( $top, 0 );
		}
		if ( ! $out ) {
			foreach ( $this->objs as $n => $o ) {
				if ( preg_match( '#/Type\s*/Page\b#', $o['dict'] ) ) {
					$out[] = $n;
				}
			}
		}
		return $out;
	}

	/**
	 * Texte de chaque page.
	 *
	 * @return array<int,array<int,array{x:float,y:float,t:string}>>
	 */
	public function text() {
		$out = array();
		foreach ( $this->pages() as $p ) {
			$d = $this->objs[ $p ]['dict'];
			$c = '';
			if ( preg_match( '#/Contents\s*\[([^\]]*)\]#', $d, $cm ) ) {
				preg_match_all( '#(\d+)\s+\d+\s+R#', $cm[1], $refs );
				foreach ( $refs[1] as $r ) {
					$c .= $this->stream( (int) $r ) . "\n";
				}
			} elseif ( preg_match( '#/Contents\s+(\d+)\s+\d+\s+R#', $d, $cm ) ) {
				$c = $this->stream( (int) $cm[1] );
			}
			$h = preg_match( '#/MediaBox\s*\[\s*[-0-9.]+\s+[-0-9.]+\s+[-0-9.]+\s+([-0-9.]+)\s*\]#', $d, $mb ) ? (float) $mb[1] : 842.0;
			$out[] = self::interpret( $c, $h );
		}
		return $out;
	}

	/** Chaîne littérale à partir de la parenthèse ouvrante en $p ; $p avance après la fermante. */
	private static function literal( $s, &$p ) {
		$out   = '';
		$depth = 0;
		$n     = strlen( $s );
		for ( ++$p; $p < $n; $p++ ) {
			$ch = $s[ $p ];
			if ( '\\' === $ch ) {
				$nx = $s[ ++$p ] ?? '';
				$map = array( 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c" );
				if ( isset( $map[ $nx ] ) ) {
					$out .= $map[ $nx ];
				} elseif ( ctype_digit( $nx ) ) {
					$oct = $nx;
					while ( strlen( $oct ) < 3 && isset( $s[ $p + 1 ] ) && ctype_digit( $s[ $p + 1 ] ) ) {
						$oct .= $s[ ++$p ];
					}
					$out .= chr( octdec( $oct ) & 255 );
				} elseif ( "\r" === $nx || "\n" === $nx ) {
					if ( "\r" === $nx && "\n" === ( $s[ $p + 1 ] ?? '' ) ) {
						++$p;
					}
				} else {
					$out .= $nx;
				}
				continue;
			}
			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				if ( 0 === $depth ) {
					++$p;
					return $out;
				}
				--$depth;
			}
			$out .= $ch;
		}
		return $out;
	}

	private static function mul( array $a, array $b ) {
		return array(
			$a[0] * $b[0] + $a[1] * $b[2],
			$a[0] * $b[1] + $a[1] * $b[3],
			$a[2] * $b[0] + $a[3] * $b[2],
			$a[2] * $b[1] + $a[3] * $b[3],
			$a[4] * $b[0] + $a[5] * $b[2] + $b[4],
			$a[4] * $b[1] + $a[5] * $b[3] + $b[5],
		);
	}

	private static function utf8( $s ) {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' );
		}
		return utf8_encode( $s ); // phpcs:ignore
	}

	/** Interprète un flux de contenu : positions des textes affichés. */
	private static function interpret( $c, $page_h ) {
		$items = array();
		$ctm   = array( 1, 0, 0, 1, 0, 0 );
		$stack = array();
		$tm    = array( 1, 0, 0, 1, 0, 0 );
		$tlm   = $tm;
		$lead  = 0.0;
		$ops   = array();
		$n     = strlen( $c );
		$p     = 0;
		$show  = function ( $txt ) use ( &$items, &$tm, &$ctm, $page_h ) {
			$m = self::mul( $tm, $ctm );
			$t = trim( self::utf8( $txt ) );
			if ( '' !== $t ) {
				$items[] = array(
					'x' => round( $m[4], 1 ),
					'y' => round( $page_h - $m[5], 1 ),
					't' => $t,
				);
			}
		};
		while ( $p < $n ) {
			$ch = $c[ $p ];
			if ( ctype_space( $ch ) ) {
				++$p;
				continue;
			}
			if ( '%' === $ch ) {
				$e = strpos( $c, "\n", $p );
				$p = false === $e ? $n : $e + 1;
				continue;
			}
			if ( '(' === $ch ) {
				$ops[] = array( 's', self::literal( $c, $p ) );
				continue;
			}
			if ( '<' === $ch ) {
				if ( '<' === ( $c[ $p + 1 ] ?? '' ) ) {
					$e   = strpos( $c, '>>', $p );
					$p   = false === $e ? $n : $e + 2;
					$ops = array();
					continue;
				}
				$e     = strpos( $c, '>', $p );
				$hex   = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $c, $p + 1, ( false === $e ? $n : $e ) - $p - 1 ) );
				$hex  .= ( strlen( $hex ) % 2 ) ? '0' : '';
				$ops[] = array( 's', (string) hex2bin( $hex ) );
				$p     = false === $e ? $n : $e + 1;
				continue;
			}
			if ( '[' === $ch ) {
				$ops[] = array( '[', null );
				++$p;
				continue;
			}
			if ( ']' === $ch ) {
				$arr = array();
				while ( $ops ) {
					$o = array_pop( $ops );
					if ( '[' === $o[0] ) {
						break;
					}
					array_unshift( $arr, $o );
				}
				$ops[] = array( 'a', $arr );
				++$p;
				continue;
			}
			if ( '/' === $ch ) {
				preg_match( '#/[^\s/\[\]()<>{}%]*#A', $c, $mm, 0, $p );
				$ops[] = array( 'n', $mm[0] );
				$p    += strlen( $mm[0] );
				continue;
			}
			if ( preg_match( '#[+-]?(?:\d+\.?\d*|\.\d+)#A', $c, $mm, 0, $p ) ) {
				$ops[] = array( 'f', (float) $mm[0] );
				$p    += strlen( $mm[0] );
				continue;
			}
			if ( ! preg_match( '#[A-Za-z\'"*]+#A', $c, $mm, 0, $p ) ) {
				++$p;
				continue;
			}
			$op = $mm[0];
			$p += strlen( $op );
			$f  = array();
			foreach ( $ops as $o ) {
				if ( 'f' === $o[0] ) {
					$f[] = $o[1];
				}
			}
			switch ( $op ) {
				case 'q':
					$stack[] = $ctm;
					break;
				case 'Q':
					if ( $stack ) {
						$ctm = array_pop( $stack );
					}
					break;
				case 'cm':
					if ( count( $f ) >= 6 ) {
						$ctm = self::mul( array_slice( $f, -6 ), $ctm );
					}
					break;
				case 'BT':
					$tm  = array( 1, 0, 0, 1, 0, 0 );
					$tlm = $tm;
					break;
				case 'Tm':
					if ( count( $f ) >= 6 ) {
						$tm  = array_slice( $f, -6 );
						$tlm = $tm;
					}
					break;
				case 'TD':
					if ( count( $f ) >= 2 ) {
						$lead = -end( $f );
					}
					// même déplacement que Td
				case 'Td':
					if ( count( $f ) >= 2 ) {
						$tx  = $f[ count( $f ) - 2 ];
						$ty  = $f[ count( $f ) - 1 ];
						$tlm = self::mul( array( 1, 0, 0, 1, $tx, $ty ), $tlm );
						$tm  = $tlm;
					}
					break;
				case 'TL':
					if ( $f ) {
						$lead = end( $f );
					}
					break;
				case 'T*':
					$tlm = self::mul( array( 1, 0, 0, 1, 0, -$lead ), $tlm );
					$tm  = $tlm;
					break;
				case 'Tj':
				case "'":
				case '"':
					if ( "'" === $op || '"' === $op ) {
						$tlm = self::mul( array( 1, 0, 0, 1, 0, -$lead ), $tlm );
						$tm  = $tlm;
					}
					foreach ( array_reverse( $ops ) as $o ) {
						if ( 's' === $o[0] ) {
							$show( $o[1] );
							break;
						}
					}
					break;
				case 'TJ':
					foreach ( array_reverse( $ops ) as $o ) {
						if ( 'a' === $o[0] ) {
							$txt = '';
							foreach ( $o[1] as $el ) {
								if ( 's' === $el[0] ) {
									$txt .= $el[1];
								} elseif ( 'f' === $el[0] && $el[1] < -200 ) {
									$txt .= ' ';
								}
							}
							$show( $txt );
							break;
						}
					}
					break;
			}
			$ops = array();
		}
		return $items;
	}
}
