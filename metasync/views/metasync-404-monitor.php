<style type="text/css">
	.wp-list-table .column-id {
		width: 6%;
	}

	.wp-list-table .column-uri {
		width: 40%;
	}

	.wp-list-table .column-hits_count {
		width: 8%;
	}

	.wp-list-table .column-date_time {
		width: 13%;
	}

	/* .wp-list-table .column-user_agent { width: 30%; } */
</style>

<div class="wrap">
	<!-- Forms are NOT created automatically, so you need to wrap the table in one to use features like bulk actions -->
	<form id="404-monitor-filter" method="post">
		<!-- For plugins, we also need to ensure that the form posts back to our current page -->
		<input type="hidden" name="page" value="<?php $request_data = sanitize_post($_REQUEST);
												echo esc_attr(sanitize_post($request_data['page'])) ?>" />
		<!-- Now we can render the completed list table -->
		<?php $Metasync404Monitor->display() ?>
	</form>
</div>