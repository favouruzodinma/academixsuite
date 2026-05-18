jQuery(document).ready(function() {

	if(!wdtfrontendobject.elementorPreviewMode) {
		wdtPortfolioFrontendUtils.wdtPortfolioListingImageSwiperGallery();
	}

});


( function( $ ) {

	var dtDirectorMediaImagesJs = function($scope, $){
		wdtPortfolioFrontendUtils.wdtPortfolioListingImageSwiperGallery();
	};

    $(window).on('elementor/frontend/init', function(){
		if(wdtfrontendobject.elementorPreviewMode) {
			elementorFrontend.hooks.addAction('frontend/element_ready/wdt-widget-sp-media-images.default', dtDirectorMediaImagesJs);
		}
	});

} )( jQuery );