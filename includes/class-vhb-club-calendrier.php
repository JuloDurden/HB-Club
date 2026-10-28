<?php
/**
 * Calendrier des événements (3.1.0) : vue d'ensemble dans l'administration.
 * Grille du mois (navigation mois par mois, création d'un événement en cliquant
 * sur un jour) et liste de la saison, mois par mois. Lecture seule : la saisie
 * reste dans la fiche de l'événement.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Calendrier {

	const PAGE = 'vhb-club-calendrier';

	const JOURS = array( 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
	}

	public static function menu() {
		// Gestionnaire : sous le menu de l'extension. Éditeur de stages : sous son menu « Événements ».
		$parent = current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ? VHB_Club_Admin_Slugs::MENU : 'edit.php?post_type=' . VHB_Club_Types::STAGE;
		add_submenu_page( $parent, 'Calendrier des événements', 'Calendrier', VHB_Club_Reglages::CAP_STAGES, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** Événements publiés, programmés et brouillons, avec leur statut. */
	private static function evenements() {
		$ids = get_posts(
			array(
				'post_type'        => VHB_Club_Types::STAGE,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$s = VHB_Club_Stages::stage( $id );
			if ( '' === $s['debut'] ) {
				continue;
			}
			$s['statut'] = get_post_status( $id );
			$out[]       = $s;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['debut'], $b['debut'] );
			}
		);
		return $out;
	}

	private static function lien_mois( $y, $m ) {
		return admin_url( 'admin.php?page=' . self::PAGE . '&mois=' . sprintf( '%04d-%02d', $y, $m ) );
	}

	private static function pastille( array $s, $court = false ) {
		$c      = VHB_Club_Types::COULEURS_STAGE[ $s['type'] ] ?? '#6B7080';
		$classe = 'vhbc-cal-ev' . ( 'publish' === $s['statut'] ? '' : ' vhbc-cal-ev--brouillon' ) . ( $s['a_confirmer'] ? ' vhbc-cal-ev--flou' : '' );
		$titre  = $s['titre'] . ' (' . ( VHB_Club_Types::TYPES_STAGE[ $s['type'] ] ?? $s['type'] ) . ')'
			. ( $s['a_confirmer'] ? ', date à confirmer' . ( '' !== $s['date_texte'] ? ' : ' . $s['date_texte'] : '' ) : '' )
			. ( 'publish' === $s['statut'] ? '' : ', non publié' );
		return '<a class="' . esc_attr( $classe ) . '" style="--c:' . esc_attr( $c ) . '" href="' . esc_url( get_edit_post_link( $s['id'], 'raw' ) ) . '" title="' . esc_attr( $titre ) . '">'
			. ( $s['a_confirmer'] ? '<span aria-hidden="true">? </span>' : '' ) . esc_html( $s['titre'] ) . '</a>';
	}

	public static function page() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_STAGES ) ) {
			return;
		}
		$q = isset( $_GET['mois'] ) ? sanitize_text_field( wp_unslash( $_GET['mois'] ) ) : ''; // phpcs:ignore
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $q, $mm ) || (int) $mm[2] < 1 || (int) $mm[2] > 12 ) {
			$mm = array( '', wp_date( 'Y' ), wp_date( 'm' ) );
		}
		$y     = (int) $mm[1];
		$m     = (int) $mm[2];
		$auj   = VHB_Club_Donnees::today();
		$evs   = self::evenements();
		$nb    = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
		$dec   = (int) gmdate( 'N', gmmktime( 0, 0, 0, $m, 1, $y ) ) - 1; // lundi = 0
		$prec  = 1 === $m ? array( $y - 1, 12 ) : array( $y, $m - 1 );
		$suiv  = 12 === $m ? array( $y + 1, 1 ) : array( $y, $m + 1 );
		$nouv  = admin_url( 'post-new.php?post_type=' . VHB_Club_Types::STAGE );
		$mois  = ucfirst( VHB_Club_Donnees::MOIS[ $m ] ) . ' ' . $y;
		?>
		<div class="wrap vhbc-wrap vhbc-cal-wrap">
			<h1 class="wp-heading-inline">Calendrier des événements</h1>
			<a href="<?php echo esc_url( $nouv ); ?>" class="page-title-action">Ajouter un événement</a>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . VHB_Club_Types::STAGE ) ); ?>" class="page-title-action">Liste des événements</a>
			<hr class="wp-header-end">

			<div class="vhbc-cal-nav">
				<a class="button" href="<?php echo esc_url( self::lien_mois( $prec[0], $prec[1] ) ); ?>" aria-label="Mois précédent">&lsaquo;</a>
				<h2><?php echo esc_html( $mois ); ?></h2>
				<a class="button" href="<?php echo esc_url( self::lien_mois( $suiv[0], $suiv[1] ) ); ?>" aria-label="Mois suivant">&rsaquo;</a>
				<a class="button vhbc-cal-auj" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">Aujourd'hui</a>
			</div>

			<ul class="vhbc-cal-legende">
				<?php foreach ( VHB_Club_Types::TYPES_STAGE as $k => $v ) : ?>
					<li><span class="vhbc-type-pt" style="background:<?php echo esc_attr( VHB_Club_Types::COULEURS_STAGE[ $k ] ); ?>"></span><?php echo esc_html( $v ); ?></li>
				<?php endforeach; ?>
				<li><span class="vhbc-cal-ev vhbc-cal-ev--flou vhbc-cal-ev--mini" style="--c:#6B7080">?</span>date à confirmer</li>
				<li><span class="vhbc-cal-ev vhbc-cal-ev--brouillon vhbc-cal-ev--mini" style="--c:#6B7080">&nbsp;</span>non publié</li>
			</ul>

			<table class="vhbc-cal" role="grid" aria-label="<?php echo esc_attr( $mois ); ?>">
				<thead><tr>
					<?php foreach ( self::JOURS as $j ) : ?>
						<th scope="col"><?php echo esc_html( $j ); ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php
				$cases = (int) ceil( ( $dec + $nb ) / 7 ) * 7;
				for ( $i = 0; $i < $cases; $i++ ) {
					if ( 0 === $i % 7 ) {
						echo '<tr>';
					}
					$n = $i - $dec + 1;
					if ( $n < 1 || $n > $nb ) {
						echo '<td class="vhbc-cal-hors"></td>';
					} else {
						$d   = sprintf( '%04d-%02d-%02d', $y, $m, $n );
						$cls = ( $d === $auj ? ' vhbc-cal-auj' : '' ) . ( $i % 7 >= 5 ? ' vhbc-cal-we' : '' );
						echo '<td class="vhbc-cal-jour' . esc_attr( $cls ) . '"><div class="vhbc-cal-tete"><span class="vhbc-cal-n">' . (int) $n . '</span>';
						echo '<a class="vhbc-cal-plus" href="' . esc_url( add_query_arg( 'vhbc_debut', $d, $nouv ) ) . '" title="' . esc_attr( 'Ajouter un événement le ' . VHB_Club_Donnees::periode( $d, $d ) ) . '">+</a></div>';
						foreach ( $evs as $s ) {
							if ( $s['debut'] <= $d && $d <= $s['fin'] ) {
								echo self::pastille( $s ); // phpcs:ignore
							}
						}
						echo '</td>';
					}
					if ( 6 === $i % 7 ) {
						echo '</tr>';
					}
				}
				?>
				</tbody>
			</table>

			<?php
			// Saison du mois affiché : 1er septembre au 31 août.
			$sd   = $m >= 9 ? $y : $y - 1;
			$de   = $sd . '-09-01';
			$a    = ( $sd + 1 ) . '-08-31';
			$sais = array_filter(
				$evs,
				static function ( $s ) use ( $de, $a ) {
					return $s['debut'] >= $de && $s['debut'] <= $a;
				}
			);
			?>
			<h2 class="vhbc-cal-saison">Saison <?php echo (int) $sd . '-' . ( (int) $sd + 1 ); ?></h2>
			<?php if ( ! $sais ) : ?>
				<p>Aucun événement saisi pour cette saison.</p>
			<?php else : ?>
				<table class="widefat striped vhbc-cal-liste">
					<thead><tr><th scope="col">Dates</th><th scope="col">Événement</th><th scope="col">Type</th><th scope="col">Lieu</th><th scope="col">Sur le site</th></tr></thead>
					<tbody>
					<?php
					$mc = '';
					foreach ( $sais as $s ) {
						$k = substr( $s['debut'], 0, 7 );
						if ( $k !== $mc ) {
							$mc = $k;
							$p  = explode( '-', $k );
							echo '<tr class="vhbc-cal-mois"><th colspan="5"><a href="' . esc_url( self::lien_mois( (int) $p[0], (int) $p[1] ) ) . '">' . esc_html( ucfirst( VHB_Club_Donnees::MOIS[ (int) $p[1] ] ) . ' ' . $p[0] ) . '</a></th></tr>';
						}
						list( $lieu ) = VHB_Club_Stages::lieu_txt( $s );
						$dates        = $s['a_confirmer'] ? 'à confirmer' . ( '' !== $s['date_texte'] ? ' : ' . $s['date_texte'] : '' ) : VHB_Club_Donnees::periode( $s['debut'], $s['fin'], true );
						$etat         = 'publish' !== $s['statut'] ? 'non publié' : array(
							'a_venir'  => 'à venir',
							'en_cours' => 'en cours',
							'termine'  => 'terminé, masqué',
						)[ $s['etat'] ] ?? '';
						$c = VHB_Club_Types::COULEURS_STAGE[ $s['type'] ] ?? '#6B7080';
						echo '<tr><td>' . esc_html( $dates ) . '</td>';
						echo '<td><strong><a href="' . esc_url( get_edit_post_link( $s['id'], 'raw' ) ) . '">' . esc_html( $s['titre'] ) . '</a></strong></td>';
						echo '<td><span class="vhbc-type"><span class="vhbc-type-pt" style="background:' . esc_attr( $c ) . '"></span>' . esc_html( VHB_Club_Types::TYPES_STAGE[ $s['type'] ] ?? $s['type'] ) . '</span></td>';
						echo '<td>' . esc_html( $lieu ) . '</td>';
						echo '<td><span class="vhbc-pill' . ( in_array( $etat, array( 'à venir', 'en cours' ), true ) ? ' vhbc-pill--en_cours' : ' vhbc-pill--off' ) . '">' . esc_html( $etat ) . '</span></td></tr>';
					}
					?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
