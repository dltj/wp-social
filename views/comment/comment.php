<li <?php comment_class('social-comment social-clearfix '.$comment_type); ?> id="li-comment-<?php comment_ID(); ?>">
<div class="social-comment-inner social-clearfix" id="comment-<?php comment_ID(); ?>">
	<div class="social-comment-header">
		<div class="social-comment-author vcard">
			<?php
				switch ($comment_type) {
					case 'pingback':
						echo '<span class="social-comment-label">Pingback</span>';
					break;
					default:
						echo get_avatar($comment, 40);
					break;
				}

				if ($service === null or $service->show_full_comment($comment->comment_type)) {
					printf('<cite class="social-fn fn">%s</cite>', get_comment_author_link());
				}

				if ($depth > 1) {
					echo '<span class="social-replied social-imr">'.__('replied:', Social::$i18n).'</span>';
				}
			?>
		</div>
		<!-- .comment-author .vcard -->
		<div class="social-comment-meta">
			<span class="social-posted-from">
				<?php if ($status_url !== null): ?>
				<a href="<?php echo esc_url($status_url); ?>" title="<?php _e(sprintf('View on %s', $service->title()), Social::$i18n); ?>" target="_blank">
				<?php endif; ?>
				<span><?php _e('View', Social::$i18n); ?></span>
				<?php if ($status_url !== null): ?>
				</a>
				<?php endif; ?>
			</span>
			<a href="<?php echo esc_url(get_comment_link(get_comment_ID())); ?>" class="social-posted-when" target="_blank"><?php esc_html(printf(__('%s ago', Social::$i18n), human_time_diff(strtotime($comment->comment_date_gmt)))); ?></a>
		</div>
	</div>
	<div class="social-comment-body">
		<?php if ($comment->comment_approved == '0'): ?>
		<em class="comment-awaiting-moderation"><?php _e('Your comment is awaiting moderation.', 'social'); ?></em><br />
		<?php endif; ?>
		<?php esc_html(comment_text()); ?>
	</div>
	<?php if ($service === null or $service->show_full_comment($comment->comment_type)): ?>
	<div class="social-actions entry-meta">
		<?php
            comment_reply_link(array_merge($args, array('depth' => $depth, 'max_depth' => $args['max_depth'])));
            edit_comment_link(__('Edit', Social::$i18n), '<span class="comment-edit-link"> &middot; ', '</span>');

            if (!empty($social_items)) {
                echo '<div class="social-items-comment">'.$social_items.'</div>';
            }
        ?>
	</div>
	<?php endif; ?>
	<!-- .reply -->
</div><!-- #comment-<?php echo comment_ID(); ?> -->