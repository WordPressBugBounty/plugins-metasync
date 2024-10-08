<style type="text/css">
	.column-sources_from {
		width: 30%;
	}

	.column-url_redirect_to {
		width: 30%;
	}

	.column-http_code {
		width: 10%;
	}

	.column-hits_count {
		width: 10%;
	}

	.column-status {
		width: 10%;
	}

	.column-last_accessed_at {
		width: 10%;
	}
</style>

<div class="wrap">
	<!-- Forms are NOT created automatically, so you need to wrap the table in one to use features like bulk actions -->
	<form id="redirection-form" method="post">
		<?php include "metasync-add-redirection.php";
		$request_data = sanitize_post($_REQUEST); ?>
		<!-- For plugins, we also need to ensure that the form posts back to our current page -->
		<input type="hidden" name="page" value="<?php echo esc_attr($request_data['page']) ?>" />
		<!-- Now we can render the completed list table -->
		<?php $MetasyncRedirection->display() ?>
	</form>
</div>