function initChurchToolsAuthSettings()
{
	$(document).ready(function(){

		// Connect
		$('#churchtoolsauth_connect').click(function(e) {
			var button = $(this);

			data = $('#churchtoolsauth_form').serialize();
	    	data += '&action=connect';

	    	button.button('loading');
			fsAjax(data, laroute.route('churchtoolsauth.ajax'),
				function(response) {
					
					if ( response.status == 'success' ) {
						showFloatingAlert('success', response.msg_success);

						setTimeout(function(){
							location.reload();
						}, 2000); //Submit form to load person list

					} else {
						showAjaxError(response);
					}

					button.button('reset');
				}, true
			);
			e.preventDefault();
		});

		// Sync
		$('#churchtoolsauth_sync').click(function(e) {
			var button = $(this);

			data = $('#churchtoolsauth_form').serialize();
			data += '&action=sync';

			button.button('loading');

			$.ajaxSetup({
				timeout: 60000 //Timeout in milliseconds
			});

			fsAjax(data, laroute.route('churchtoolsauth.ajax'),
				function(response) {
					
					if ( response.status == 'success' ) {
						showFloatingAlert('success', response.msg_success);
					} else {
						showAjaxError(response);
					}

					button.button('reset');
				}, true
			);
			e.preventDefault();
		});

		$("#churchtoolsauth_admins").select2({
			ajax: {
				url: laroute.route('churchtoolsauth.ajax_search'),
				dataType: 'json',
				delay: 250,
				cache: true,
				data: function (params) {
					return {
						domain: 'person',
						q: params.term,
						page: params.page
					};
				},
			},
			allowClear: true,
			placeholder: $('#churchtoolsauth_admins').children('option:first').text(),
			tags: false,
			minimumInputLength: 2,
			language: {
	            inputTooShort: function(args) {
	                return text_inputTooShort;
	            },
				searching: function(args) {
	                return text_searching + '...';
	            },
				noResults: function(args) {
	                return text_noResults;
	            }
	        }
		});

	});
}

function initChurchToolsAuthMailSettings()
{
	$(document).ready(function(){

		$("#churchtoolsauth_groups_roles").select2({
			ajax: {
				url: laroute.route('churchtoolsauth.ajax_search'),
				dataType: 'json',
				delay: 250,
				cache: true,
				data: function (params) {
					return {
						domain: 'group_role',
						q: params.term,
						page: params.page
					};
				},
			},
			allowClear: true,
			placeholder: $('#churchtoolsauth_groups_roles').children('option:first').text(),
			tags: false,
			minimumInputLength: 2,
			language: {
	            inputTooShort: function(args) {
	                return text_inputTooShort;
	            },
				searching: function(args) {
	                return text_searching + '...';
	            },
				noResults: function(args) {
	                return text_noResults;
	            }
	        }
		});

	});
}