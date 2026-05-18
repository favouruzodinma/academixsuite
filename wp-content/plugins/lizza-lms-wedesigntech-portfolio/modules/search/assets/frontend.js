var wdtPortfolioSearchForm = {

	dtInit : function() {


		// Features field slider

			jQuery('.wdt-sf-features-slider').each(function() {

				var handle_start = jQuery(this).parents('.wdt-sf-features-field-holder').find('.wdt-sf-features-start');
				var handle_end = jQuery(this).parents('.wdt-sf-features-field-holder').find('.wdt-sf-features-end');

				var slider_handle_start = jQuery(this).find('.wdt-sf-features-slider-start-handle');
				var slider_handle_end = jQuery(this).find('.wdt-sf-features-slider-end-handle');

				var min_value = parseInt(jQuery(this).attr('data-min'), 10);
				var max_value = parseInt(jQuery(this).attr('data-max'), 10);
				var item_unit = jQuery(this).attr('data-itemunit');

				var updated_min_value = parseInt(jQuery(this).attr('data-updated-min'), 10);
				var updated_max_value = parseInt(jQuery(this).attr('data-updated-max'), 10);

				jQuery(this).slider({
					range: true,
					min: min_value,
					max: max_value,
					values: [ updated_min_value, updated_max_value ],
					slide: function(event, ui) {

						handle_start.val(ui.values[0]);
						handle_end.val(ui.values[1]);

						var features_range_start = ui.values[0] + item_unit;
						var features_range_end = ui.values[1] + item_unit;

						slider_handle_start.html(features_range_start);
						slider_handle_end.html(features_range_end);

					},
					stop: function(event, ui) {
						if(jQuery(this).hasClass('wdt-with-ajax-load')) {
							window.setTimeout(function(){
								wdtPortfolioFrontendUtils.wdtPortfolioLoadDataOutput();
							}, 250);
						}
					},
				});

			});


		// Submitting search form

			jQuery( 'body' ).delegate( '.wdt-submit-searchform', 'click', function(e) {

				var this_item = jQuery(this);

				if(this_item.attr('data-outputtype') == 'separate-page') {

					var separate_page_url = this_item.attr('data-separatepageurl');

					// Creating form with datas

					var formData = '';
					formData += '<form class="wdtSearchForm" action="' + separate_page_url + '" method="post"></form>';

					var searchForm = jQuery(formData);
					jQuery('body').append(searchForm);

					jQuery('.wdt-sf-fields-holder').each(function() {

						if(jQuery(this).find('.wdt-chosen-select').length) {

							var selected_items = [];
							selected_items.push(jQuery(this).find('.wdt-chosen-select').val());

							var item_name = jQuery(this).find('.wdt-chosen-select').attr('name');
							jQuery('.wdtSearchForm').append('<input type="text" name="'+item_name+'" value="'+selected_items+'" />');

						} else {
							var item_data = jQuery(this).clone();
							jQuery('.wdtSearchForm').append(item_data);
						}

					});

					jQuery(searchForm).submit();

					searchForm.remove();

				} else {

					wdtPortfolioFrontendUtils.wdtPortfolioLoadDataOutput();

				}

				e.preventDefault();

			});


		// On orderby item selection

			jQuery( 'body' ).delegate( '.wdt-sf-orderby-list a', 'click', function(e) {

				if(jQuery(this).hasClass('active')) {
					jQuery(this).removeClass('active');
				} else {
					jQuery( '.wdt-sf-orderby-list a' ).removeClass('active');
					jQuery(this).addClass('active');
				}

				if(jQuery(this).parents('.wdt-sf-orderby-list').hasClass('wdt-with-ajax-load')) {
					window.setTimeout(function(){
						wdtPortfolioFrontendUtils.wdtPortfolioLoadDataOutput();
					}, 250);
				}

				e.preventDefault();

			});


		// On others item selection

			jQuery( 'body' ).delegate( '.wdt-sf-others-list-item', 'click', function(e) {

				if(jQuery(this).hasClass('active')) {
					jQuery(this).removeClass('active');
				} else {
					jQuery(this).addClass('active');
				}

				if(!jQuery(this).hasClass('wdt-detect-location') && jQuery(this).hasClass('wdt-with-ajax-load')) {
					window.setTimeout(function(){
						wdtPortfolioFrontendUtils.wdtPortfolioLoadDataOutput();
					}, 250);
				}

				e.preventDefault();

			});


		// Ajax load on input change

			jQuery( 'body' ).delegate( '.wdt-sf-categories.wdt-with-ajax-load, .wdt-sf-features.wdt-with-ajax-load, .wdt-sf-tags.wdt-with-ajax-load, .wdt-sf-orderby.wdt-with-ajax-load, .wdt-sf-startdate.wdt-with-ajax-load', 'change', function() {

				window.setTimeout(function(){
					wdtPortfolioFrontendUtils.wdtPortfolioLoadDataOutput();
				}, 250);

			});

			jQuery( 'body' ).delegate( '.wdt-sf-keyword.wdt-with-ajax-load, .wdt-sf-mls-number.wdt-with-ajax-load', 'input', function() {

				window.setTimeout(function(){
					wdtPortfolioFrontendUtils.wdtPortfolioLoadDataOutput();
				}, 800);

			});

			jQuery( 'body' ).delegate( '.wdt-sf-keyword, .wdt-sf-mls-number', 'keypress', function(e) {

				if(e.which == 13) {
					jQuery('.wdt-submit-searchform').trigger('click');
					return false;
				}

			});


		// Load data on search form submit click

			jQuery('.wdt-submit-searchform.wdt-execute').trigger('click');


	},

};

jQuery(document).ready(function() {

	if(!wdtfrontendobject.elementorPreviewMode) {
		wdtPortfolioSearchForm.dtInit();
	}

});

( function( $ ) {

	var wdtPortfolioSearchFormJs = function($scope, $){
		wdtPortfolioFrontend.dtInit();
		wdtPortfolioSearchForm.dtInit();
	};

    $(window).on('elementor/frontend/init', function(){
		if(wdtfrontendobject.elementorPreviewMode) {
			elementorFrontend.hooks.addAction('frontend/element_ready/wdt-widget-sf-output-data-container.default', wdtPortfolioSearchFormJs);
			elementorFrontend.hooks.addAction('frontend/element_ready/wdt-widget-sf-submit-button.default', wdtPortfolioSearchFormJs);
		}
	});

} )( jQuery );