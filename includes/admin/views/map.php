<?php
/**
 * The map: build status, comparison with the spreadsheet's files and a preview.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Languages;
use CV_Resekarta\Map_Compare;
use CV_Resekarta\Map_Data;
use CV_Resekarta\Shortcode;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only.
$cvrk_msg     = isset( $_GET['cvrk_msg'] ) ? sanitize_key( wp_unslash( $_GET['cvrk_msg'] ) ) : '';
$cvrk_preview = isset( $_GET['sprak'] ) ? sanitize_key( wp_unslash( $_GET['sprak'] ) ) : '';
// phpcs:enable

$cvrk_build = Map_Data::last_build();
$cvrk_names = Languages::instance()->active();
$cvrk_langs = $cvrk_build ? array_keys( $cvrk_build['languages'] ) : array();
if ( ! in_array( $cvrk_preview, $cvrk_langs, true ) ) {
	$cvrk_preview = $cvrk_langs ? $cvrk_langs[0] : 'sv';
}
$cvrk_name = function ( $code ) use ( $cvrk_names ) {
	foreach ( $cvrk_names as $wpml => $l ) {
		if ( Languages::map_code( $wpml ) === $code ) {
			return $l['name'];
		}
	}
	return $code;
};
$cvrk_error = get_option( 'cvrk_map_error' );
?>
<div class="wrap cvrk">
	<h1>Resekarta – karta</h1>
	<?php Admin::tabs(); ?>
	<?php
	if ( 'rebuilt' === $cvrk_msg ) {
		Admin::notice( 'Kartan är ombyggd.' );
	} elseif ( 'rebuild_failed' === $cvrk_msg ) {
		Admin::notice( 'Kartan kunde inte byggas om.', 'error' );
	}
	if ( $cvrk_error ) {
		Admin::notice( 'Senaste automatiska ombyggnaden misslyckades: ' . esc_html( $cvrk_error ), 'error' );
	}
	?>

	<p>Kartan byggs om automatiskt när en resa eller dess översättning sparas, när listsidorna läses och när något ändras i Resekarta. Den sparas som filer i <code><?php echo esc_html( str_replace( ABSPATH, '/', Map_Data::dir() ) ); ?></code>.</p>

	<div class="cvrk-summary">
		<?php if ( $cvrk_build ) : ?>
			<span>Senast byggd <?php echo esc_html( mysql2date( 'j M Y H:i', $cvrk_build['time'] ) ); ?> (version <?php echo esc_html( $cvrk_build['version'] ); ?>)</span>
			<?php foreach ( $cvrk_build['errors'] as $cvrk_e ) : ?>
				<span class="cvrk-warn"><?php echo esc_html( $cvrk_e ); ?></span>
			<?php endforeach; ?>
		<?php else : ?>
			<span class="cvrk-warn">Kartan har inte byggts ännu.</span>
		<?php endif; ?>
		<?php Admin::rebuild_button( 'karta' ); ?>
	</div>

	<?php if ( $cvrk_build ) : ?>
		<h2>Språk</h2>
		<table class="widefat striped cvrk-table cvrk-narrow">
			<thead>
				<tr>
					<th>Språk</th>
					<th>Resor på kartan</th>
					<th>Med egen översättning</th>
					<th>Dagens karta (kalkylarket)</th>
					<th>Datafil</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cvrk_build['languages'] as $cvrk_code => $cvrk_l ) : ?>
					<?php $cvrk_old = Map_Data::read_file( Map_Compare::old_file( $cvrk_code ) ); ?>
					<tr>
						<td><strong><?php echo esc_html( $cvrk_name( $cvrk_code ) ); ?></strong> <span class="cvrk-muted"><?php echo esc_html( $cvrk_code ); ?></span></td>
						<td><?php echo (int) $cvrk_l['trips']; ?></td>
						<td>
							<?php echo (int) $cvrk_l['translated']; ?>
							<?php if ( $cvrk_l['translated'] < $cvrk_l['trips'] ) : ?>
								<span class="cvrk-muted">– övriga visas på svenska</span>
							<?php endif; ?>
						</td>
						<td><?php echo null === $cvrk_old ? '<span class="cvrk-muted">finns inte</span>' : (int) count( $cvrk_old ) . ' resor'; ?></td>
						<td><a href="<?php echo esc_url( Map_Data::url() . 'resor-' . $cvrk_code . '.js?v=' . $cvrk_build['version'] ); ?>" target="_blank" rel="noopener">resor-<?php echo esc_html( $cvrk_code ); ?>.js</a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Jämförelse med dagens karta</h2>
		<p>Dagens karta läser filerna som kalkylarket skickar till <code>uploads/karta/</code>. Här jämförs de med tilläggets filer, resa för resa (efter länken).</p>
		<?php
		$cvrk_any = false;
		foreach ( array_keys( $cvrk_build['languages'] ) as $cvrk_code ) :
			$cvrk_old = Map_Data::read_file( Map_Compare::old_file( $cvrk_code ) );
			$cvrk_new = Map_Data::read_file( Map_Data::dir() . 'resor-' . $cvrk_code . '.js' );
			if ( null === $cvrk_old || null === $cvrk_new ) {
				continue;
			}
			$cvrk_any = true;
			$cvrk_c   = Map_Compare::compare( $cvrk_new, $cvrk_old, Map_Compare::wp_resolver() );
			?>
			<h3><?php echo esc_html( $cvrk_name( $cvrk_code ) ); ?></h3>
			<p>
				<?php
				printf(
					'Tillägget: %d resor. Dagens karta: %d resor. %d är helt lika.',
					(int) $cvrk_c['new_count'],
					(int) $cvrk_c['old_count'],
					(int) $cvrk_c['same']
				);
				?>
			</p>
			<?php if ( $cvrk_c['only_old'] ) : ?>
				<details open><summary class="cvrk-warn">Bara på dagens karta (<?php echo count( $cvrk_c['only_old'] ); ?>)</summary><ul class="cvrk-list">
					<?php foreach ( $cvrk_c['only_old'] as $cvrk_k => $cvrk_t ) : ?>
						<li><?php echo esc_html( $cvrk_t ); ?> <span class="cvrk-muted"><?php echo esc_html( $cvrk_k ); ?></span></li>
					<?php endforeach; ?>
				</ul></details>
			<?php endif; ?>
			<?php if ( $cvrk_c['only_new'] ) : ?>
				<details open><summary>Bara i tillägget (<?php echo count( $cvrk_c['only_new'] ); ?>)</summary><ul class="cvrk-list">
					<?php foreach ( $cvrk_c['only_new'] as $cvrk_k => $cvrk_t ) : ?>
						<li><?php echo esc_html( $cvrk_t ); ?> <span class="cvrk-muted"><?php echo esc_html( $cvrk_k ); ?></span></li>
					<?php endforeach; ?>
				</ul></details>
			<?php endif; ?>
			<?php foreach ( $cvrk_c['changed'] as $cvrk_field => $cvrk_rows ) : ?>
				<details><summary>Ändrad <?php echo esc_html( Map_Compare::FIELDS[ $cvrk_field ] ); ?> (<?php echo count( $cvrk_rows ); ?>)</summary>
					<table class="widefat striped cvrk-table cvrk-compare">
						<thead><tr><th>Resa</th><th>Dagens karta</th><th>Tillägget</th></tr></thead>
						<tbody>
						<?php foreach ( $cvrk_rows as $cvrk_r ) : ?>
							<tr><td><?php echo esc_html( $cvrk_r['title'] ); ?></td><td><?php echo esc_html( $cvrk_r['old'] ); ?></td><td><?php echo esc_html( $cvrk_r['new'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endforeach; ?>
		<?php endforeach; ?>
		<?php if ( ! $cvrk_any ) : ?>
			<p class="cvrk-muted">Inga filer från kalkylarket hittades i uploads/karta/ – inget att jämföra med.</p>
		<?php endif; ?>

		<h2>Förhandsvisning</h2>
		<p>
			<?php foreach ( $cvrk_langs as $cvrk_code ) : ?>
				<a class="button<?php echo $cvrk_code === $cvrk_preview ? ' button-primary' : ''; ?>" href="<?php echo esc_url( Admin::url( 'karta', array( 'sprak' => $cvrk_code ) ) . '#forhandsvisning' ); ?>"><?php echo esc_html( $cvrk_name( $cvrk_code ) ); ?></a>
			<?php endforeach; ?>
			<a class="button-link" href="<?php echo esc_url( Map_Data::url() . 'karta.html?lang=' . $cvrk_preview . '&v=' . $cvrk_build['version'] ); ?>" target="_blank" rel="noopener">Öppna i ny flik</a>
		</p>
		<iframe id="forhandsvisning" class="cvrk-preview" src="<?php echo esc_url( Map_Data::url() . 'karta.html?lang=' . $cvrk_preview . '&v=' . $cvrk_build['version'] ); ?>" title="Förhandsvisning av kartan" loading="lazy"></iframe>
	<?php endif; ?>

	<h2>Kortkoden</h2>
	<?php if ( Shortcode::enabled() ) : ?>
		<p><strong>Tillägget visar kartan för [resekarta].</strong>
		<?php if ( Shortcode::other_owner() ) : ?>
			<span class="cvrk-warn">Men [resekarta] registreras också av <?php echo esc_html( Shortcode::other_owner() ); ?> – avaktivera det.</span>
		<?php endif; ?>
		</p>
	<?php else : ?>
		<p>
			<strong>[resekarta] sköts inte av tillägget ännu</strong><?php echo Shortcode::other_owner() ? ' – den kommer från ' . esc_html( Shortcode::other_owner() ) : ''; ?>.
			Prova den nya kartan med <code>[resekarta_test]</code> på en sida som inte är publicerad. Den tar samma inställningar som <code>[resekarta]</code>.
			<?php if ( current_user_can( Admin::CAP_SETTINGS ) ) : ?>
				Bytet görs under <a href="<?php echo esc_url( Admin::url( 'installningar' ) ); ?>">Inställningar</a>.
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<p class="cvrk-muted">Exempel: <code>[resekarta]</code>, <code>[resekarta tag="donau"]</code>, <code>[resekarta tag="vandra+spanien+sjalvguidad" filters="no"]</code>, <code>[resekarta tag="donau,rhen" hojd="600"]</code>, <code>[resekarta kat="guidad" arstid="var" niva="2"]</code>. "+" betyder att resan ska ha alla taggarna, "," att det räcker med någon av dem.</p>
</div>
