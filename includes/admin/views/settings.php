<?php
/**
 * Settings page.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Settings;
use CV_Resekarta\Trips;

defined( 'ABSPATH' ) || exit;

$cvrk_s = Settings::all();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cvrk_msg = isset( $_GET['cvrk_msg'] ) ? sanitize_key( wp_unslash( $_GET['cvrk_msg'] ) ) : '';
?>
<div class="wrap cvrk">
	<h1>Resekarta – inställningar</h1>
	<?php Admin::tabs(); ?>
	<?php
	if ( 'saved' === $cvrk_msg ) {
		Admin::notice( 'Inställningarna är sparade.' );
	} elseif ( 'mail_sent' === $cvrk_msg ) {
		Admin::notice( 'Testmejlet är skickat till ' . esc_html( $cvrk_s['notify_email'] ) . '.' );
	} elseif ( 'mail_failed' === $cvrk_msg ) {
		Admin::notice( 'Testmejlet kunde inte skickas. Kontrollera adressen, och att sajten kan skicka mejl (t.ex. kontaktformulären).', 'error' );
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cvrk_save_settings' ); ?>
		<input type="hidden" name="action" value="cvrk_save_settings">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cvrk-notify">Mejl för notiser</label></th>
				<td>
					<input type="text" class="regular-text" id="cvrk-notify" name="cvrk[notify_email]" value="<?php echo esc_attr( $cvrk_s['notify_email'] ); ?>">
					<p class="description">Hit skickas mejl om nya resor och fel i nattkörningen. Flera adresser: skilj med komma.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvrk-contact">Kontaktadress för geokodning</label></th>
				<td>
					<input type="email" class="regular-text" id="cvrk-contact" name="cvrk[geocode_contact]" value="<?php echo esc_attr( $cvrk_s['geocode_contact'] ); ?>">
					<p class="description">OpenStreetMap vill veta vem som slår upp platser. Adressen skickas med varje uppslag.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvrk-geocoder">Geokodning</label></th>
				<td>
					<select id="cvrk-geocoder" name="cvrk[geocoder]">
						<?php foreach ( Settings::GEOCODERS as $cvrk_key => $cvrk_label ) : ?>
							<option value="<?php echo esc_attr( $cvrk_key ); ?>" <?php selected( $cvrk_s['geocoder'], $cvrk_key ); ?>><?php echo esc_html( $cvrk_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvrk-google">Google-nyckel</label></th>
				<td>
					<input type="text" class="regular-text code" id="cvrk-google" name="cvrk[google_key]" value="<?php echo esc_attr( $cvrk_s['google_key'] ); ?>" autocomplete="off">
					<p class="description">Behövs bara om du väljer Google Geocoding ovan. OpenStreetMap behöver ingen nyckel.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvrk-style">Standard kartstil</label></th>
				<td>
					<select id="cvrk-style" name="cvrk[map_style]">
						<?php foreach ( Settings::MAP_STYLES as $cvrk_key => $cvrk_label ) : ?>
							<option value="<?php echo esc_attr( $cvrk_key ); ?>" <?php selected( $cvrk_s['map_style'], $cvrk_key ); ?>><?php echo esc_html( $cvrk_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">Listsidor</th>
				<td>
					<?php foreach ( Trips::CATEGORIES as $cvrk_key => $cvrk_label ) : ?>
						<p>
							<label>
								<span class="cvrk-label"><?php echo esc_html( $cvrk_label ); ?></span>
								<input type="text" class="regular-text code" name="cvrk[list_pages][<?php echo esc_attr( $cvrk_key ); ?>]" value="<?php echo esc_attr( isset( $cvrk_s['list_pages'][ $cvrk_key ] ) ? $cvrk_s['list_pages'][ $cvrk_key ] : '' ); ?>">
							</label>
						</p>
					<?php endforeach; ?>
					<p class="description">Sidorna där resorna listas. Resor som står på listorna kommer med på kartan. En resa på både cykel- och vandringslistan räknas som Cykel.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Kortkoden [resekarta]</th>
				<td>
					<label>
						<input type="checkbox" name="cvrk[shortcode_enabled]" value="1" <?php checked( $cvrk_s['shortcode_enabled'] ); ?>>
						Tillägget visar kartan för kortkoden [resekarta]
					</label>
					<p class="description">Kryssa i först när du har avaktiverat det gamla kodstycket för kartan i Code Snippets. Till dess kan du prova den nya kartan med kortkoden <code>[resekarta_test]</code> (samma inställningar, t.ex. <code>[resekarta_test tag="donau"]</code>) på en sida som inte är publicerad.</p>
					<?php if ( \CV_Resekarta\Shortcode::other_owner() ) : ?>
						<p class="description"><strong>Just nu registreras [resekarta] av <?php echo esc_html( \CV_Resekarta\Shortcode::other_owner() ); ?>.</strong></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Avinstallation</th>
				<td>
					<label>
						<input type="checkbox" name="cvrk[delete_on_uninstall]" value="1" <?php checked( $cvrk_s['delete_on_uninstall'] ); ?>>
						Ta bort all data (resornas fält, destinationer, regler och inställningar) när tillägget raderas
					</label>
					<p class="description">Lämna urkryssad om du inte är säker. Då finns allt kvar om tillägget installeras igen.</p>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Spara inställningar' ); ?>
	</form>

	<h2>Nattkörning</h2>
	<?php
	$cvrk_next = wp_next_scheduled( \CV_Resekarta\Automation::NIGHTLY );
	$cvrk_run  = get_option( \CV_Resekarta\Automation::LAST_RUN );
	?>
	<p>Varje natt omkring kl 03 läser tillägget listsidorna, ger nya resor kategori och destination (först destinationsreglerna, sedan dagsprogrammet), hämtar saknade koordinater från <?php echo esc_html( \CV_Resekarta\Settings::GEOCODERS[ $cvrk_s['geocoder'] ] ); ?> och bygger om kartan. Du får mejl bara när något hänt: nya resor, eller något som gick fel. När en ny sida publiceras görs samma sak efter ett par minuter.</p>
	<ul class="cvrk-list">
		<li>Nästa körning: <?php echo $cvrk_next ? esc_html( wp_date( 'j M Y H:i', $cvrk_next ) ) : '<span class="cvrk-warn">inte schemalagd</span>'; ?></li>
		<li>Senaste körning:
			<?php
			if ( is_array( $cvrk_run ) ) {
				echo esc_html( mysql2date( 'j M Y H:i', $cvrk_run['time'] ) . ' (' . $cvrk_run['source'] . '): ' . count( $cvrk_run['new'] ) . ' nya resor' );
				echo $cvrk_run['errors'] ? ' – <span class="cvrk-warn">' . esc_html( implode( ' ', $cvrk_run['errors'] ) ) . '</span>' : ' – inga fel';
				echo ! empty( $cvrk_run['mailed'] ) ? esc_html( ', mejl skickat' ) : '';
			} else {
				echo 'ingen ännu';
			}
			?>
		</li>
	</ul>
	<p class="description">På one.com körs WordPress schemalagda jobb bara när någon besöker sajten. Det räcker oftast, men vill du vara säker kan du lägga in ett schemalagt anrop (cron) i one.com:s kontrollpanel till <code><?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?></code>, t.ex. var 15:e minut.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cvrk_test_mail' ); ?>
		<input type="hidden" name="action" value="cvrk_test_mail">
		<button class="button">Skicka testmejl</button>
		<span class="cvrk-muted">Visar hur mejlet om nya resor ser ut.</span>
	</form>
</div>
