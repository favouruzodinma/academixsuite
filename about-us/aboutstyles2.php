<style id='lizza-woo-cart-notification-inline-css' type='text/css'>



/*--------------------------------------------------------------*/
    /* #region - Add-to-Cart Notification Widget */
/*--------------------------------------------------------------*/

    .wdt-shop-cart-widget.cart-notification-widget, .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-inner,
    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content { float: left; width: 100%; }

    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-close-button { font-size: 0; height: 25px; line-height: 0; position: absolute; right: 3px; top: 3px; text-align: center; width: 25px; -webkit-border-radius: 50%; border-radius: 50%; }

    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-close-button:before { content: "\2716"; display: block; font-size: 14px; font-weight: normal; line-height: 25px; }

    .wdt-shop-cart-widget.cart-notification-widget { max-width: 500px; position: fixed; bottom: 32px; left: 18px; width: auto; z-index: 999; -webkit-transition: var(--wdtBaseTransition); transition: var(--wdtBaseTransition); }

    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-inner { padding: 20px; }
    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content > * { display: table-cell; vertical-align: middle; }
    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content-thumb { line-height: 0; padding: 0 10px; width: 120px; }
    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content-info { padding: 5px 10px; text-align: left; }

    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content-thumb a,
    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content-thumb a img { display: block; width: 100%; }

    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content-info a { display: block; font-size: 18px; font-weight: bold; }

    .wdt-shop-cart-widget.cart-notification-widget { opacity: 0; visibility: hidden; }
    .wdt-shop-cart-widget.cart-notification-widget.wdt-shop-cart-widget-active { opacity: 1; visibility: visible; }


    .wdt-shop-cart-widget.cart-notification-widget { background-color: var(--wdtBodyBGColor); }

    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-close-button:before { color: var(--wdtAccentTxtColor); }

    .wdt-shop-cart-widget.cart-notification-widget { -webkit-box-shadow: 0 1px 3px 1px rgba(var(--wdtHeadAltColorRgb),0.25); box-shadow: 0 1px 3px 1px rgba(var(--wdtHeadAltColorRgb),0.25); }

/* #endregion - Add-to-Cart Notification Widget */



/*--------------------------------------------------------------*/
    /* #region - Add-to-Cart Sidebar Widget */
/*--------------------------------------------------------------*/

    .wdt-shop-cart-widget.activate-sidebar-widget { height: 100%; position: fixed; right: 0; top: 0; width: 350px; z-index: 999992; -webkit-transform: translateX(100%); transform: translateX(100%); -webkit-transition: var(--wdtBaseTransition); transition: var(--wdtBaseTransition); }

    .wdt-shop-cart-widget.activate-sidebar-widget:before { content: ""; }

    .wdt-shop-cart-widget.activate-sidebar-widget.wdt-shop-cart-widget-active { -webkit-transform: translateX(0); transform: translateX(0); }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-inner { height: 100%; padding: 45px 0 120px; position: relative; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header { border-width: 0 0 1px; padding-left: 15px; padding-right: 45px; position: absolute; left: 0; top: 0; width: 100%; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 { font-size: 15px; font-weight: bold; line-height: 45px; margin: 0; text-transform: uppercase; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 span, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header a { height: 45px; position: absolute; top: 0; text-align: center; width: 45px; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 span { font-size: 18px; right: 0; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 a { font-size: 0; line-height: 0; margin-right: 1px; overflow: hidden; right: 100%; text-indent: -9999px; -webkit-transform: translateX(100%); transform: translateX(100%); }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 a:before { content: "\2716"; display: block; font-size: 15px; font-weight: normal; line-height: 45px; text-indent: 0; }

    .wdt-shop-cart-widget[class*="sidebar"].activate-sidebar-widget:hover .wdt-shop-cart-widget-header h3 a { -webkit-transform: translateX(0); transform: translateX(0); }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content { float: left; width: 100%; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-inner,
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget,
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li { float: left; width: 100%; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget,
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .total { padding: 0 15px; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li { border-width: 1px 0; display: inline; margin: -1px 0 0 !important; padding: 15px 25px 15px 50px; position: relative; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li:first-child { border-top-width: 0; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li:last-child { border-bottom-width: 0; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a:not(.remove) { font-weight: 600; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a img { margin: auto; position: absolute; left: 0; top: 16px; width: 40px; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a.remove { font-size: 16px; height: 20px; line-height: 20px; margin: auto; position: absolute; bottom: 0; left: auto; right: 0; top: 0 !important; text-align: center; width: 20px; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a.remove:not(:focus) { text-decoration: none; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li:before { content: none !important; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li .quantity { display: table; margin: 0; font-size: 14px; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer { position: absolute; bottom: 0; left: 0; width: 100%; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer::before { content: ""; height: 1px; position: absolute; left: 0; right: 0; top: 0; width: auto; z-index: -1; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p { height: 50px; line-height: 50px; margin: 0; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.total { padding: 0 15px; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.total strong { float: left; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.total .amount { float: right; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.buttons { display: flex; grid-gap: 1px; }
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.buttons a { height: 100%; line-height: inherit; margin: 0; padding-top: 0; padding-bottom: 0; text-align: center; width: 50%; -webkit-border-radius: 0; border-radius: 0; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart__empty-message { margin: 0; padding: 15px; }

    .wdt-shop-cart-widget-overlay { background-color: rgba(var(--wdtHeadAltColorRgb),0.7); height: 100%; position: fixed; top: 0; left: 0; width: 100%; z-index: 999991; -webkit-transition: opacity .25s ease, visibility 0s ease .25s; transition: opacity .25s ease, visibility 0s ease .25s; }


    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header a, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li { border-style: solid;  }


    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 a, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a.remove, .wdt-shop-cart-widget-overlay { opacity: 0; visibility: hidden; }

    .wdt-shop-cart-widget[class*="sidebar"].activate-sidebar-widget:hover .wdt-shop-cart-widget-header h3 a,
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li:hover a.remove,
    .wdt-shop-cart-widget.activate-sidebar-widget.wdt-shop-cart-widget-active + .wdt-shop-cart-widget-overlay { opacity: 1; visibility: visible; }


    /* Default Color - Colors */
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a:not(.remove):not(:hover),
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.total .amount { color: var(--wdtHeadAltColor); }


    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 a, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 a:hover { color: var(--wdtAccentTxtColor); }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a.remove { color: var(--wdtAccentTxtColor) !important; }


    /* Default Color - Borders */
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer::before { -webkit-box-shadow: 0 2px 6px 0 rgba(var(--wdtHeadAltColorRgb),0.5); box-shadow: 0 2px 6px 0 rgba(var(--wdtHeadAltColorRgb),0.5); }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header a, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li { border-color: rgba(var(--wdtHeadAltColorRgb),0.075); }


    /* Default Color - BG */
    .wdt-shop-cart-widget.activate-sidebar-widget { background-color: #f7f7f7; }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer { background-color: var(--wdtBodyBGColor); }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.buttons a.checkout, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a.remove,

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.buttons a:not(.checkout),

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 a, .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .woocommerce-mini-cart-footer p.buttons a:hover, .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-close-button { background-color: var(--wdtHeadAltColor); }

    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 span { background-color: rgba(var(--wdtBodyBGColorRgb),0.15); }

    .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-close-button:hover,
    .wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-content .product_list_widget li a.remove:hover { background-color: #9f2124; }


    /* #endregion - Add-to-Cart Sidebar Widget */


/*--------------------------------------------------------------*/
    /* #region - Responsive */
/*--------------------------------------------------------------*/

    /*----*****---- << Mobile (Landscape) >> ----*****----*/

    /* Common Styles for the devices below 767px width */
    @media only screen and (max-width: 767px) {

        .wdt-shop-cart-widget.cart-notification-widget { margin: auto; bottom: 5px; left: 0; right: 0; }

    }


    /* Note: Design for a width of 480px */
    @media only screen and (min-width: 480px) and (max-width: 767px) {

        .wdt-shop-cart-widget.cart-notification-widget { max-width: 420px; }

    }

    /* Common Styles for the devices below 479px width */
    @media only screen and (max-width: 479px) {

        .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content > * { display: table; margin: auto; text-align: center !important; }

        .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content-info { font-size: 11px; }
        .wdt-shop-cart-widget.cart-notification-widget .wdt-shop-cart-widget-content-info a { font-size: 13px; }


		.wdt-shop-cart-widget[class*="sidebar"] .wdt-shop-cart-widget-header h3 a { right: 0; -webkit-border-radius: 50%; border-radius: 50%; -webkit-transform: scale(0); transform: scale(0); }

		.wdt-shop-cart-widget[class*="sidebar"].activate-sidebar-widget:hover .wdt-shop-cart-widget-header h3 a { -webkit-border-radius: 0; border-radius: 0; -webkit-transform: scale(1); transform: scale(1); }

    }

    /*----*****---- << Mobile >> ----*****----*/

    /* Mobile Portrait Size to Mobile Landscape Size (devices and browsers) */
    @media only screen and (min-width: 320px) and (max-width: 479px) {

        .wdt-shop-cart-widget.cart-notification-widget { max-width: 290px; }


		.wdt-shop-cart-widget.activate-sidebar-widget { max-width: 290px; }
		.wdt-shop-cart-widget.activate-sidebar-widget { width: 290px; }

    }


/* #endregion - Responsive */

</style>