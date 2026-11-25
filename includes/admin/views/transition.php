<?php
/**
 * Checklist for the switch-over from the spreadsheet and the Code Snippets snippet.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Automation;
use CV_Resekarta\Importer;
use CV_Resekarta\Languages;
use CV_Resekarta\Map_Data;
use CV_Resekarta\Shortcode;
use CV_Resekarta\Transition;
use CV_Resekarta\Trips;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cvrk_msg   = isset( $_GET['cvrk_msg'] ) ? sanitize_key( wp_unslash( $_GET['cvrk_msg'] ) ) : '';
$cvrk_error = get_transient( 'cvrk_form_error_' . get_current_user_id() );
if ( $cvrk_error ) {
	delete_transient( 'cvrk_form_error_' . get_current_user_id() );
}

$cvrk_import  = get_option( Importer::REPORT_OPTION );
$cvrk_build   = Map_Data::last_build();
$cvrk_compare = Transition::comparison();
$cvrk_pages   = Transition::pages_with_map();
$cvrk_old     = Transition::old_files();
$cvrk_other   = Shortcode::other_owner();
$cvrk_on      = Shortcode::enabled();
$cvrk_on_map  = 0;
foreach ( Trips::ids() as $cvrk_id ) {
	$cvrk_t       = Trips::get( $cvrk_id );
	$cvrk_on_map += Trips::map_status( $cvrk_t, \CV_Resekarta\Destinations::get( $cvrk_t['destination'] ) )['on'] ? 1 : 0;
}
$cvrk_compare_ok = $cvrk_compare && ! array_filter(
	$cvrk_compare,
	function ( $c ) {
		return $c['missing'] > 0;
	}
);
$cvrk_iframes = array_filter(
	$cvrk_pages,
	function ( $p ) {
		return in_array( $p['kind'], array( 'iframe', 'unknown' ), true );
	}
);
$cvrk_sheet_recent = isset( $cvrk_old['resor-sv.js'] ) && ( time() - $cvrk_old['resor-sv.js'] ) < 36 * HOUR_IN_SECONDS;

/**
 * One step: done (true/false/null = cannot tell), title and content.
 */
$cvrk_step = function ( $nr, $done, $title ) {
	$icon  = true === $done ? '✅' : ( false === $done ? '⬜' : '➖' );
	$class = true === $done ? 'cvrk-step-done' : 'cvrk-step-todo';
	printf( '<h2 class="cvrk-step %s"><span aria-hidden="true">%s</span> %d. %s</h2>', esc_attr( $class ), esc_html( $icon ), (int) $nr, esc_html( $title ) );
};
?>
<div class="wrap cvrk cvrk-transition">
	<h1>Resekarta – övergång från kalkylarket</h1>
	<?php Admin::tabs(); ?>
	<?php
	if ( 'shortcode_on' === $cvrk_msg ) {
		Admin::notice( 'Tilläggets kortkod är påslagen. Kontrollera sidorna i steg 4.' );
	} elseif ( 'shortcode_off' === $cvrk_msg ) {
		Admin::notice( 'Tilläggets kortkod är avstängd. Aktivera kodstycket i Code Snippets igen om kartan ska visas som förut.', 'warning' );
	} elseif ( 'old_deleted' === $cvrk_msg ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Admin::notice( sprintf( '%d gamla kartfiler är borttagna.', isset( $_GET['antal'] ) ? (int) $_GET['antal'] : 0 ) );
	}
	if ( $cvrk_error ) {
		Admin::notice( esc_html( $cvrk_error['message'] ), 'error' );
	}
	?>
	<p>Gör stegen i ordning. Bocken sätts av sig själv när tillägget kan se att steget är gjort. Allt går att backa: kodstycket och kalkylarket finns kvar tills du tar bort dem.</p>

	<?php $cvrk_step( 1, is_array( $cvrk_import ) && $cvrk_on_map > 0, 'Importera och kontrollera översikten' ); ?>
	<p>
		<?php if ( is_array( $cvrk_import ) ) : ?>
			Importen kördes <?php echo esc_html( mysql2date( 'j M Y H:i', $cvrk_import['time'] ) ); ?>. <?php echo (int) $cvrk_on_map; ?> resor är på kartan.
		<?php else : ?>
			Importen är inte körd. <a href="<?php echo esc_url( Admin::url( 'import' ) ); ?>">Gå till Import</a>.
		<?php endif; ?>
		<a href="<?php echo esc_url( Admin::url( '', array( 'visa' => 'atgard' ) ) ); ?>">Visa resor som behöver åtgärd</a>.
	</p>

	<?php $cvrk_step( 2, $cvrk_compare ? $cvrk_compare_ok : null, 'Jämför med dagens karta' ); ?>
	<?php if ( $cvrk_compare ) : ?>
		<table class="widefat striped cvrk-table cvrk-narrow">
			<thead><tr><th>Språk</th><th>Tillägget</th><th>Dagens karta</th><th>Saknas i tillägget</th></tr></thead>
			<tbody>
				<?php foreach ( $cvrk_compare as $cvrk_code => $cvrk_c ) : ?>
					<tr>
						<td><?php echo esc_html( $cvrk_code ); ?></td>
						<td><?php echo (int) $cvrk_c['new']; ?></td>
						<td><?php echo null === $cvrk_c['old'] ? '<span class="cvrk-muted">ingen fil</span>' : (int) $cvrk_c['old']; ?></td>
						<td><?php echo $cvrk_c['missing'] ? '<span class="cvrk-warn">' . (int) $cvrk_c['missing'] . '</span>' : '0'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>Detaljer och förhandsvisning finns under <a href="<?php echo esc_url( Admin::url( 'karta' ) ); ?>">Karta</a>. Gör gärna ett par stickprov: öppna förhandsvisningen och klicka på några resor.</p>
	<?php else : ?>
		<p>Kartan är inte byggd ännu – <a href="<?php echo esc_url( Admin::url( 'karta' ) ); ?>">bygg den under Karta</a>.</p>
	<?php endif; ?>

	<?php $cvrk_step( 3, $cvrk_on && ! $cvrk_other, 'Byt från kodstycket till tillägget' ); ?>
	<ol>
		<li>
			Gå till <a href="<?php echo esc_url( admin_url( 'admin.php?page=snippets' ) ); ?>">Snippets</a> och <strong>avaktivera</strong> kodstycket för kartan (det som innehåller <code>CYKLAVANDRA_KARTA_NYCKEL</code> och <code>add_shortcode( 'resekarta'</code>). Radera det inte – det är reserven.
			<?php if ( $cvrk_other ) : ?>
				<br><span class="cvrk-warn">Just nu registreras [resekarta] av <?php echo esc_html( $cvrk_other ); ?>.</span>
			<?php else : ?>
				<br><span class="cvrk-yes">Inget kodstycke registrerar [resekarta] längre.</span>
			<?php endif; ?>
		</li>
		<li>
			Slå på tilläggets kortkod:
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvrk-inline">
				<?php wp_nonce_field( 'cvrk_toggle_shortcode' ); ?>
				<input type="hidden" name="action" value="cvrk_toggle_shortcode">
				<?php if ( $cvrk_on ) : ?>
					<span class="cvrk-yes">påslagen</span>
					<button class="button-link cvrk-delete" name="on" value="">Stäng av igen</button>
				<?php else : ?>
					<button class="button button-primary" name="on" value="1">Slå på tilläggets kortkod</button>
				<?php endif; ?>
			</form>
		</li>
	</ol>
	<p class="cvrk-muted">Gick något fel? Stäng av tilläggets kortkod här och aktivera kodstycket igen – då visas den gamla kartan som förut.</p>

	<?php $cvrk_step( 4, null, 'Kontrollera sidorna med kartan på varje språk' ); ?>
	<?php if ( $cvrk_iframes ) : ?>
		<p class="cvrk-warn">Några sidor visar den gamla kartfilen direkt (inte med kortkoden). Byt där till <code>[resekarta]</code> med samma inställningar:</p>
	<?php endif; ?>
	<?php if ( $cvrk_pages ) : ?>
		<p><?php echo count( $cvrk_pages ); ?> publicerade sidor visar kartan. Öppna några på varje språk och kontrollera att kartan syns, att knapparna står på rätt språk och att förvalen stämmer.</p>
		<table class="widefat striped cvrk-table cvrk-narrow">
			<thead><tr><th>Sida</th><th>Språk</th><th>Kartan</th></tr></thead>
			<tbody>
				<?php foreach ( $cvrk_pages as $cvrk_p ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $cvrk_p['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $cvrk_p['title'] ? $cvrk_p['title'] : '(utan namn)' ); ?></a> <span class="cvrk-muted"><?php echo esc_html( 'page' === $cvrk_p['type'] ? '' : $cvrk_p['type'] ); ?></span></td>
						<td><?php echo esc_html( $cvrk_p['lang'] ); ?></td>
						<td>
							<?php if ( 'iframe' === $cvrk_p['kind'] ) : ?>
								<span class="cvrk-warn">gammal kartfil direkt</span>
							<?php elseif ( 'unknown' === $cvrk_p['kind'] ) : ?>
								<span class="cvrk-muted">kunde inte kontrolleras – öppna sidan och titta</span>
							<?php elseif ( 'hidden_old' === $cvrk_p['kind'] ) : ?>
								<span class="cvrk-muted">gammal kod sparad i sidan, men den visas inte</span>
							<?php else : ?>
								<code><?php echo esc_html( $cvrk_p['code'] ); ?></code>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="cvrk-muted">Hittade inga publicerade sidor med kartan.</p>
	<?php endif; ?>

	<?php $cvrk_step( 5, isset( $cvrk_old['resor-sv.js'] ) ? ! $cvrk_sheet_recent : null, 'Stäng av kalkylarkets nattkörning' ); ?>
	<p>
		Öppna kalkylarket "Cykla &amp; Vandra - resedatabas karta" och välj menyn <strong>Cyklavandra → Slå av nattlig körning</strong>.
		Annars försöker det skicka kartdata till kodstycket varje natt och mejlar om att det inte går.
		Kalkylarket och dess webbapp kan ligga kvar en tid som reserv.
	</p>
	<?php if ( isset( $cvrk_old['resor-sv.js'] ) ) : ?>
		<p class="cvrk-muted">Kalkylarket uppdaterade kartfilerna senast <?php echo esc_html( wp_date( 'j M Y H:i', $cvrk_old['resor-sv.js'] ) ); ?>.<?php echo $cvrk_sheet_recent ? ' Det ser ut att fortfarande köra varje natt.' : ''; ?></p>
	<?php endif; ?>
	<p class="cvrk-muted">Tilläggets egen nattkörning: nästa <?php $cvrk_next = wp_next_scheduled( Automation::NIGHTLY ); echo $cvrk_next ? esc_html( wp_date( 'j M Y H:i', $cvrk_next ) ) : 'inte schemalagd'; ?>.</p>

	<?php $cvrk_step( 6, $cvrk_old ? false : true, 'Städa (när allt fungerat ett tag)' ); ?>
	<?php if ( $cvrk_old ) : ?>
		<p>De gamla kartfilerna i <code>uploads/karta/</code> (<?php echo esc_html( implode( ', ', array_keys( $cvrk_old ) ) ); ?>) används inte av tillägget. De kan tas bort när kodstycket är avstängt och inga sidor visar den gamla filen direkt.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Ta bort de gamla kartfilerna? Kodstycket kan då inte längre visa den gamla kartan.');">
			<?php wp_nonce_field( 'cvrk_delete_old_files' ); ?>
			<input type="hidden" name="action" value="cvrk_delete_old_files">
			<button class="button" <?php disabled( ! $cvrk_on || $cvrk_other || $cvrk_iframes ); ?>>Ta bort de gamla kartfilerna</button>
		</form>
	<?php else : ?>
		<p>De gamla kartfilerna är borta.</p>
	<?php endif; ?>
	<p class="cvrk-muted">Därefter kan kodstycket raderas i Code Snippets, och kalkylarket arkiveras.</p>
</div>
