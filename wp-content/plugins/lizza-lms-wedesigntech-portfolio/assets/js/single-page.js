var wdtPortfolioSinglePageUtils = {

	wdtPortfolioMapInitialize : function(this_item) {

	    var listing_latitude = this_item.attr('data-latitude');
	    var listing_longitude = this_item.attr('data-longitude');
	    var map_color = this_item.attr('data-mapcolor');

	    if(map_color == '') {
	    	map_color = wdtmapobject.defaultMapColor;
	    }

	    var default_zoom_level = wdtmapobject.defaultZoomLevel;
	    var default_map_type = wdtmapobject.defaultMapType;

	    var enableMapTypeControl = wdtmapobject.enableMapTypeControl;
	    var enableZoomControl = wdtmapobject.enableZoomControl;
	    var enableScaleControl = wdtmapobject.enableScaleControl;
	    var enableStreetViewControl = wdtmapobject.enableStreetViewControl;
	    var enableFullscreenControl = wdtmapobject.enableFullscreenControl;

	    var mapOptions = {
							flat:false,
							noClear:false,
							zoom: parseInt(default_zoom_level, 10),
							scrollwheel: false,
							draggable: true,
							disableDefaultUI:false,
							center: new google.maps.LatLng(listing_latitude, listing_longitude),
							mapTypeId: default_map_type.toLowerCase(),
							styles: [
								{stylers: [{hue: map_color}]},
							],

							mapTypeControl: enableMapTypeControl,
							zoomControl: enableZoomControl,
							scaleControl: enableScaleControl,
							streetViewControl: enableStreetViewControl,
							fullscreenControl: enableFullscreenControl
						};


	    var map_id = this_item.find('.wdt-listings-map-holder').attr('id');
	    if(document.getElementById(map_id)) {
	        var map_sp = new google.maps.Map(document.getElementById(map_id), mapOptions);
	    } else {
	        return;
	    }

	    google.maps.visualRefresh = true;

	    var point = new google.maps.LatLng(listing_latitude, listing_longitude);

	    wdtPortfolioSinglePageUtils.wdtPortfolioMapLocationMarker(point, map_sp, this_item);

	},

	wdtPortfolioMapLocationMarker : function(location, map_sp, this_item) {

		var marker_image = this_item.attr('data-markerimage');

    	var mapMarker = new wdtPortfolioCustomMarker(
			location,
			map_sp,
			{
				map_icon: marker_image,
				add_info: false,
			},
			''
		);

		return mapMarker;

	},

    wdtPortfolioCheckReadyState :  function(printWindow) {

        //if (printWindow.document.readyState == "complete") {
            printWindow.focus(); // necessary for IE >= 10
            printWindow.print();
            printWindow.close();
        //}

    },

};

var wdtPortfolioSinglePage = {

	dtInit : function() {

		// Initialze Map

			jQuery('.wdt-listings-map-container').each(function() {
				google.maps.event.addDomListener(window, 'load', wdtPortfolioSinglePageUtils.wdtPortfolioMapInitialize(jQuery(this)));
			});


		// Add to favourite list

			jQuery( 'body' ).delegate( '.wdt-listings-utils-favourite-item', 'click', function(e) {

                var this_item = jQuery(this);

				if(jQuery(this).hasClass('wdt-login-link')) {

                    this_item.parents('.wdt-listings-utils-favourite').prepend( '<span class="wdt-fav-please-login">'+ wdtfrontendobject.pleaseLogin +'</span>' );
                    window.setTimeout(function(){
						this_item.parents('.wdt-listings-utils-favourite').find('span.wdt-fav-please-login').remove();
					}, 2000);

                } else {

					var listing_id = this_item.attr('data-listingid');
					var user_id = this_item.attr('data-userid');

					if(jQuery(this).hasClass('addtofavourite')) {
						var favourite_label = 'addtofavourite';
					} else {
						var favourite_label = 'removefavourite';
					}

					jQuery.ajax({
						type: "POST",
						url: wdtfrontendobject.ajaxurl,
						data:
						{
							action: 'wdt_listing_favourite_marker',
							listing_id: listing_id,
							user_id: user_id,
						},
						beforeSend: function(){
							this_item.parents('.wdt-listings-utils-favourite').prepend( '<span><i class="fa fa-spinner fa-spin"></i></span>' );
						},
						success: function (response) {
							if(favourite_label == 'addtofavourite') {
								this_item.html('<span class="fa fa-heart"></span>');
								this_item.removeClass('addtofavourite');
								this_item.addClass('removefavourite');
							} else {
								this_item.html('<span class="far fa-heart"></span>');
								this_item.removeClass('removefavourite');
								this_item.addClass('addtofavourite');
							}
						},
						complete: function(){
							this_item.parents('.wdt-listings-utils-favourite').find("span:first").remove();
						}
					});

				}

				e.preventDefault();

			});

		// Print page

			jQuery( 'body' ).delegate( '.wdt-listings-utils-print-item', 'click', function(e) {

				var data = jQuery('body').find('#main').html();

				var printWindow = window.open('', wdtfrontendobject.printerTitle, 'height=600,width=1900');
				printWindow.document.write('<html><head><title>'+wdtfrontendobject.printerTitle+'</title>');

				// Directory Plugin CSS
				jQuery('link[id$="-css"]').each(function () {
					printWindow.document.write('<link rel="stylesheet" href="'+jQuery(this).attr('href')+'" type="text/css" media="all" />');
				});

				// Inline CSS
				jQuery('style[id$="-css"]').each(function () {
					printWindow.document.write('<style id="'+jQuery(this).attr('id')+'" type="text/css">'+jQuery(this).html()+'</style>');
				});

				printWindow.document.write('<link rel="stylesheet" href="'+wdtfrontendobject.pluginPath+'assets/css/print.css" type="text/css" media="all" />');

				printWindow.document.write('</head><body>');
				printWindow.document.write(data);
				printWindow.document.write('</body></html>');
				printWindow.document.close();

				setTimeout(function() {
					wdtPortfolioSinglePageUtils.wdtPortfolioCheckReadyState(printWindow);
				}, 1200);

				e.preventDefault();

			});


		// Contact form submit

			jQuery( 'body' ).delegate( '.wdt-contactform-submit-button', 'click', function(e) {

				var this_item = jQuery(this);
				var notification_box = this_item.parents('.wdt-listings-contactform').find('.wdt-contactform-notification-box');

				var form = jQuery('.wdt-listings-contactform')[0];
				var data = new FormData(form);
				data.append('action', 'wdt_process_listing_contactform');

				jQuery.ajax({
					type: "POST",
					url: wdtfrontendobject.ajaxurl,
					data: data,
					processData: false,
					contentType: false,
					cache: false,
					dataType: "JSON",
					beforeSend: function() {
						this_item.prepend( '<span><i class="fa fa-spinner fa-spin"></i></span>' );
					},
					success: function (response) {
						notification_box.removeClass('wdt-success wdt-failure');
						if(response.success) {
							notification_box.addClass('wdt-success');
							notification_box.html(response.message);
						} else {
							notification_box.addClass('wdt-failure');
							notification_box.html(response.message);
						}
					},
					complete: function() {
						this_item.find('span').remove();
					}
				});

				e.preventDefault();

			});


		// Send request to view contact details

			jQuery( 'body' ).delegate( '.wdt-listings-contactdetails-request', 'click', function(e) {

				var this_item = jQuery(this);
				var listing_id = this_item.attr('data-listingid');

				jQuery.ajax({
					type: "POST",
					url: wdtfrontendobject.ajaxurl,
					data:
					{
						action: 'wdt_listing_contactdetails_request',
						listing_id: listing_id,
					},
					dataType: "JSON",
					beforeSend: function() {
						this_item.prepend( '<span><i class="fa fa-spinner fa-spin"></i></span>' );
					},
					success: function (response) {
						if(response.success) {
							location.reload();
						} else {
							this_item.parents('.wdt-listings-contactdetails-request-container').append('<div class="wdt-contactdetails-request-notification-box">'+response.message+'</div>');
							window.setTimeout(function(){
								this_item.parents('.wdt-listings-contactdetails-request-container').find('.wdt-contactdetails-request-notification-box').remove();
							}, 2000);
						}
					},
					complete: function() {
						this_item.find('span').remove();
					}
				});

				e.preventDefault();

			});


		// Activity Tracker - Website Visit, Phone & Mobile Click

			jQuery( 'body' ).delegate( '.wdt-listings-contactdetails-list a.web, .wdt-listings-contactdetails-list a.phone, .wdt-listings-contactdetails-list a.mobile', 'click', function(e) {

				var this_item  = jQuery(this);
				var listing_id = this_item.attr('data-listingid');
				var user_id    = this_item.attr('data-userid');

				var activity_type = '';
				if(this_item.hasClass('web')) {
					activity_type = 'website';
				} else if(this_item.hasClass('phone')) {
					activity_type = 'phone';
				} else if(this_item.hasClass('mobile')) {
					activity_type = 'mobile';
				}

				jQuery.getJSON('https://geoip-db.com/json/geoip.php?jsonp=?').done(function(location) {

					var country = location.country_name;
					var city    = location.city;
					var zip     = location.postal;

					jQuery.ajax({
						type: "POST",
						url: wdtfrontendobject.ajaxurl,
						data:
						{
							action       : 'wdt_listing_activity_tracker_contactdetails',
							activity_type: activity_type,
							listing_id   : listing_id,
							user_id      : user_id,
							country      : country,
							city         : city,
							zip          : zip
						},
						dataType: "JSON",
						success: function (response) {
						}
					});

				});

			});


            if(jQuery('.wdt-portfolio-sticky-section').length) {
                let $stickyInstanceOptions = {
                    topSpacing: 0,
                    bottomSpacing: 0,
                    containerSelector: '.wdt-portfolio-sticky-section-container',
                    innerWrapperSelector: '.wdt-portfolio-sticky-section-inner',
                };
                $stickyInstance = new StickySidebar(jQuery('.wdt-portfolio-sticky-section')[0], $stickyInstanceOptions);
            }

	}

};

jQuery(document).ready(function() {

	if(!wdtfrontendobject.elementorPreviewMode) {
		wdtPortfolioSinglePage.dtInit();
	}

});


( function( $ ) {

	var wdtPortfolioSinglePageJs = function($scope, $){
		wdtPortfolioSinglePage.dtInit();
	};

    $(window).on('elementor/frontend/init', function(){
		if(wdtfrontendobject.elementorPreviewMode) {
			elementorFrontend.hooks.addAction('frontend/element_ready/wdt-widget-sp-map.default', wdtPortfolioSinglePageJs);
		}
	});

} )( jQuery );