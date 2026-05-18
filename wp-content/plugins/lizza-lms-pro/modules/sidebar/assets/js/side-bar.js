(function ($) {
    "use strict";
	$(document).ready(function() {
        if( $("#secondary").length ) {
            $('.secondary-sidebar')
                .theiaStickySidebar({
                    additionalMarginTop: 90,
                    containerSelector: $('#primary')
                });
        }
    });
})(jQuery);