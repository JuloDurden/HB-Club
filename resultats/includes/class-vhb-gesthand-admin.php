<?php
/**
 * Page « VHB > Rencontres loisirs » : calendrier Gest'Hand, feuilles de match,
 * statistiques des joueurs du club (visibles ici seulement).
 */

defined( 'ABSPATH' ) || exit;

class VHB_Gesthand_Admin {

	const SLUG = 'vhb-loisirs';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'reglages', 'import', 'fdm', 'relire', 'provisoires', 'blasons' ) as $a ) {
			add_action( 'admin_post_vhb_gh_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
	}

	public static function menu() {
		$parent = VHB_Admin::menu_commun();
		add_submenu_page( $parent ? $parent : VHB_Admin::SLUG, 'Rencontres loisirs', 'Rencontres loisirs', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::SLUG ) ) {
			wp_enqueue_media();
		}
	}

	public static function post_blasons() {
		self::guard( 'blasons' );
		$d     = VHB_Gesthand::get();
		$choix = array();
		foreach ( (array) ( $_POST['blason'] ?? array() ) as $nom => $v ) { // phpcs:ignore
			$nom = VHB_Settings::upper( sanitize_text_field( wp_unslash( $nom ) ) );
			$v   = sanitize_text_field( wp_unslash( $v ) );
			if ( 'media' === $v ) {
				$id = absint( $_POST['media'][ $nom ] ?? 0 ); // phpcs:ignore
				if ( $id && wp_attachment_is_image( $id ) ) {
					$choix[ $nom ] = 'media:' . $id;
				}
			} elseif ( 'aucun' === $v || 0 === strpos( $v, 'club:' ) ) {
				$choix[ $nom ] = $v;
			}
		}
		$d['blasons'] = $choix;
		update_option( VHB_Gesthand::OPTION, $d, false );
		VHB_Gesthand::publier();
		self::back( 'Blasons enregistrés.' );
	}

	private static function back( $msg, $type = 'ok' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::SLUG,
					'vhb_gh'   => rawurlencode( $msg ),
					'vhb_type' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhb_gh_' . $action );
	}

	/** Fichier envoyé : contenu, ou exception lisible. */
	private static function upload( $champ, $ext ) {
		if ( empty( $_FILES[ $champ ]['tmp_name'] ) || ! empty( $_FILES[ $champ ]['error'] ) ) { // phpcs:ignore
			throw new RuntimeException( 'Aucun fichier reçu.' );
		}
		$nom = sanitize_file_name( wp_unslash( $_FILES[ $champ ]['name'] ?? '' ) ); // phpcs:ignore
		if ( strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) ) !== $ext ) {
			throw new RuntimeException( 'Le fichier doit être un .' . $ext . '.' );
		}
		if ( (int) $_FILES[ $champ ]['size'] > 5242880 ) { // phpcs:ignore
			throw new RuntimeException( 'Fichier trop lourd (5 Mo au plus).' );
		}
		$raw = file_get_contents( $_FILES[ $champ ]['tmp_name'] ); // phpcs:ignore
		if ( false === $raw || '' === $raw ) {
			throw new RuntimeException( 'Fichier vide.' );
		}
		return array( $raw, $nom );
	}

	public static function post_reglages() {
		self::guard( 'reglages' );
		$d      = VHB_Gesthand::get();
		$equipe = sanitize_title( wp_unslash( $_POST['equipe'] ?? '' ) ); // phpcs:ignore
		$d['equipe']  = ( '' === $equipe || VHB_Settings::equipe( $equipe ) ) ? $equipe : $d['equipe'];
		$d['nom']     = sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ); // phpcs:ignore
		$d['libelle'] = sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) ); // phpcs:ignore
		$d['filtre']  = sanitize_text_field( wp_unslash( $_POST['filtre'] ?? '' ) ); // phpcs:ignore
		$d['filles']  = ! empty( $_POST['filles'] ); // phpcs:ignore
		if ( '' === $d['nom'] ) {
			$d['nom'] = 'Rencontres loisirs';
		}
		update_option( VHB_Gesthand::OPTION, $d, false );
		VHB_Gesthand::publier();
		VHB_Store::prune();
		self::back( 'Réglages enregistrés.' );
	}

	public static function post_import() {
		self::guard( 'import' );
		try {
			list( $raw, $nom ) = self::upload( 'fichier', 'csv' );
			$b = VHB_Gesthand::importer( $raw, $nom );
		} catch ( Throwable $e ) {
			self::back( $e->getMessage(), 'erreur' );
		}
		$msg = sprintf( 'Calendrier importé : %d rencontre(s) ajoutée(s), %d mise(s) à jour', $b['ajoutes'], $b['maj'] );
		if ( $b['provisoires_retires'] ) {
			$msg .= sprintf( ', %d rencontre(s) provisoire(s) remplacée(s) par le calendrier officiel', $b['provisoires_retires'] );
		}
		if ( $b['retires'] ) {
			$msg .= sprintf( ', %d rencontre(s) retirée(s) (absentes de Gest\'Hand)', $b['retires'] );
		}
		self::back( $msg . '.' );
	}

	public static function post_fdm() {
		self::guard( 'fdm' );
		try {
			list( $raw ) = self::upload( 'fichier', 'pdf' );
			$code        = VHB_Gesthand::deposer( $raw );
		} catch ( Throwable $e ) {
			self::back( 'Feuille non prise en compte : ' . $e->getMessage(), 'erreur' );
		}
		self::back( 'Feuille de match ' . $code . ' lue : score et statistiques enregistrés.' );
	}

	public static function post_relire() {
		self::guard( 'relire' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore
		}
		$b = VHB_Gesthand::recuperer( new VHB_Fetcher( VHB_Settings::get()['club_id'] ), 10 );
		VHB_Gesthand::publier();
		$msg = sprintf( '%d feuille(s) lue(s)', $b['lues'] );
		if ( $b['absentes'] ) {
			$msg .= sprintf( ', %d pas encore publiée(s) par la FFHB', $b['absentes'] );
		}
		foreach ( $b['erreurs'] as $code => $e ) {
			$msg .= ', ' . $code . ' : ' . $e;
		}
		self::back( $msg . '.', $b['erreurs'] ? 'erreur' : 'ok' );
	}

	public static function post_provisoires() {
		self::guard( 'provisoires' );
		$d = VHB_Gesthand::get();
		$n = 0;
		foreach ( $d['rencontres'] as $code => $r ) {
			if ( ! empty( $r['provisoire'] ) ) {
				unset( $d['rencontres'][ $code ] );
				++$n;
			}
		}
		update_option( VHB_Gesthand::OPTION, $d, false );
		VHB_Gesthand::publier();
		VHB_Store::prune();
		self::back( $n . ' rencontre(s) provisoire(s) retirée(s).' );
	}

	private static function quand( $iso ) {
		$ts = VHB_Render::ts( $iso );
		if ( ! $ts ) {
			return '<em>date à venir</em>';
		}
		$h = '0000' === wp_date( 'Hi', $ts ) ? '' : ' ' . wp_date( 'G\hi', $ts );
		return esc_html( VHB_Render::JOURS_ABR[ (int) wp_date( 'N', $ts ) ] . ' ' . wp_date( 'd/m/Y', $ts ) . $h );
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$d      = VHB_Gesthand::get();
		$club   = VHB_Settings::get()['club_id'];
		$cfg    = '' !== $d['equipe'] ? VHB_Settings::equipe( $d['equipe'] ) : null;
		$renc   = array();
		foreach ( $cfg ? VHB_Gesthand::comp_data( $cfg, true )['rencontres'] : array() as $r ) {
			$r['tour'] = 'J' . (int) $r['journee'];
			$renc[]    = $r;
		}
		$msg  = isset( $_GET['vhb_gh'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['vhb_gh'] ) ) ) : ''; // phpcs:ignore
		$type = isset( $_GET['vhb_type'] ) && 'erreur' === $_GET['vhb_type'] ? 'error' : 'success'; // phpcs:ignore
		$post = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap vhb-gh">
			<h1>Rencontres loisirs <span class="vhb-gh-sub">Gest'Hand</span></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:820px">Pour les rencontres saisies dans Gest'Hand mais absentes de ffhandball.fr. Le <strong>calendrier</strong> vient d'un export GH Extraction (ou d'un fichier provisoire au même format). Les <strong>résultats</strong> viennent de la feuille de match : le site la télécharge seul sur le serveur de la FFHB après chaque match (à la mise à jour suivante des résultats), ou vous la déposez ici.</p>
			<?php if ( '' === (string) $club ) : ?>
				<div class="notice notice-warning inline"><p>Renseignez d'abord l'identifiant FFHB du club dans <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . VHB_Admin::SLUG ) ); ?>">Résultats FFHB</a> : il sert à reconnaître nos rencontres et nos joueurs.</p></div>
			<?php endif; ?>

			<h2>Réglages</h2>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
				<input type="hidden" name="action" value="vhb_gh_reglages">
				<?php wp_nonce_field( 'vhb_gh_reglages' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="vhb-gh-equipe">Équipe</label></th><td>
						<select name="equipe" id="vhb-gh-equipe">
							<option value="">Choisir…</option>
							<?php foreach ( VHB_Settings::equipes() as $e ) : ?>
								<option value="<?php echo esc_attr( $e['slug'] ); ?>" <?php selected( $d['equipe'], $e['slug'] ); ?>><?php echo esc_html( $e['nom'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Les rencontres s'ajoutent à cette équipe, comme une compétition de plus. Une équipe se crée dans <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . VHB_Admin::SLUG ) ); ?>">Résultats FFHB</a>, même sans page FFHB.</p>
					</td></tr>
					<tr><th scope="row"><label for="vhb-gh-nom">Nom de la compétition</label></th><td><input type="text" class="regular-text" name="nom" id="vhb-gh-nom" value="<?php echo esc_attr( $d['nom'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="vhb-gh-lib">Notre nom affiché</label></th><td><input type="text" class="regular-text" name="libelle" id="vhb-gh-lib" value="<?php echo esc_attr( $d['libelle'] ); ?>" placeholder="<?php echo esc_attr( $cfg ? VHB_Gesthand::comp_data( $cfg, true )['libelle'] : '' ); ?>"><p class="description">Laisser vide : le nom de Gest'Hand sans le numéro d'équipe (« … LOISIRS 0 » devient « … LOISIRS »).</p></td></tr>
					<tr><th scope="row"><label for="vhb-gh-filtre">Compétition à importer</label></th><td><input type="text" class="regular-text" name="filtre" id="vhb-gh-filtre" value="<?php echo esc_attr( $d['filtre'] ); ?>"><p class="description">Mot contenu dans la colonne « competition » de l'export. Les autres compétitions de l'export sont ignorées : elles viennent déjà de ffhandball.fr.</p></td></tr>
					<tr><th scope="row">Buts féminins</th><td><label><input type="checkbox" name="filles" value="1" <?php checked( ! empty( $d['filles'] ) ); ?>> Un but féminin compte double sur la feuille : les statistiques des joueuses sont divisées par 2</label><p class="description">Le score officiel, lui, n'est jamais modifié.</p></td></tr>
				</table>
				<?php submit_button( 'Enregistrer les réglages', 'secondary' ); ?>
			</form>

			<h2>Calendrier</h2>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" enctype="multipart/form-data" class="vhb-gh-inline">
				<input type="hidden" name="action" value="vhb_gh_import">
				<?php wp_nonce_field( 'vhb_gh_import' ); ?>
				<input type="file" name="fichier" accept=".csv,text/csv" required>
				<?php submit_button( 'Importer l\'export GH Extraction', 'primary', 'submit', false ); ?>
			</form>
			<p class="description" style="max-width:820px">Réimportez le fichier à chaque mise à jour du calendrier dans Gest'Hand. Une rencontre officielle remplace la rencontre <strong>provisoire</strong> (code commençant par PROV) du même jour ; les provisoires à venir sur la période du fichier officiel sont retirées, celles d'après restent. Les matchs joués disparaissent de l'export GH Extraction : ils restent sur le site. Un match passé dont la feuille n'est pas lue n'est pas affiché sur le site.</p>
			<?php if ( $d['imports'] ) : ?>
				<p class="description">Dernier import : <?php echo esc_html( wp_date( 'd/m/Y H:i', VHB_Render::ts( $d['imports'][0]['date'] ) ) . ( $d['imports'][0]['fichier'] ? ' (' . $d['imports'][0]['fichier'] . ')' : '' ) ); ?>.</p>
			<?php endif; ?>

			<?php if ( ! $cfg ) : ?>
				<p><em>Choisissez l'équipe dans les réglages pour afficher les rencontres sur le site.</em></p>
			<?php endif; ?>

			<h2>Rencontres <span class="count">(<?php echo (int) count( $d['rencontres'] ); ?>)</span></h2>
			<table class="widefat striped vhb-gh-table">
				<thead><tr><th>J</th><th>Date</th><th>Rencontre</th><th>Lieu</th><th>Calendrier</th><th>Score</th><th>Tiers-temps</th><th>Feuille</th></tr></thead>
				<tbody>
				<?php
				if ( ! $renc ) {
					echo '<tr><td colspan="8"><em>Aucune rencontre. Importez un export GH Extraction.</em></td></tr>';
				}
				foreach ( $renc as $r ) {
					$res  = $d['resultats'][ $r['id'] ] ?? array();
					$tt   = array_map( static function ( $t ) { return (int) $t[0] . '-' . (int) $t[1]; }, (array) ( $r['tt'] ?? array() ) );
					$ts   = VHB_Render::ts( $r['date'] );
					if ( ! empty( $r['provisoire'] ) ) {
						$f = '<span class="vhb-pill vhb-pill--off">calendrier provisoire</span>';
					} elseif ( ! empty( $res['manuel'] ) ) {
						$f = 'déposée le ' . esc_html( wp_date( 'd/m H:i', (int) $res['lu'] ) );
					} elseif ( ! empty( $res['lu'] ) ) {
						$f = 'lue le ' . esc_html( wp_date( 'd/m H:i', (int) $res['lu'] ) ) . ( empty( $res['final'] ) ? ' <span class="description">(relue après 48 h)</span>' : '' );
					} elseif ( ! empty( $res['absent'] ) ) {
						$f = '<span class="vhb-err">pas encore publiée</span> <span class="description">(essai le ' . esc_html( wp_date( 'd/m H:i', (int) $res['absent'] ) ) . ')</span>';
					} elseif ( $ts && $ts > time() ) {
						$f = '<span class="description">après le match</span>';
					} else {
						$f = '<span class="description">à la prochaine mise à jour</span>';
					}
					$score = $r['joue'] ? '<strong>' . esc_html( $r['score1'] . ' - ' . $r['score2'] ) . '</strong>' . ( $r['forfait'] ? ' <span class="vhb-pill">forfait</span>' : '' ) : '';
					echo '<tr>'
						. '<td>' . esc_html( $r['tour'] ) . '</td>'
						. '<td>' . self::quand( $r['date'] ) . '</td>' // phpcs:ignore
						. '<td>' . esc_html( $r['domicile'] ) . ' <span class="description">contre</span> ' . esc_html( $r['exterieur'] ) . ' <span class="vhb-pill' . ( $r['a_domicile'] ? '' : ' vhb-pill--off' ) . '">' . ( $r['a_domicile'] ? 'dom.' : 'ext.' ) . '</span></td>'
						. '<td>' . esc_html( $r['lieu'] ) . ( $r['adresse'] ? '<br><span class="description">' . esc_html( $r['adresse'] ) . '</span>' : '' ) . '</td>'
						. '<td><code>' . esc_html( $r['id'] ) . '</code></td>'
						. '<td>' . $score . '</td>' // phpcs:ignore
						. '<td>' . esc_html( implode( ' · ', $tt ) ) . '</td>'
						. '<td>' . $f . '</td>' // phpcs:ignore
						. '</tr>';
				}
				?>
				</tbody>
			</table>
			<div class="vhb-gh-actions">
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="vhb-gh-inline">
					<input type="hidden" name="action" value="vhb_gh_relire">
					<?php wp_nonce_field( 'vhb_gh_relire' ); ?>
					<?php submit_button( 'Chercher les feuilles maintenant', 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( array_filter( wp_list_pluck( $d['rencontres'], 'provisoire' ) ) ) : ?>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="vhb-gh-inline" onsubmit="return window.confirm('Retirer toutes les rencontres provisoires ?');">
					<input type="hidden" name="action" value="vhb_gh_provisoires">
					<?php wp_nonce_field( 'vhb_gh_provisoires' ); ?>
					<?php submit_button( 'Retirer les rencontres provisoires', 'delete', 'submit', false ); ?>
				</form>
				<?php endif; ?>
			</div>

			<h2>Blasons des adversaires</h2>
			<?php
			$connus = VHB_Gesthand::clubs_connus();
			$advs   = VHB_Gesthand::adversaires();
			?>
			<p class="description" style="max-width:820px">Les blasons viennent des clubs déjà rencontrés par nos équipes FFHB. Le site devine le club à partir du nom ; corrigez ici s'il se trompe (« POUSSAN » : La Vène HB). Pour un club jamais rencontré en FFHB, choisissez une image de la médiathèque. Sans blason : les initiales du club.</p>
			<?php if ( $advs ) : ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
				<input type="hidden" name="action" value="vhb_gh_blasons">
				<?php wp_nonce_field( 'vhb_gh_blasons' ); ?>
				<table class="widefat striped vhb-gh-table vhb-gh-blasons"><thead><tr><th>Adversaire</th><th>Blason</th><th>Club</th></tr></thead><tbody>
				<?php
				foreach ( $advs as $cle => $nom ) {
					$c     = (string) ( $d['blasons'][ $cle ] ?? '' );
					$stem  = VHB_Gesthand::blason_de( $nom, $connus, $d['blasons'] );
					$url   = VHB_Logos::url( $stem );
					$guess = VHB_Gesthand::devine( $nom, $connus );
					$mid   = preg_match( '/^media:(\d+)$/', $c, $mm ) ? (int) $mm[1] : 0;
					$f     = esc_attr( $cle );
					echo '<tr><td><strong>' . esc_html( $nom ) . '</strong></td>'
						. '<td>' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="" width="40" height="40" style="object-fit:contain" class="vhb-gh-img">' : '<span class="vhb-gh-ini">' . esc_html( VHB_Logos::initials( $nom ) ) . '</span>' ) . '</td><td>'
						. '<select name="blason[' . $f . ']" class="vhb-gh-sel">'
						. '<option value="">Automatique' . ( '' !== $guess ? ' : ' . esc_html( $guess ) : ' (aucun club trouvé)' ) . '</option>';
					foreach ( array_keys( $connus ) as $club ) {
						echo '<option value="' . esc_attr( 'club:' . $club ) . '" ' . selected( $c, 'club:' . $club, false ) . '>' . esc_html( $club ) . '</option>';
					}
					echo '<option value="media" ' . selected( (bool) $mid, true, false ) . '>Image de la médiathèque…</option>'
						. '<option value="aucun" ' . selected( $c, 'aucun', false ) . '>Aucun (initiales)</option></select> '
						. '<input type="hidden" name="media[' . $f . ']" value="' . (int) $mid . '" class="vhb-gh-mid">'
						. '<button type="button" class="button vhb-gh-pick"' . ( $mid ? '' : ' style="display:none"' ) . '>' . ( $mid ? 'Changer l\'image' : 'Choisir l\'image' ) . '</button>'
						. '</td></tr>';
				}
				?>
				</tbody></table>
				<?php submit_button( 'Enregistrer les blasons', 'secondary' ); ?>
			</form>
			<script>
			( function () {
				document.querySelectorAll( '.vhb-gh-blasons tr' ).forEach( function ( tr ) {
					var sel = tr.querySelector( '.vhb-gh-sel' ), btn = tr.querySelector( '.vhb-gh-pick' ), mid = tr.querySelector( '.vhb-gh-mid' );
					if ( ! sel ) { return; }
					var choisir = function () {
						if ( ! window.wp || ! wp.media ) { return; }
						var f = wp.media( { title: 'Blason de l\'adversaire', library: { type: 'image' }, multiple: false } );
						f.on( 'select', function () {
							var a = f.state().get( 'selection' ).first().toJSON();
							mid.value = a.id;
							var img = tr.querySelector( 'td:nth-child(2)' );
							var u = ( a.sizes ? ( a.sizes.thumbnail || a.sizes.full ) : null );
							img.innerHTML = '<img src="' + ( u ? u.url : a.url ) + '" alt="" width="40" height="40" style="object-fit:contain">';
							btn.textContent = 'Changer l\'image';
						} );
						f.open();
					};
					sel.addEventListener( 'change', function () {
						btn.style.display = sel.value === 'media' ? '' : 'none';
						if ( sel.value === 'media' ) {
							if ( mid.value === '0' ) { choisir(); }
						}
					} );
					btn.addEventListener( 'click', choisir );
				} );
			} )();
			</script>
			<?php else : ?>
				<p><em>Aucun adversaire pour l'instant.</em></p>
			<?php endif; ?>

			<h2>Déposer une feuille de match</h2>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" enctype="multipart/form-data" class="vhb-gh-inline">
				<input type="hidden" name="action" value="vhb_gh_fdm">
				<?php wp_nonce_field( 'vhb_gh_fdm' ); ?>
				<input type="file" name="fichier" accept=".pdf,application/pdf" required>
				<?php submit_button( 'Lire la feuille', 'secondary', 'submit', false ); ?>
			</form>
			<p class="description" style="max-width:820px">En secours, si la feuille n'est pas publiée par la FFHB. Le PDF n'est pas conservé : le site en garde le score, les tiers-temps et les statistiques de nos joueurs. Une feuille déposée n'est plus remplacée par celle de la FFHB.</p>

			<h2>Statistiques de la saison</h2>
			<p class="description" style="max-width:820px">Visibles uniquement ici, jamais sur le site. Prénom et initiale du nom ; ni licence, ni joueurs adverses.<?php echo ! empty( $d['filles'] ) ? ' Buts des joueuses divisés par 2 (un but féminin compte double sur la feuille).' : ''; ?></p>
			<?php $tot = VHB_Gesthand::totaux(); ?>
			<table class="widefat striped vhb-gh-table vhb-gh-stats">
				<thead><tr><th>Joueur</th><th>Matchs</th><th>Buts</th><th>Tirs</th><th>Réussite</th><th>7 m</th><th>Arrêts</th><th>2 min</th><th>Av. / Dis.</th></tr></thead>
				<tbody>
				<?php
				if ( ! $tot ) {
					echo '<tr><td colspan="9"><em>Aucune feuille lue pour l\'instant.</em></td></tr>';
				}
				foreach ( $tot as $t ) {
					echo '<tr><td><strong>' . esc_html( $t['nom'] ) . '</strong>' . ( $t['numeros'] ? ' <span class="description">n° ' . esc_html( implode( ', ', array_keys( $t['numeros'] ) ) ) . '</span>' : '' ) . '</td>'
						. '<td>' . (int) $t['matchs'] . '</td><td><strong>' . (int) $t['buts'] . '</strong></td><td>' . (int) $t['tirs'] . '</td>'
						. '<td>' . ( $t['tirs'] ? esc_html( round( 100 * $t['buts'] / $t['tirs'] ) . ' %' ) : '' ) . '</td>'
						. '<td>' . (int) $t['7m'] . '</td><td>' . (int) $t['arrets'] . '</td><td>' . (int) $t['2min'] . '</td><td>' . (int) $t['av'] . ' / ' . (int) $t['dis'] . '</td></tr>';
				}
				?>
				</tbody>
			</table>
			<?php
			foreach ( array_reverse( $renc ) as $r ) {
				$js = $d['stats'][ $r['id'] ] ?? array();
				if ( ! $js ) {
					continue;
				}
				echo '<details class="vhb-gh-match"><summary>' . esc_html( $r['tour'] ) . ' · ' . self::quand( $r['date'] ) . ' · ' . esc_html( $r['domicile'] . ' ' . $r['score1'] . ' - ' . $r['score2'] . ' ' . $r['exterieur'] ) . '</summary>'; // phpcs:ignore
				echo '<table class="widefat striped vhb-gh-table"><thead><tr><th>N°</th><th>Joueur</th><th>Buts</th><th>Tirs</th><th>7 m</th><th>Arrêts</th><th>2 min</th></tr></thead><tbody>';
				foreach ( $js as $j ) {
					echo '<tr><td>' . esc_html( $j['numero'] ) . '</td><td>' . esc_html( $j['nom'] ) . ( ! empty( $j['capitaine'] ) ? ' <span class="vhb-pill vhb-pill--off">capitaine</span>' : '' ) . '</td><td>' . (int) $j['buts'] . ( (int) $j['buts'] !== (int) $j['buts_fdm'] ? ' <span class="description">(' . (int) $j['buts_fdm'] . ' sur la feuille)</span>' : '' ) . '</td><td>' . (int) $j['tirs'] . '</td><td>' . (int) $j['7m'] . '</td><td>' . (int) $j['arrets'] . '</td><td>' . (int) $j['2min'] . '</td></tr>';
				}
				echo '</tbody></table></details>';
			}
			?>
		</div>
		<style>
			.vhb-gh .vhb-gh-sub { font-size: 13px; font-weight: 600; color: #646970; margin-left: 6px; }
			.vhb-gh .vhb-gh-inline { display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 6px 12px 6px 0; }
			.vhb-gh .vhb-gh-actions { margin: 10px 0 0; }
			.vhb-gh .vhb-gh-table { max-width: 1200px; }
			.vhb-gh .vhb-gh-table td { vertical-align: top; }
			.vhb-gh .vhb-gh-stats { max-width: 900px; }
			.vhb-gh .vhb-gh-match { max-width: 900px; margin: 10px 0; }
			.vhb-gh .vhb-gh-match summary { cursor: pointer; font-weight: 600; padding: 6px 0; }
			.vhb-gh .vhb-pill { display: inline-block; font-size: 11px; line-height: 1.6; padding: 0 8px; border-radius: 999px; background: #dff0d8; color: #1e4620; }
			.vhb-gh .vhb-pill--off { background: #f0f0f1; color: #50575e; }
			.vhb-gh .vhb-err { color: #b32d2e; }
			.vhb-gh .vhb-gh-blasons { max-width: 900px; }
			.vhb-gh .vhb-gh-blasons td { vertical-align: middle; }
			.vhb-gh .vhb-gh-ini { display: inline-flex; width: 40px; height: 40px; border-radius: 50%; background: #dcdcde; color: #50575e; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
			.vhb-gh .vhb-gh-sel { max-width: 360px; }
		</style>
		<?php
	}
}
