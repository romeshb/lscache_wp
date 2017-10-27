<?php
if ( ! defined( 'WPINC' ) ) die ;

$sapi_key = get_option( LiteSpeed_Cache_Admin_API::DB_SAPI_KEY ) ;

$media = LiteSpeed_Cache_Media::get_instance() ;
$img_count = $media->img_count() ;

?>

<div class="wrap">
	<h2>
		<?php echo __('LiteSpeed Cache Image Optimization', 'litespeed-cache') ; ?>
		<span class="litespeed-desc">
			v<?php echo LiteSpeed_Cache::PLUGIN_VERSION; ?>
		</span>
	</h2>
</div>
<div class="litespeed-wrap">
	<div class="litespeed-body">
		<h3 class="litespeed-title"><?php echo __('Auth Info', 'litespeed-cache') ; ?></h3>
		<?php if ( $sapi_key ) : ?>
			<?php echo __('Your API key is ', 'litespeed-cache') ; ?>
			<code><?php echo $sapi_key ; ?></code>
		<?php endif ; ?>
		<a href="<?php echo LiteSpeed_Cache_Utility::build_url( LiteSpeed_Cache::ACTION_SAPI, LiteSpeed_Cache_Admin_API::TYPE_REQUEST_KEY ) ; ?>" class="litespeed-btn-success">
			<?php echo __( 'Sync Key', 'litespeed-cache' ) ; ?>
		</a>
		<span class="litespeed-desc">
			<?php echo __( 'This will communicate with LiteSpeed server, get a free unique key for optimization requests.', 'litespeed-cache' ) ; ?>
		</span>

		<h3 class="litespeed-title"><?php echo __('Images', 'litespeed-cache') ; ?></h3>

		<p>Total images: <?php echo $img_count[ 'total_img' ] ; ?></p>
		<p>Images needed to request: <?php echo $img_count[ 'total_not_requested' ] ; ?></p>
		<a href="<?php echo LiteSpeed_Cache_Utility::build_url( LiteSpeed_Cache::ACTION_MEDIA, LiteSpeed_Cache_Media::TYPE_IMG_OPTIMIZE ) ; ?>" class="litespeed-btn-success">
			<?php echo __( 'Send Request to LiteSpeed Server', 'litespeed-cache' ) ; ?>
		</a>
		<div class="litespeed-desc">
			<?php echo __( 'This will send the optimization request with the images to LiteSpeed server.', 'litespeed-cache' ) ; ?>
		</div>

		<hr />

		<p>Requested images: <?php echo $img_count[ 'total_requested' ] ; ?></p>
		<p>Server finished images: <?php echo $img_count[ 'total_server_finished' ] ; ?></p>
		<p>Optimized images: <?php echo $img_count[ 'total_pulled' ] ; ?></p>
		<div class="litespeed-desc">
			<?php echo __( 'After LiteSpeed server finished optimization, it will notify your server to pull the optimized images.', 'litespeed-cache' ) ; ?>
			<?php echo __( 'All these processes are automatic.', 'litespeed-cache' ) ; ?>
		</div>

	</div>
</div>