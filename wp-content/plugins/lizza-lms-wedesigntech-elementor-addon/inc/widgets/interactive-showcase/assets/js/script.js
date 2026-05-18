(function ($) {
  
    const wdtInteractiveShowcaseWidgetHandler = function($scope, $) {

        const $showcaseItem = $scope.find('.wdt-interactive-showcase-container');
        const $hover_and_click = $showcaseItem.data('click');

        var hover_content_section = $showcaseItem.find('.wdt-interactive-showcase-list-wrapper ul li');
        var image_content_section = $showcaseItem.find('.wdt-interactive-showcase-content-wrapper').find('div');
        
        $showcaseItem.find('.wdt-interactive-showcase-list-wrapper ul li:first-child').addClass('wdt-interactive-showcase-active');
        $showcaseItem.find('.wdt-interactive-showcase-content-wrapper').find('div:first-child').addClass('wdt-interactive-showcase-active');

        if( $hover_and_click ) {
            // Content on click
            hover_content_section.click( function() {

                var content_id_name = $(this).attr('id');
                if( ! ($(this).hasClass('wdt-interactive-showcase-active')) ) {
                    $showcaseItem.find('ul li').removeClass('wdt-interactive-showcase-active');
                    $showcaseItem.find('.wdt-interactive-showcase-content-wrapper').find('div').removeClass('wdt-interactive-showcase-active');
                    $(this).addClass('wdt-interactive-showcase-active');
    
                    image_content_section.each(function() {
                        var this_image       = $(this);
                        var image_id_name    = this_image.attr('id');                
                        if( image_content_section.is('#' + content_id_name) ) {
                            if( image_id_name == content_id_name ) {
                                this_image.addClass('wdt-interactive-showcase-active');
                            } else {
                                this_image.removeClass('wdt-interactive-showcase-active');
                            }
                        }
                    });
                }
            });
        } else {
            // Content on hover
            hover_content_section.mouseover( function() {

                var content_id_name = $(this).attr('id');
                if( ! ($(this).hasClass('wdt-interactive-showcase-active')) ) {
                    $showcaseItem.find('ul li').removeClass('wdt-interactive-showcase-active');
                    $showcaseItem.find('.wdt-interactive-showcase-content-wrapper').find('div').removeClass('wdt-interactive-showcase-active');
                    $(this).addClass('wdt-interactive-showcase-active');
    
                    image_content_section.each(function() {
                        var this_image       = $(this);
                        var image_id_name    = this_image.attr('id');                
                        if( image_content_section.is('#' + content_id_name) ) {
                            if( image_id_name == content_id_name ) {
                                this_image.addClass('wdt-interactive-showcase-active');
                            } else {
                                this_image.removeClass('wdt-interactive-showcase-active');
                            }
                        }
                    });
                }
            });
        }
  
    };
  
    $(window).on('elementor/frontend/init', function () {
        elementorFrontend.hooks.addAction('frontend/element_ready/wdt-interactive-showcase.default', wdtInteractiveShowcaseWidgetHandler);
    });
  
  })(jQuery);