<?php
/**
 * Gemensam meny "Cykla&Vandra" för Cykla & Vandras egna tillägg.
 *
 * Samma fil kopieras oförändrad till varje tillägg (t.ex. Bokningsformulär och
 * AI-sök). Klassen definieras av den kopia som laddas först, och menyn skapas
 * bara en gång. Ändras filen ska den nya kopian läggas in i alla tillägg, och
 * VERSION höjas så att det syns vilken kopia som är vilken.
 *
 * Så används den i ett tillägg:
 *
 *     require_once __DIR__ . '/cyklavandra-meny.php';
 *
 *     add_action( 'admin_menu', function () {
 *         add_submenu_page( Cyklavandra_Meny::SLUG, 'Sidans titel', 'Menytext', 'manage_options', 'min-slug', 'min_callback' );
 *     } );
 *
 *     // Valfritt: visas på översiktssidan.
 *     add_filter( 'cyklavandra_verktyg', function ( $verktyg ) {
 *         $verktyg[] = array(
 *             'titel'       => 'Mitt verktyg',
 *             'beskrivning' => 'Vad det gör.',
 *             'url'         => admin_url( 'admin.php?page=min-slug' ),
 *             'version'     => '1.0.0',
 *             'ikon'        => plugins_url( 'assets/icon.svg', __FILE__ ), // valfri
 *         );
 *         return $verktyg;
 *     } );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Cyklavandra_Meny' ) ) {

	final class Cyklavandra_Meny {

		const VERSION = 1;
		const SLUG    = 'cyklavandra';

		/** Lägsta behörighet för att se menyn. Undersidorna har egna krav. */
		const CAP = 'edit_pages';

		/** Placering i vänstermenyn: strax under Kontrollpanelen. */
		const POSITION = 3;

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register' ), 5 );
		}

		public static function register() {
			global $admin_page_hooks;
			if ( ! empty( $admin_page_hooks[ self::SLUG ] ) ) {
				return; // Ett annat tillägg har redan skapat menyn.
			}
			add_menu_page(
				'Cykla & Vandra',
				'Cykla&amp;Vandra',
				self::CAP,
				self::SLUG,
				array( __CLASS__, 'render' ),
				self::icon(),
				self::POSITION
			);
			// Första undermenyn får annars samma namn som rubriken.
			add_submenu_page( self::SLUG, 'Cykla & Vandra', 'Översikt', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		}

		/** Fjäll och sol, enfärgad så att WordPress kan färga den efter adminfärgschemat. */
		public static function icon() {
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M15 1.6a2.4 2.4 0 1 1 0 4.8a2.4 2.4 0 0 1 0-4.8zM7.5 4.5l3.6 5.6l1.6-2.2l6.3 9.1H1zM7.5 7.3l-1.6 2.5l1.1-.6l.9.9l1-.9l.6.4z"/></svg>';
			return 'data:image/svg+xml;base64,' . base64_encode( $svg );
		}

		public static function render() {
			$verktyg = apply_filters( 'cyklavandra_verktyg', array() );
			?>
			<div class="wrap">
				<h1>Cykla &amp; Vandra</h1>
				<p>Cykla &amp; Vandras egna tillägg.</p>
				<?php if ( ! $verktyg ) : ?>
					<p>Inga verktyg är installerade.</p>
				<?php endif; ?>
				<div class="cyklavandra-verktyg">
					<?php foreach ( (array) $verktyg as $v ) :
						$v = wp_parse_args( (array) $v, array( 'titel' => '', 'beskrivning' => '', 'url' => '', 'version' => '', 'ikon' => '' ) );
						?>
						<a class="cyklavandra-kort" href="<?php echo esc_url( $v['url'] ); ?>">
							<?php if ( $v['ikon'] ) : ?>
								<img src="<?php echo esc_url( $v['ikon'] ); ?>" alt="" width="56" height="56">
							<?php endif; ?>
							<span>
								<strong><?php echo esc_html( $v['titel'] ); ?></strong>
								<?php if ( $v['version'] ) : ?>
									<small>Version <?php echo esc_html( $v['version'] ); ?></small>
								<?php endif; ?>
								<em><?php echo esc_html( $v['beskrivning'] ); ?></em>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<style>
				.cyklavandra-verktyg { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:16px; margin-top:16px; }
				.cyklavandra-kort { display:flex; gap:14px; align-items:flex-start; padding:16px; background:#fff; border:1px solid #c3c4c7; border-radius:8px; text-decoration:none; color:#1d2327; }
				.cyklavandra-kort:hover, .cyklavandra-kort:focus { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
				.cyklavandra-kort img { flex:none; border-radius:12px; }
				.cyklavandra-kort strong { display:block; font-size:15px; color:#2271b1; }
				.cyklavandra-kort small { display:block; color:#646970; margin:2px 0 6px; }
				.cyklavandra-kort em { display:block; font-style:normal; color:#50575e; }
			</style>
			<?php
		}
	}
}

// Flera kopior anropar init(), men WordPress lägger bara till samma callback en gång.
Cyklavandra_Meny::init();
