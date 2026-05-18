(function ($) {
    "use strict";
	$(document).ready(function () {
	    if( $(".single-post").length ) {
	        $(".lizza_lms_like_btn a").on("click",function() {
	            var btn = $(this);
	            var post_id = btn.data("postid");
	            var act = btn.data("action");

	            $.ajax({
	                type: "post",
	                url: lizza_lms_urls.ajaxurl,
	                data: { action: 'single_post_process_like',
	                        nonce: lizza_lms_urls.wpnonce,
	                        post_id: post_id,
	                        doaction: act
	                      },
	                success: function(data, textStatus, XMLHttpRequest){
	                        btn.find('span').text(data);
	                },
	                error: function(MLHttpRequest, textStatus, errorThrown){ }
	            });
	            return false;
	        });
	    }

        if($(".wdt-related-posts.wdt-related-carousel").length) {
            $(".wdt-related-posts.wdt-related-carousel").each(function(){

              var $min = parseInt($(this).attr('data-items'));

              if($(window).width() <= 767 || $('#primary').width() <= 570 ) { $min = 1; }

              var prv = $(this).find('.prev-arrow'); var nxt = $(this).find('.next-arrow');
              var pager = $(this).find('.carousel-pager');

              var $This = $(this).find('.tpl-blog-holder');
              $This.removeClass('apply-isotope');
              $This.find('.grid-sizer').remove();

              $This.carouFredSel({
                responsive: true,
                width: '100%',
                auto: false,
                prev: prv,
                next: nxt,
                pagination: pager,
                height: 'variable',
                items: { width: parseInt( 1200 / $min ),  height: 'variable', visible: { min: $min } },
                scroll: { items: 1 },
                onCreate: function () {
                    $(window).on("resize", function() {
                        $This.trigger('configuration', ['debug', false, true]);
                    }).trigger('resize');
                },
                swipe: {
                    onMouse: true,
                    onTouch: true
                }
              });
            });
        }

        lizza_lms_blog_related_article();
        function lizza_lms_blog_related_article(){

            var $related = $(".related-article");
            var $relatedflag = $(".related-flag");
            var effect = 'auto';

            if($related.length) {

                $related.mouseenter(function() {
                    effect = 'normal';
                    $related.addClass('open');
                }).mouseleave(function() {
                    $related.removeClass('open');
                });

                $relatedflag.waypoint(function (direction) {
                    if(effect == 'auto') {
                        if(direction == 'up'){
                            $related.removeClass('open');
                        } else {
                            $related.addClass('open');
                        }
                    }

                }, {
                    offset: '100%',
                    context: window
                });
            }
        }
    });
})(jQuery);