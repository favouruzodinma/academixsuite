
var dtDirectorFeaturedCommentsCommon = {

	dtInit : function() {

		jQuery('.wdt-ratings-holder span').mouseover(function(e) {
			if(!jQuery(this).parents('.wdt-ratings-holder').hasClass('rated')) {
				jQuery('.wdt-ratings-holder span').removeClass('zmdi zmdi-star');
				jQuery( this ).prevAll( 'span' ).andSelf().addClass('zmdi zmdi-star');
				jQuery( this ).nextAll( 'span' ).addClass('zmdi zmdi-star-outline');
			} else {
				setTimeout(function() { jQuery('.wdt-ratings-holder').removeClass('rated'); },100);
			}
			e.preventDefault;
		}).mouseout(function(e) {
			if(!jQuery(this).parents('.wdt-ratings-holder').hasClass('rated')) {
				jQuery('.wdt-ratings-holder span').removeClass('zmdi zmdi-star');
				jQuery( this ).prevAll( 'span' ).andSelf().addClass('zmdi zmdi-star');
				jQuery( this ).nextAll( 'span' ).addClass('zmdi zmdi-star-outline');
			} else {
				setTimeout(function() { jQuery('.wdt-ratings-holder').removeClass('rated'); },100);
			}
			e.preventDefault;
		});

		jQuery('.wdt-ratings-holder span').on('click', function(e) {
			if(!jQuery(this).parents('.wdt-ratings-holder').hasClass('rated')) {
				jQuery(this).prevAll('span').andSelf().addClass('zmdi zmdi-star');
				jQuery(this).parents('.wdt-ratings-holder').find('#wdt_rating').val(parseInt(jQuery(this).html(), 10));
				jQuery(this).parents('.wdt-ratings-holder').addClass('rated');
			}
			e.preventDefault;
		});

	},

};

jQuery(document).ready(function() {

	dtDirectorFeaturedCommentsCommon.dtInit();

});