
var dtDirectorSocialShare = {

	dtInit : function() {

		// Social Share Toggle

			jQuery('.wdt-listings-social-share-container').each(function () {

				jQuery(this).find('.wdt-listings-social-share-item-icon').on('click', function(e) {
					jQuery('.wdt-listings-social-share-container').removeClass('active');
					jQuery(this).parents('.wdt-listings-social-share-container').toggleClass('active');
					e.stopPropagation();
				});

			});

			jQuery('body:not(.wdt-listings-social-share-container)').on('click', function() {
				jQuery('.wdt-listings-social-share-container').each(function () {

					if(jQuery(this).length > 0 && jQuery(this).hasClass('active')) {
						jQuery(this).toggleClass('active');
					}

				});
			});


	}

};


jQuery(document).ready(function() {

	if(!wdtfrontendobject.elementorPreviewMode) {
		dtDirectorSocialShare.dtInit();
	}

});


( function( $ ) {

	var dtDirectorSocialShareJs = function($scope, $){
		dtDirectorSocialShare.dtInit();
	};

    $(window).on('elementor/frontend/init', function(){
		if(wdtfrontendobject.elementorPreviewMode) {
			elementorFrontend.hooks.addAction('frontend/element_ready/wdt-widget-sp-social-share.default', dtDirectorSocialShareJs);
		}
	});

} )( jQuery );