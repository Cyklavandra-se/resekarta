<?php
/**
 * Destination rules: an editable list in order (drag to reorder).
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Rules;

defined( 'ABSPATH' ) || exit;

$cvrk_rules = Rules::all();
$cvrk_dests = Destinations::all();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cvrk_msg = isset( $_GET['cvrk_msg'] ) ? sanitize_key( wp_unslash( $_GET['cvrk_msg'] ) ) : '';

/**
 * One rule row.
 */
$cvrk_row = function ( $i, $keyword, $destination ) {
	?>
	<tr>
		<td class="cvrk-handle" title="Dra för att flytta"><span class="dashicons dashicons-menu" aria-hidden="true"></span><span class="cvrk-rule-nr"><?php echo (int) $i + 1; ?></span></td>
		<td><input type="text" class="cvrk-rule-keyword regular-text" name="cvrk_rules[<?php echo (int) $i; ?>][keyword]" value="<?php echo esc_attr( $keyword ); ?>" aria-label="Nyckelord"></td>
		<td><?php Admin::destination_select( 'cvrk_rules[' . (int) $i . '][destination]', $destination ); ?></td>
		<td class="cvrk-rule-actions">
			<button type="button" class="button-link cvrk-rule-up" aria-label="Flytta upp">▲</button>
			<button type="button" class="button-link cvrk-rule-down" aria-label="Flytta ned">▼</button>
			<button type="button" class="button-link cvrk-rule-remove cvrk-delete">Ta bort</button>
		</td>
	</tr>
	<?php
};
?>
<div class="wrap cvrk">
	<h1>Resekarta – destinationsregler</h1>
	<?php Admin::tabs(); ?>
	<?php
	if ( 'saved' === $cvrk_msg ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Admin::notice( sprintf( 'Reglerna är sparade (%d st).', isset( $_GET['antal'] ) ? (int) $_GET['antal'] : count( $cvrk_rules ) ) );
	}
	?>
	<p>När en ny resa saknar destination prövas reglerna uppifrån. Den första regel vars nyckelord finns i resans svenska namn avgör destinationen (stora och små bokstäver spelar ingen roll). Dra i <span class="dashicons dashicons-menu"></span> för att ändra ordningen. Rader utan nyckelord eller destination tas bort när du sparar.</p>

	<p class="cvrk-rule-tester">
		<label for="cvrk-rule-test"><strong>Prova ett namn:</strong></label>
		<input type="text" id="cvrk-rule-test" class="regular-text" placeholder="t.ex. Cykla längs Donau från källan">
		<span id="cvrk-rule-test-result" class="cvrk-muted" aria-live="polite"></span>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cvrk_save_rules' ); ?>
		<input type="hidden" name="action" value="cvrk_save_rules">
		<table class="widefat striped cvrk-table cvrk-narrow" id="cvrk-rules">
			<thead><tr><th>#</th><th>Nyckelord i resans namn</th><th>Destination</th><th></th></tr></thead>
			<tbody>
				<?php
				foreach ( $cvrk_rules as $cvrk_i => $cvrk_rule ) {
					$cvrk_row( $cvrk_i, $cvrk_rule['keyword'], $cvrk_rule['destination'] );
				}
				?>
			</tbody>
		</table>
		<p><button type="button" class="button cvrk-rule-add">+ Lägg till regel</button></p>
		<?php submit_button( 'Spara reglerna' ); ?>
	</form>
	<template id="cvrk-rule-template">
		<?php $cvrk_row( 0, '', 0 ); ?>
	</template>
	<?php if ( ! $cvrk_dests ) : ?>
		<p class="cvrk-warn">Det finns inga destinationer ännu – lägg till dem under fliken Destinationer.</p>
	<?php endif; ?>
</div>
