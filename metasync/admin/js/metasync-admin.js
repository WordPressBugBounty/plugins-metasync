(function ($) {
	'use strict';

	/**
	 * All of the code for your admin-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */

	function metasync_syncPostsAndPages() {
		wp.ajax.post("lgSendCustomerParams", {})
			.done(function (response) {
				console.log(response);
			});
	}

	function metasyncGenerateAPIKey() {
		return Math.random().toString(36).substring(2, 15) +
			Math.random().toString(36).substring(2, 15);
	}

	function metasyncLGLogin(user, pass) {
		jQuery.post(ajaxurl, {
			action: 'lglogin',
			username: user, password: pass
		}, function (response) {
			if (typeof response.token !== "undefined") {
				$("#linkgraph_token").val(response.token);
				$("#linkgraph_customer_id").val(response.customer_id);
				$(".input.lguser,#lgerror").addClass('hidden');
				localStorage.setItem('token', response.token);
			} else {
				$("#lgerror").html(`${response.detail} (${response.kind})`).removeClass('hidden');
			}
		}
		);
	}

	function setToken() {
		if ($("#linkgraph_token") && $("#linkgraph_token").val()) {
			localStorage.setItem('token', $("#linkgraph_token").val());
		}
	}

	function addClassTableRowLocalSEO() {
		if (document.getElementsByClassName('form-table') && document.getElementById('local_seo_person_organization')) {
			const myElement = document.getElementsByTagName('tr');

			for (let i = 0; i < myElement.length; i++) {
				myElement[i].classList.add('metasync-seo-' + (i + 10));
			}
		}
	}

	function addClassTableRowSiteInfo() {
		if (document.getElementsByClassName('form-table') && document.getElementById('site_info_type')) {
			const myElement = document.getElementsByTagName('tr');

			for (let i = 0; i < myElement.length; i++) {
				myElement[i].classList.add('metasync-site-info-' + (i + 10));
			}
		}
	}

	function uploadMedia(title, text, input, src, closeBtn) {

		var mediaUploader;

		// If the uploader object has already been created, reopen the dialog
		if (mediaUploader) {
			mediaUploader.open();
			return;
		}
		// Extend the wp.media object
		mediaUploader = wp.media.frames.file_frame = wp.media({
			title: title,
			button: {
				text: text
			}, multiple: false
		});

		// When a file is selected, grab the URL and set it as the text field's value
		mediaUploader.on('select', function () {
			var attachment = mediaUploader.state().get('selection').first().toJSON();
			jQuery('#' + input).val(attachment.id);
			jQuery('#' + src).attr('src', attachment.url);
			jQuery('#' + src).attr('width', 300);
			jQuery('#' + closeBtn).attr('type', 'button');
			jQuery('#' + src).show();
			jQuery('#' + closeBtn).show();
		});
		// Open the uploader dialog
		mediaUploader.open();
	}

	function getLocalSeoOnLoadPage() {
		if (document.getElementsByClassName('form-table') && document.getElementById('local_seo_person_organization')) {
			var $type = $("#local_seo_person_organization").val();
			const classes = ['17', '18', '19', '20', '21', '24', '25'];
			if ($type == "Person") {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).hide();
				}
				$('.metasync-seo-15').show();
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).show();
				}
				$('.metasync-seo-15').hide();
			}
		}
	}

	function siteInfoOnLoadPage() {
		if (document.getElementsByClassName('form-table') && document.getElementById('site_info_type')) {
			var $type = $("#site_info_type").val();
			const classes = ['18', '19'];
			if ($type === 'blog' || $type === 'portfolio' || $type === 'otherpersonal') {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).hide();
				}
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).show();
				}
			}
		}
	}

	function deleteTime() {
		$(this).parent().remove();
	}

	function hideElementById(id) {
		if ($('#' + id)) {
			$('#' + id).hide()
		}
	}

	function removeValueById(id) {
		if ($('#' + id)) {
			$('#' + id).val('')
		}
	}

	$(function () {
		$("#addNewTime").on("click", function () {
			$('#daysTime').append(
				'<li>' +
				'<select name="metasync_options[localseo][days][]">' +
				'<option value="Monday">Monday</option>' +
				'<option value="Tuseday">Tuseday</option>' +
				'<option value="Wednesday">Wednesday</option>' +
				'<option value="Thursday">Thursday</option>' +
				'<option value="Friday">Friday</option>' +
				'<option value="Saturday">Saturday</option>' +
				'<option value="Sunday">Sunday</option>' +
				'</select>' +
				'<input type="text" name="metasync_options[localseo][times][]">' +
				'<button id="timeDelete">Delete</button>' +
				'</li>');
			return;
		});
		$(document).on('click', '#timeDelete', deleteTime);
	});

	function deleteNumber() {
		$(this).parent().remove();
	}

	$(function () {
		$("#addNewNumber").on("click", function () {
			$('#phone-numbers').append(
				'<li>' +
				'<select name="metasync_options[localseo][phonetype][]">' +
				'<option value="Customer Service">Customer Service</option>' +
				'<option value="Technical Support">Technical Support</option>' +
				'<option value="Billing Support">Billing Support</option>' +
				'<option value="Bill Payment">Bill Payment</option>' +
				'<option value="Sales">Sales</option>' +
				'<option value="Reservations">Reservations</option>' +
				'<option value="Credit Card Support">Credit Card Support</option>' +
				'<option value="Emergency">Emergency</option>' +
				'<option value="Baggage Tracking">Baggage Tracking</option>' +
				'<option value="Roadside Assistance">Roadside Assistance</option>' +
				'<option value="Package Tracking">Package Tracking</option>' +
				'</select>' +
				'<input type="text" name="metasync_options[localseo][phonenumber][]">' +
				'<button id="number-delete">Delete</button>' +
				'</li>');
			return;
		});
		$(document).on('click', '#number-delete', deleteNumber);
	});

	function deleteSourceUrl() {
		$(this).parent().remove();
	}
	$(function () {
		$("#addNewSourceUrl").on("click", function () {
			$('#source_urls').append(
				'<li>' +
				'<input type="text" class="regular-text" name="source_url[]">' +
				'<select name="search_type[]">' +
				'<option value="exact">Exact</option>' +
				'<option value="contain">Contain</option>' +
				'<option value="start">Start With</option>' +
				'<option value="end">End With</option>' +
				'</select>' +
				'<button id="source_url_delete">Remove</button>' +
				'</li>');
			return;
		});
		$(document).on('click', '#source_url_delete', deleteSourceUrl);
	});

	$(function () {

		setToken();

		$('body').on("click", "#wp_metasync_sync", function (e) {
			e.preventDefault();
			metasync_syncPostsAndPages();
		});
		$('body').on("click", "#metasync_settings_genkey_btn", function () {
			$("#apikey").val(metasyncGenerateAPIKey());
		});
		$('body').on("click", "#lgloginbtn", function () {
			if ($('#lgusername').val() == "" || $('#lgpassword').val() == "") {
				$('.input.lguser').toggleClass('hidden');
			} else {
				metasyncLGLogin($('#lgusername').val(), $('#lgpassword').val());
			}
		});

		$('body').on("click", "#local_seo_logo_close_btn", function () {
			removeValueById('local_seo_logo');
			hideElementById('local_seo_business_logo');
			hideElementById('local_seo_logo_close_btn');
		});

		$('body').on("click", "#site_google_logo_close_btn", function () {
			removeValueById('site_google_logo');
			hideElementById('site_google_logo_img');
			hideElementById('site_google_logo_close_btn');
		});

		$('body').on("click", "#site_social_image_close_btn", function () {
			removeValueById('site_social_share_image');
			hideElementById('site_social_share_img');
			hideElementById('site_social_image_close_btn');
		});

		$('body').on("click", "#logo_upload_button", function () {
			uploadMedia('Logo', 'Add', 'local_seo_logo', 'local_seo_business_logo', 'local_seo_logo_close_btn');
		});

		$('body').on("click", "#google_logo_btn", function () {
			uploadMedia('Site Google Logo', 'Add', 'site_google_logo', 'site_google_logo_img', 'site_google_logo_close_btn');
		});

		$('body').on("click", "#social_share_image_btn", function () {
			uploadMedia('Site Social Share Image', 'Add', 'site_social_share_image', 'site_social_share_img', 'site_social_image_close_btn');
		});

		$('body').on("click", "#robots_common1", function () {
			$('#robots_common1').prop('checked', true);
			$('#robots_common2').prop('checked', false);
		});

		$('body').on("click", "#robots_common2", function () {
			$('#robots_common1').prop('checked', false);
			$('#robots_common2').prop('checked', true);
		});

		addClassTableRowLocalSEO();

		addClassTableRowSiteInfo();

		getLocalSeoOnLoadPage();

		siteInfoOnLoadPage();

		$("#local_seo_person_organization").change(function () {
			const classes = ['17', '18', '19', '20', '21', '24', '25'];
			if (this.value === 'Person') {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).hide();
				}
				$('.metasync-seo-15').show();
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).show();
				}
				$('.metasync-seo-15').hide();
			}
		});

		$("#site_info_type").change(function () {
			const classes = ['18', '19'];
			if (this.value === 'blog' || this.value === 'portfolio' || this.value === 'otherpersonal') {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).hide();
				}
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).show();
				}
			}
		});

		$('#metasync-giapi-response').hide();

		$('body').on("click", "#metasync-btn-send", function () {

			var url = $('#metasync-giapi-url');
			var action = $('input[type="radio"]:checked');
			var response = $('#metasync-giapi-response');

			var urls = url.val().split('\n').filter(Boolean);

			var urls_str = urls[0];
			var is_bulk = false;
			if (urls.length > 1) {
				urls_str = urls;
				is_bulk = true;
			}

			jQuery.ajax({
				method: "POST",
				url: "admin-ajax.php",
				data: {
					action: "send_giapi",
					metasync_giapi_url: url.val(),
					metasync_giapi_action: action.val()
				}
			})
				.always(function (info) {

					response.show();

					$('.result-action').html('<strong>' + action.val() + '</strong>' + ' <br> ' + urls_str);

					if (!is_bulk) {
						if (typeof info.error !== 'undefined') {
							$('.result-status-code').text(info.error.code).siblings('.result-message').text(info.error.message);
						} else {
							var d = new Date();
							$('.result-status-code').text('Success').siblings('.result-message').text(d.toString());
						}
					} else {
						$('.result-status-code').text('Success').siblings('.result-message').text('Success');
						if (typeof info.error !== 'undefined') {
							$('.result-status-code').text(info.error.code).siblings('.result-message').text(info.error.message);
						} else {
							$.each(info, function (index, val) {

								if (typeof val.error !== 'undefined') {
									var error_code = '';
									if (typeof val.error.code !== 'undefined') {
										error_code = val.error.code;
									}
									var error_message = '';
									if (typeof val.error.message !== 'undefined') {
										error_message = val.error.message;
									}
									$('.result-status-code').text(error_code).siblings('.result-message').text(val.error.message);
								}
							});
						}
					}
				});
		});

		$('body').on("click", "#cancel-redirection", function () {
			$('#add-redirection-form').hide();
			$('#add-redirection').focus();
		});

		$('body').on("click", ".redirect_type", function () {
			if ($(this).val() === '410' || $(this).val() === '451') {
				$('#destination_url').val('');
				$('#destination').hide();
			} else {
				$('#destination').show();
			}
		});

		if ($("#post_redirection").is(':checked')) {
			$('.hide').fadeIn('slow')
		}
		$('body').on("change", "#post_redirection", function () {
			if (this.checked)
				$('.hide').fadeIn('slow')
			else
				$('.hide').fadeOut('slow')
		});

		$(document).ready(function () {
			if ($("#post_redirection").is(':checked')
				&& ($("#post_redirection_type").val() === '410'
					|| $("#post_redirection_type").val() === '451')) {
				$('#post_redirect_url').hide();
			}
		});

		$("#post_redirection_type").change(function () {
			if ($("#post_redirection").is(':checked')
				&& ($(this).val() === '410'
					|| $(this).val() === '451')) {
				$('#post_redirect_url').hide();
			} else {
				$('#post_redirect_url').show();
			}
		});

	});

	$(function () {
		var psconsole = $('#error-code-box');
		if (psconsole.length)
			psconsole.scrollTop(psconsole[0].scrollHeight - psconsole.height());
	});

	$(function () {
		$("#copy-clipboard-btn").on("click", function () {
			var hiddenInput = document.createElement("input");
			hiddenInput.setAttribute("value", document.getElementById('error-code-box').value);
			document.body.appendChild(hiddenInput);
			hiddenInput.select();
			document.execCommand("copy");
			document.body.removeChild(hiddenInput);
		});
	});

	function dateFormat() {
		var months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
		var m = new Date();
		return months[m.getMonth()] + " " + ('0' + m.getDate()).slice(-2) + ", " + m.getFullYear() + "  " + (m.getHours() > 12 ? '0' + m.getHours() % 12 : '0' + m.getHours()).slice(-2) + ":" + ('0' + m.getMinutes()).slice(-2) + ":" + ('0' + m.getSeconds()).slice(-2) + ' ' + (m.getHours() > 12 ? 'PM' : 'AM');
	}

	function sendCustomerParams() {

		jQuery.ajax({
			type: "post",
			url: "admin-ajax.php",
			data: {
				action: 'lgSendCustomerParams'
			},
			success: function (response) {

				if ($('#searchatlas-api-key') && $('#searchatlas-api-key').val() === '') {

					$('#sendAuthTokenTimestamp').html('Please save your SearchAtlas API key');
					$('#sendAuthTokenTimestamp').css({ color: 'red' });

				} else if (response && response.detail) {

					$('#sendAuthTokenTimestamp').html('Please provide a valid SearchAtlas API key');
					$('#sendAuthTokenTimestamp').css({ color: 'red' });

				} else if (response == null || !response.id) {

					// $('#sendAuthTokenTimestamp').html('Something went wrong. Please refresh your page');
					// $('#sendAuthTokenTimestamp').css({ color: 'red' });

				} else {
					var dateString = dateFormat();
					 $('#sendAuthTokenTimestamp').html(dateString);
					$('#sendAuthTokenTimestamp').css({ color: 'green' });
				}
			},
		});
	}


	jQuery(document).ready(function () {

		// sendCustomerParams();
		$("#sendAuthToken").on("click", function (e) {
			e.preventDefault();
			sendCustomerParams();
			
		});

		//hook into heartbeat-send: client will send the message 'marco' in the 'client' var inside the data array
		jQuery(document).on('heartbeat-send', function (e, data) {
			e.preventDefault();
			sendCustomerParams();
		});

		//hook into heartbeat-tick: client looks for a 'server' var in the data array and logs it to console
		jQuery(document).on('heartbeat-tick', function (e, data) {
			// console.log('heartbeat-tick:', data);
			// if(data['server'])
			// console.log('Server: ' + data['server']);
		});

		//hook into heartbeat-error: in case of error, let's log some stuff
		jQuery(document).on('heartbeat-error', function (e, jqXHR, textStatus, error) {
			console.log('BEGIN ERROR');
			console.log(textStatus);
			console.log(error);
			console.log('END ERROR');
		});
	});

})(jQuery);
