var wdtPortfolioFeaturedComments = {

	dtInit : function() {

		// Comment gallery prettyphoto

			jQuery('.wdt_comment_gallery_item').each(function() {
				var attr = jQuery(this).attr('rel');
				if (jQuery('a[rel^="'+attr+'"]').length) {
					jQuery('a[rel^="'+attr+'"]').prettyPhoto({
						hook: 'rel',
						show_title: false,
						deeplinking: false,
						social_tools: false,
				});
				}
			});

		// File Upload

			jQuery('body').on('change', '.wdt-comment-media-upload', function() {
				var input = jQuery(this),
				inputFiles = input.get(0).files,
				selectedFiles = '';

				selectedFiles = inputFiles[0]['name'];
				if(inputFiles.length > 1) {
					selectedFiles = selectedFiles + ' + ' + (inputFiles.length-1);
				}

				jQuery('.wdt-comment-media-label').html(selectedFiles);

			});

	}

};


jQuery(document).ready(function() {

	if(!wdtfrontendobject.elementorPreviewMode) {
		wdtPortfolioFeaturedComments.dtInit();
	}

});


( function( $ ) {

	var wdtPortfolioFeaturedCommentsJs = function($scope, $){
		wdtPortfolioFeaturedComments.dtInit();
	};

    $(window).on('elementor/frontend/init', function(){
		if(wdtfrontendobject.elementorPreviewMode) {
			elementorFrontend.hooks.addAction('frontend/element_ready/wdt-widget-sp-featured-comments.default', wdtPortfolioFeaturedCommentsJs);
		}
	});

} )( jQuery );