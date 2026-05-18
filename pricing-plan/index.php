<?php
// Start session and load required files
require_once __DIR__ . '/../includes/autoload.php';

// Get database connection
$db = Database::getPlatformConnection();

// Fetch plans from database for public display
$plans = [];
$exchange_rate = 1500; // USD to NGN conversion rate

try {
	// Fetch all active plans, excluding free trial if you don't want to show it
	$stmt = $db->prepare("SELECT * FROM plans WHERE is_active = 1 AND slug != 'free-trial' ORDER BY sort_order, price_monthly ASC");
	$stmt->execute();
	$plans = $stmt->fetchAll();

	// If no plans found, try without is_active filter (for backward compatibility)
	if (empty($plans)) {
		$stmt = $db->query("SELECT * FROM plans WHERE slug != 'free-trial' ORDER BY sort_order, price_monthly ASC");
		$plans = $stmt->fetchAll();
	}
} catch (Exception $e) {
	error_log("Failed to fetch plans for public display: " . $e->getMessage());
	$plans = [];
}

// Function to get default features based on plan

// Helper function to format storage
if (!function_exists('formatStorage')) {
	function formatStorage($mb)
	{
		if ($mb >= 1024) {
			return round($mb / 1024, 1) . ' GB storage';
		}
		return $mb . ' MB storage';
	}
}

// Function to parse JSON features from database or use defaults
if (!function_exists('parseFeatures')) {
	function parseFeatures($featuresJson, $plan)
	{
		// If features exist in database, use them
		if (!empty($featuresJson)) {
			try {
				$features = json_decode($featuresJson, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($features) && !empty($features)) {
					return $features;
				}
			} catch (Exception $e) {
				error_log("Error parsing features JSON: " . $e->getMessage());
			}
		}
	}
}

// Function to calculate yearly savings percentage
if (!function_exists('calculateYearlySavings')) {
	function calculateYearlySavings($monthlyPrice, $yearlyPrice)
	{
		if ($monthlyPrice <= 0 || $yearlyPrice <= 0) {
			return 0;
		}

		$monthlyTotal = $monthlyPrice * 12;
		if ($monthlyTotal <= 0) {
			return 0;
		}

		$savings = (1 - ($yearlyPrice / $monthlyTotal)) * 100;
		return max(0, min(100, round($savings, 0)));
	}
}

// Function to format price
if (!function_exists('formatPrice')) {
	function formatPrice($price)
	{
		if ($price == 0) {
			return 'Free';
		}

		if ($price < 1) {
			return number_format($price, 2);
		}

		return number_format($price, 0);
	}
}

// Function to get NGN price
if (!function_exists('getNairaPrice')) {
	function getNairaPrice($usdPrice, $exchange_rate = 1500)
	{
		if ($usdPrice <= 0) {
			return 'Free';
		}

		$ngnPrice = $usdPrice * $exchange_rate;
		return '₦' . number_format($ngnPrice, 0);
	}
}
?>
<!DOCTYPE html>
<html lang="en-US">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">

	<link rel="profile" href="https://gmpg.org/xfn/11">


	<title>Pricing Plan &#8211; Academixsuite : A multi tenant school management software for mordern school.</title>
	<meta name='robots' content='max-image-preview:large'>
	<style>
		img:is([sizes="auto" i], [sizes^="auto," i]) {
			contain-intrinsic-size: 3000px 1500px
		}
	</style>
	<link rel='dns-prefetch' href='//maps.googleapis.com'>
	<link rel='dns-prefetch' href='//fonts.googleapis.com'>
	<link rel="alternate" type="application/rss+xml" title="Academixsuite.&raquo; Feed" href="../feed/index">
	<link rel="alternate" type="application/rss+xml" title="Academixsuite.&raquo; Comments Feed" href="../comments/feed/index">
	<script type="text/javascript">
		/* <![CDATA[ */
		window._wpemojiSettings = {
			"baseUrl": "https:\/\/s.w.org\/images\/core\/emoji\/16.0.1\/72x72\/",
			"ext": ".png",
			"svgUrl": "https:\/\/s.w.org\/images\/core\/emoji\/16.0.1\/svg\/",
			"svgExt": ".svg",
			"source": {
				"concatemoji": "https:\/\/lizza.wpengine.com\/lms\/wp-includes\/js\/wp-emoji-release.min.js?ver=6.8.3"
			}
		};
		/*! This file is auto-generated */
		! function(s, n) {
			var o, i, e;

			function c(e) {
				try {
					var t = {
						supportTests: e,
						timestamp: (new Date).valueOf()
					};
					sessionStorage.setItem(o, JSON.stringify(t))
				} catch (e) {}
			}

			function p(e, t, n) {
				e.clearRect(0, 0, e.canvas.width, e.canvas.height), e.fillText(t, 0, 0);
				var t = new Uint32Array(e.getImageData(0, 0, e.canvas.width, e.canvas.height).data),
					a = (e.clearRect(0, 0, e.canvas.width, e.canvas.height), e.fillText(n, 0, 0), new Uint32Array(e.getImageData(0, 0, e.canvas.width, e.canvas.height).data));
				return t.every(function(e, t) {
					return e === a[t]
				})
			}

			function u(e, t) {
				e.clearRect(0, 0, e.canvas.width, e.canvas.height), e.fillText(t, 0, 0);
				for (var n = e.getImageData(16, 16, 1, 1), a = 0; a < n.data.length; a++)
					if (0 !== n.data[a]) return !1;
				return !0
			}

			function f(e, t, n, a) {
				switch (t) {
					case "flag":
						return n(e, "\ud83c\udff3\ufe0f\u200d\u26a7\ufe0f", "\ud83c\udff3\ufe0f\u200b\u26a7\ufe0f") ? !1 : !n(e, "\ud83c\udde8\ud83c\uddf6", "\ud83c\udde8\u200b\ud83c\uddf6") && !n(e, "\ud83c\udff4\udb40\udc67\udb40\udc62\udb40\udc65\udb40\udc6e\udb40\udc67\udb40\udc7f", "\ud83c\udff4\u200b\udb40\udc67\u200b\udb40\udc62\u200b\udb40\udc65\u200b\udb40\udc6e\u200b\udb40\udc67\u200b\udb40\udc7f");
					case "emoji":
						return !a(e, "\ud83e\udedf")
				}
				return !1
			}

			function g(e, t, n, a) {
				var r = "undefined" != typeof WorkerGlobalScope && self instanceof WorkerGlobalScope ? new OffscreenCanvas(300, 150) : s.createElement("canvas"),
					o = r.getContext("2d", {
						willReadFrequently: !0
					}),
					i = (o.textBaseline = "top", o.font = "600 32px Arial", {});
				return e.forEach(function(e) {
					i[e] = t(o, e, n, a)
				}), i
			}

			function t(e) {
				var t = s.createElement("script");
				t.src = e, t.defer = !0, s.head.appendChild(t)
			}
			"undefined" != typeof Promise && (o = "wpEmojiSettingsSupports", i = ["flag", "emoji"], n.supports = {
				everything: !0,
				everythingExceptFlag: !0
			}, e = new Promise(function(e) {
				s.addEventListener("DOMContentLoaded", e, {
					once: !0
				})
			}), new Promise(function(t) {
				var n = function() {
					try {
						var e = JSON.parse(sessionStorage.getItem(o));
						if ("object" == typeof e && "number" == typeof e.timestamp && (new Date).valueOf() < e.timestamp + 604800 && "object" == typeof e.supportTests) return e.supportTests
					} catch (e) {}
					return null
				}();
				if (!n) {
					if ("undefined" != typeof Worker && "undefined" != typeof OffscreenCanvas && "undefined" != typeof URL && URL.createObjectURL && "undefined" != typeof Blob) try {
						var e = "postMessage(" + g.toString() + "(" + [JSON.stringify(i), f.toString(), p.toString(), u.toString()].join(",") + "));",
							a = new Blob([e], {
								type: "text/javascript"
							}),
							r = new Worker(URL.createObjectURL(a), {
								name: "wpTestEmojiSupports"
							});
						return void(r.onmessage = function(e) {
							c(n = e.data), r.terminate(), t(n)
						})
					} catch (e) {}
					c(n = g(i, f, p, u))
				}
				t(n)
			}).then(function(e) {
				for (var t in e) n.supports[t] = e[t], n.supports.everything = n.supports.everything && n.supports[t], "flag" !== t && (n.supports.everythingExceptFlag = n.supports.everythingExceptFlag && n.supports[t]);
				n.supports.everythingExceptFlag = n.supports.everythingExceptFlag && !n.supports.flag, n.DOMReady = !1, n.readyCallback = function() {
					n.DOMReady = !0
				}
			}).then(function() {
				return e
			}).then(function() {
				var e;
				n.supports.everything || (n.readyCallback(), (e = n.source || {}).concatemoji ? t(e.concatemoji) : e.wpemoji && e.twemoji && (t(e.twemoji), t(e.wpemoji)))
			}))
		}((window, document), window._wpemojiSettings);
		/* ]]> */
	</script>
	<style id='wp-emoji-styles-inline-css' type='text/css'>
		img.wp-smiley,
		img.emoji {
			display: inline !important;
			border: none !important;
			box-shadow: none !important;
			height: 1em !important;
			width: 1em !important;
			margin: 0 0.07em !important;
			vertical-align: -0.1em !important;
			background: none !important;
			padding: 0 !important;
		}
	</style>
	<?php
	include_once('./style1.php');
	?>
	<link rel='stylesheet' id='b549b30414ed46447538ef1d3a028552-css' href='../../css?family=Red+Hat+Display:300,400,500,600,700,800,900,300italic,italic,500italic,600italic,700italic,800italic,900italic&#038;subset=latin-ext' type='text/css' media='all'>
	<link rel='stylesheet' id='9cf5893edf1d7e4d60526d4dd68092d4-css' href='../../css-1?family=DM+Sans:100,200,300,400,500,600,700,800,900&#038;subset=latin-ext' type='text/css' media='all'>
	<link rel='stylesheet' id='3313e79ffe037d389688456f7efde7a0-css' href='../../css-2?family=Manrope:200,300,400,500,600,700,800&#038;subset=latin-ext' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-lms-css' href='../wp-content/themes/lizza-lms/style.css?ver=1.0.7' type='text/css' media='all'>
	<style id='lizza-lms-inline-css' type='text/css'>
		:root {
			--wdtPrimaryColor: #14452f;
			--wdtPrimaryColorRgb: 20, 69, 47;
			--wdtSecondaryColor: #7cff77;
			--wdtSecondaryColorRgb: 124, 255, 119;
			--wdtTertiaryColor: #f2f8f1;
			--wdtTertiaryColorRgb: 242, 248, 241;
			--wdtBodyBGColor: #ffffff;
			--wdtBodyBGColorRgb: 255, 255, 255;
			--wdtBodyTxtColor: #394630;
			--wdtBodyTxtColorRgb: 57, 70, 48;
			--wdtHeadAltColor: #22281E;
			--wdtHeadAltColorRgb: 34, 40, 30;
			--wdtLinkColor: #22281E;
			--wdtLinkColorRgb: 34, 40, 30;
			--wdtLinkHoverColor: #14452f;
			--wdtLinkHoverColorRgb: 20, 69, 47;
			--wdtBorderColor: #E7E7E7;
			--wdtBorderColorRgb: 231, 231, 231;
			--wdtAccentTxtColor: #ffffff;
			--wdtAccentTxtColorRgb: 255, 255, 255;
			--wdtFontTypo_Base: "Manrope", sans-serif;
			--wdtFontWeight_Base: 400;
			--wdtFontSize_Base: 16px;
			--wdtLineHeight_Base: 1.7;
			--wdtFontTypo_Alt: "DM Sans", sans-serif;
			--wdtFontWeight_Alt: 700;
			--wdtFontSize_Alt: 68px;
			--wdtLineHeight_Alt: 1.2;
			--wdtFontTypo_H1: "DM Sans", sans-serif;
			--wdtFontWeight_H1: 700;
			--wdtFontSize_H1: 68px;
			--wdtLineHeight_H1: 1.2;
			--wdtFontTypo_H2: "DM Sans", sans-serif;
			--wdtFontWeight_H2: 700;
			--wdtFontSize_H2: 55px;
			--wdtLineHeight_H2: 1.2;
			--wdtFontTypo_H3: "DM Sans", sans-serif;
			--wdtFontWeight_H3: 700;
			--wdtFontSize_H3: 40px;
			--wdtLineHeight_H3: 1.2;
			--wdtFontTypo_H4: "DM Sans", sans-serif;
			--wdtFontWeight_H4: 700;
			--wdtFontSize_H4: 30px;
			--wdtLineHeight_H4: 1.2;
			--wdtFontTypo_H5: "DM Sans", sans-serif;
			--wdtFontWeight_H5: 700;
			--wdtFontSize_H5: 24px;
			--wdtLineHeight_H5: 1.2;
			--wdtFontTypo_H6: "DM Sans", sans-serif;
			--wdtFontWeight_H6: 700;
			--wdtFontSize_H6: 20px;
			--wdtLineHeight_H6: 1.2;
			--wdtFontTypo_Ext: "DM Sans", sans-serif;
			--wdtFontWeight_Ext: 500;
			--wdtFontSize_Ext: 14px;
			--wdtLineHeight_Ext: 1.15;
		}
	</style>
	<link rel='stylesheet' id='lizza-icons-css' href='../wp-content/themes/lizza-lms/assets/css/icons.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-base-css' href='../wp-content/themes/lizza-lms/assets/css/base.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-grid-css' href='../wp-content/themes/lizza-lms/assets/css/grid.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-layout-css' href='../wp-content/themes/lizza-lms/assets/css/layout.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-widget-css' href='../wp-content/themes/lizza-lms/assets/css/widget.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-additional-css-css' href='../wp-content/themes/lizza-lms/assets/css/additional-css.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='site-breadcrumb-css' href='../wp-content/plugins/lizza-lms-plus/modules/breadcrumb/assets/css/breadcrumb.css?ver=6.8.3' type='text/css' media='all'>
	<link rel='stylesheet' id='site-header-css' href='../wp-content/plugins/lizza-lms-plus/modules/header/assets/css/header.css?ver=6.8.3' type='text/css' media='all'>
	<link rel='stylesheet' id='site-loader-css' href='../wp-content/plugins/lizza-lms-plus/modules/site-loader/layouts/loader-1/assets/css/loader-1.css?ver=1.0.2' type='text/css' media='all'>
	<link rel='stylesheet' id='site-sidebar-css' href='../wp-content/plugins/lizza-lms-pro/modules/sidebar/assets/css/sidebar.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-blog-css' href='../wp-content/themes/lizza-lms/modules/blog/assets/css/blog.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-blog-archive-simple-css' href='../wp-content/themes/lizza-lms/modules/blog/templates/simple/assets/css/blog-archive-simple.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='jquery-bxslider-css' href='../wp-content/themes/lizza-lms/modules/blog/assets/css/jquery.bxslider.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-breadcrumb-css' href='../wp-content/themes/lizza-lms/modules/breadcrumb/assets/css/breadcrumb.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-comments-css' href='../wp-content/themes/lizza-lms/modules/comments/assets/css/comments.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-footer-css' href='../wp-content/themes/lizza-lms/modules/footer/assets/css/footer.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-header-css' href='../wp-content/themes/lizza-lms/modules/header/assets/css/header.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-pagination-css' href='../wp-content/themes/lizza-lms/modules/pagination/assets/css/pagination.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-magnific-popup-css' href='../wp-content/themes/lizza-lms/modules/post/assets/css/magnific-popup.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-quick-search-css' href='../wp-content/themes/lizza-lms/modules/search/assets/css/search.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-secondary-css' href='../wp-content/themes/lizza-lms/modules/sidebar/assets/css/sidebar.css?ver=1.0.7' type='text/css' media='all'>
	<link rel='stylesheet' id='lizza-woo-css' href='../wp-content/themes/lizza-lms/modules/woocommerce/assets/css/default.css?ver=1.0.7' type='text/css' media='all'>

	<?php
	include_once('./style2.php');
	?>


	<link rel='stylesheet' id='google-fonts-1-css' href='../../css-4?family=DM+Sans%3A100%2C100italic%2C200%2C200italic%2C300%2C300italic%2C400%2C400italic%2C500%2C500italic%2C600%2C600italic%2C700%2C700italic%2C800%2C800italic%2C900%2C900italic%7CManrope%3A100%2C100italic%2C200%2C200italic%2C300%2C300italic%2C400%2C400italic%2C500%2C500italic%2C600%2C600italic%2C700%2C700italic%2C800%2C800italic%2C900%2C900italic&#038;display=swap&#038;ver=6.8.3' type='text/css' media='all'>
	<link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin="">
	<script type="text/javascript" src="../wp-includes/js/jquery/jquery.min.js?ver=3.7.1" id="jquery-core-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/jquery-migrate.min.js?ver=3.4.1" id="jquery-migrate-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/woocommerce/assets/js/jquery-blockui/jquery.blockUI.min.js?ver=2.7.0-wc.9.1.4" id="jquery-blockui-js" defer="defer" data-wp-strategy="defer"></script>
	<script type="text/javascript" id="wc-add-to-cart-js-extra">
		/* <![CDATA[ */
		var wc_add_to_cart_params = {
			"ajax_url": "\/lms\/wp-admin\/admin-ajax.php",
			"wc_ajax_url": "\/lms\/?wc-ajax=%%endpoint%%",
			"i18n_view_cart": "View cart",
			"cart_url": "https:\/\/lizza.wpengine.com\/lms\/cart\/",
			"is_cart": "",
			"cart_redirect_after_add": "no"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js?ver=9.1.4" id="wc-add-to-cart-js" defer="defer" data-wp-strategy="defer"></script>
	<script type="text/javascript" src="../wp-content/plugins/woocommerce/assets/js/js-cookie/js.cookie.min.js?ver=2.1.4-wc.9.1.4" id="js-cookie-js" defer="defer" data-wp-strategy="defer"></script>
	<script type="text/javascript" id="woocommerce-js-extra">
		/* <![CDATA[ */
		var woocommerce_params = {
			"ajax_url": "\/lms\/wp-admin\/admin-ajax.php",
			"wc_ajax_url": "\/lms\/?wc-ajax=%%endpoint%%"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js?ver=9.1.4" id="woocommerce-js" defer="defer" data-wp-strategy="defer"></script>
	<script type="text/javascript" id="wc-cart-fragments-js-extra">
		/* <![CDATA[ */
		var wc_cart_fragments_params = {
			"ajax_url": "\/lms\/wp-admin\/admin-ajax.php",
			"wc_ajax_url": "\/lms\/?wc-ajax=%%endpoint%%",
			"cart_hash_key": "wc_cart_hash_d79814e9b660126373daa0caf4c2c422",
			"fragment_name": "wc_fragments_d79814e9b660126373daa0caf4c2c422",
			"request_timeout": "5000"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.min.js?ver=9.1.4" id="wc-cart-fragments-js" defer="defer" data-wp-strategy="defer"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/chart.min.js?ver=6.8.3" id="dtlms-chart-js"></script>
	<link rel="https://api.w.org/" href="../wp-json/index">
	<link rel="alternate" title="JSON" type="application/json" href="../wp-json/wp/v2/pages/22163">
	<link rel="EditURI" type="application/rsd+xml" title="RSD" href="https://lizza.wpengine.com/lms/xmlrpc.php?rsd">
	<link rel="canonical" href="index">
	<link rel='shortlink' href='index.htm?p=22163'>
	<link rel="alternate" title="oEmbed (JSON)" type="application/json+oembed" href="../wp-json/oembed/1.0/embed-8?url=https%3A%2F%2Flizza.wpengine.com%2Flms%2Fpricing-plan%2F">
	<link rel="alternate" title="oEmbed (XML)" type="text/xml+oembed" href="../wp-json/oembed/1.0/embed-9?url=https%3A%2F%2Flizza.wpengine.com%2Flms%2Fpricing-plan%2F&#038;format=xml">
	<style type="text/css" media="all" id="wcs_styles"></style> <noscript>
		<style>
			.woocommerce-product-gallery {
				opacity: 1 !important;
			}
		</style>
	</noscript>
	<meta name="generator" content="Elementor 3.23.3; features: e_optimized_css_loading, additional_custom_breakpoints, e_lazyload; settings: css_print_method-external, google_font-enabled, font_display-swap">
	<link rel="preconnect" href="//code.tidio.co">
	<style>
		.e-con.e-parent:nth-of-type(n+4):not(.e-lazyloaded):not(.e-no-lazyload),
		.e-con.e-parent:nth-of-type(n+4):not(.e-lazyloaded):not(.e-no-lazyload) * {
			background-image: none !important;
		}

		@media screen and (max-height: 1024px) {

			.e-con.e-parent:nth-of-type(n+3):not(.e-lazyloaded):not(.e-no-lazyload),
			.e-con.e-parent:nth-of-type(n+3):not(.e-lazyloaded):not(.e-no-lazyload) * {
				background-image: none !important;
			}
		}

		@media screen and (max-height: 640px) {

			.e-con.e-parent:nth-of-type(n+2):not(.e-lazyloaded):not(.e-no-lazyload),
			.e-con.e-parent:nth-of-type(n+2):not(.e-lazyloaded):not(.e-no-lazyload) * {
				background-image: none !important;
			}
		}
	</style>
	<style class='wp-fonts-local' type='text/css'>
		@font-face {
			font-family: Inter;
			font-style: normal;
			font-weight: 300 900;
			font-display: fallback;
			src: url('../wp-content/plugins/woocommerce/assets/fonts/Inter-VariableFont_slnt,wght.woff2') format('woff2');
			font-stretch: normal;
		}

		@font-face {
			font-family: Cardo;
			font-style: normal;
			font-weight: 400;
			font-display: fallback;
			src: url('../wp-content/plugins/woocommerce/assets/fonts/cardo_normal_400.woff2') format('woff2');
		}
	</style>
	<link rel="icon" href="../wp-content/uploads/sites/12/2023/11/Lizza-Fav-Icon-1.png" sizes="32x32">
	<link rel="icon" href="../wp-content/uploads/sites/12/2023/11/Lizza-Fav-Icon-1.png" sizes="192x192">
	<link rel="apple-touch-icon" href="../wp-content/uploads/sites/12/2023/11/Lizza-Fav-Icon-1.png">
	<meta name="msapplication-TileImage" content="https://lizza.wpengine.com/lms/wp-content/uploads/sites/12/2023/11/Lizza-Fav-Icon-1.png">
	<style type="text/css" id="wp-custom-css">
		div[class*="list-item-wrapper"] div[class*="list-thumb"]:before {
			z-index: 0 !important;
		}

		.scroll_tabs_container .scroll_tab_left_button::before {
			position: relative;
			display: block;
			left: -40px;
		}

		#dtlms-course-curriculum-popup .dtlms-curriculum-details .dtlms-curriculum-detailed-links .dtlms-toggle-group-set .dtlms-toggle-content {
			margin: -5px;
		}

		@media screen and (max-width:1280px) {
			.mobile-menu ul li.has-mega-menu ul li.menu-item-object-wdt_mega_menus .elementor-heading-title {
				margin: 0;
			}
		}
	</style>
</head>

<body class="wp-singular page-template page-template-elementor_header_footer page page-id-22163 wp-theme-lizza-lms theme-lizza-lms lizza-plus-1.0.2 lizza-pro-1.0.0 woocommerce-no-js elementor-default elementor-template-full-width elementor-kit-6 elementor-page elementor-page-22163">
	<div class="pre-loader loader1">
		<div class="loader-inner">
			<span class="loader-text"></span>
		</div>
	</div>
	<a class="skip-link screen-reader-text" href="#main">Skip to content</a>

	<!-- **Wrapper** -->
	<div class="wrapper">

		<!-- ** Inner Wrapper ** -->
		<div class="inner-wrapper">


			<!-- ** Header Wrapper ** -->
			<div id="header-wrapper" class="header-top-relative">

				<!-- **Header** -->
				<?php
				include_once('../pagefolder/header.php');
				?>
				<!-- **Header - End ** -->

				<!-- ** Slider ** -->

				<!-- ** Slider End ** -->

				<!-- ** Breadcrumb ** -->
				<section class="main-title-section-wrapper aligncenter">
					<div class="main-title-section-container">
						<div class="container">
							<div class="main-title-section">
								<h1>Pricing Plan</h1>
							</div>
							<div class="breadcrumb"><a href="../">Home</a><span class=" breadcrumb-default-delimiter"></span><span class="current">Pricing Plan</span></div>
						</div>
					</div>
					<div class="main-title-section-bg"></div>
				</section> <!-- ** Breadcrumb End ** -->

			</div>
			<!-- ** Header Wrapper - End ** -->

			<!-- **Main** -->
			<div id="main">


				<!-- ** Container ** -->
				<div class="wdt-elementor-container-fluid">
					<div data-elementor-type="wp-page" data-elementor-id="22163" class="elementor elementor-22163">
						<section class="elementor-section elementor-top-section elementor-element elementor-element-1054fd6 elementor-section-boxed elementor-section-height-default elementor-section-height-default" data-id="1054fd6" data-element_type="section" data-settings="{&quot;wdt_bg_image&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_laptop&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_tablet_extra&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_tablet&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_mobile_extra&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_mobile&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_position&quot;:&quot;center center&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}">
							<div class="elementor-container elementor-column-gap-no">
								<div class="elementor-column elementor-col-100 elementor-top-column elementor-element elementor-element-7e5af06" data-id="7e5af06" data-element_type="column">
									<div class="elementor-widget-wrap elementor-element-populated">
										<div class="elementor-element elementor-element-86f30b9 elementor-widget__width-initial elementor-widget-tablet__width-inherit center elementor-invisible elementor-widget elementor-widget-wdt-heading" data-id="86f30b9" data-element_type="widget" data-settings="{&quot;split_heading&quot;:&quot;true&quot;,&quot;wdt_enable_inview_status&quot;:&quot;true&quot;,&quot;_animation&quot;:&quot;fadeInRight&quot;,&quot;title_vertical_align&quot;:&quot;center&quot;,&quot;subtitle_vertical_align&quot;:&quot;center&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}" data-widget_type="wdt-heading.default">
											<div class="elementor-widget-container">
												<div class="wdt-heading-holder" id="wdt-heading-86f30b9">
													<div class="wdt-heading-subtitle-wrapper wdt-heading-align-center"><span class="wdt-heading-subtitle">SCHOOL MANAGEMENT PLANS</span></div>
													<h2 class="wdt-heading-title-wrapper wdt-heading-align-center wdt-heading-deco-wrapper"><span class="wdt-heading-title">Flexible <span class="wdt-split-heading-wrapper"><span class="wdt-split-heading-title">P</span><span class="wdt-split-heading-title">r</span><span class="wdt-split-heading-title">i</span><span class="wdt-split-heading-title">c</span><span class="wdt-split-heading-title">i</span><span class="wdt-split-heading-title">n</span><span class="wdt-split-heading-title">g</span></span> <span class="wdt-split-heading-wrapper"><span class="wdt-split-heading-title">O</span><span class="wdt-split-heading-title">p</span><span class="wdt-split-heading-title">t</span><span class="wdt-split-heading-title">i</span><span class="wdt-split-heading-title">o</span><span class="wdt-split-heading-title">n</span><span class="wdt-split-heading-title">s</span></span> for Every Institution</span></h2>
												</div>
											</div>
										</div>
										<?php if (!empty($plans)): ?>
											<div class="elementor-element elementor-element-2cd219d wdt-pricing-tab-style-a elementor-invisible elementor-widget elementor-widget-wdt-tabs" data-id="2cd219d" data-element_type="widget" data-settings="{&quot;_animation&quot;:&quot;fadeInLeft&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}" data-widget_type="wdt-tabs.default">
												<div class="elementor-widget-container">
													<div class="wdt-tabs-container wdt-layout-horizontal wdt-template-default" data-class-items="wdt-layout-horizontal wdt-template-default">
														<div class="wdt-tabs-list-wrapper">
															<ul class="wdt-tabs-list">
																<li><a href="#wdt-tabs-0">
																		<div class="wdt-content-title">Monthly Billing</div>
																	</a></li>
																<li><a href="#wdt-tabs-1">
																		<div class="wdt-content-title">Annual Billing</div>
																	</a></li>
															</ul>
														</div>
														<div class="wdt-tabs-content-wrapper">
															<!-- Monthly Plans Tab -->
															<div id="wdt-tabs-0" class="wdt-tabs-content">
																<div class="elementor-element elementor-section elementor-top-section elementor-element elementor-element-e628abc elementor-section-full_width elementor-section-height-default elementor-section-height-default">
																	<div class="elementor-container elementor-column-gap-no">
																		<?php
																		$planIndex = 0;
																		foreach ($plans as $plan):
																			$features = parseFeatures($plan['features'], $plan);
																			if ($plan['price_monthly'] > 0):
																				$planClass = '';
																				if ($planIndex == 0) {
																					$planClass = 'elementor-column elementor-col-33 elementor-top-column elementor-element elementor-element-0540ccf';
																				} elseif ($planIndex == 1) {
																					$planClass = 'elementor-column elementor-col-33 elementor-top-column elementor-element elementor-element-4df7b91';
																				} else {
																					$planClass = 'elementor-column elementor-col-33 elementor-top-column elementor-element elementor-element-bf377ad';
																				}

																				// Calculate NGN price
																				$ngnMonthly = getNairaPrice($plan['price_monthly'], $exchange_rate);
																		?>
																				<div class="<?php echo $planClass; ?>" data-id="<?php echo $plan['id']; ?>" data-element_type="column">
																					<div class="elementor-widget-wrap elementor-element-populated">
																						<div class="elementor-element elementor-element-c654064 start wdt-pricing-table-style-a elementor-widget-mobile_extra__width-initial elementor-widget elementor-widget-wdt-pricing-table">
																							<div class="elementor-widget-container">
																								<div class="wdt-pricing-table-holder wdt-template-custom-template" id="wdt-pricing-table-<?php echo $plan['id']; ?>">
																									<div class="wdt-pricing-table-header">
																										<div class="wdt-content-title">
																											<h5><a href="#"><?php echo htmlspecialchars($plan['name']); ?></a></h5>
																										</div>
																										<div class="wdt-content-description"><?php echo htmlspecialchars($plan['description']); ?></div>
																									</div>
																									<div class="wdt-pricing-table-pricing">
																										<div class="wdt-pricing-table-pricing-sale">
																											<span class="wdt-pricing-table-pricing-prefix">$</span>
																											<span class="wdt-pricing-table-pricing-sale-price"><?php echo formatPrice($plan['price_monthly']); ?></span>
																											<span class="wdt-pricing-table-pricing-suffix beside">/month</span>
																											<div style="font-size: 14px; color: #666; margin-top: 5px;">
																												(<?php echo $ngnMonthly; ?>/month)
																											</div>
																										</div>
																									</div>
																									<div class="wdt-pricing-table-footer">
																										<div class="wdt-content-button">
																											<a href="/platform/school-signup?plan=<?php echo $plan['id']; ?>&billing=monthly" class="wdt-button">Select This Plan</a>
																										</div>
																									</div>
																									<div class="wdt-pricing-table-features">
																										<ul class="wdt-pricing-table-features-list">
																											<?php foreach ($features as $feature): ?>
																												<li class="wdt-pricing-table-feature-included">
																													<div class="wdt-pricing-table-features-list-inner">
																														<span class="wdt-pricing-table-features-list-icon">
																															<div class="wdt-content-icon-wrapper">
																																<div class="wdt-content-icon">
																																	<span><i aria-hidden="true" class="fas fa-check-circle"></i></span>
																																</div>
																															</div>
																														</span>
																														<span class="wdt-pricing-table-features-list-text"><?php echo htmlspecialchars($feature); ?></span>
																													</div>
																												</li>
																											<?php endforeach; ?>
																											<li class="wdt-pricing-table-feature-included">
																												<div class="wdt-pricing-table-features-list-inner">
																													<span class="wdt-pricing-table-features-list-icon">
																														<div class="wdt-content-icon-wrapper">
																															<div class="wdt-content-icon">
																																<span><i aria-hidden="true" class="fas fa-check-circle"></i></span>
																															</div>
																														</div>
																													</span>
																													<span class="wdt-pricing-table-features-list-text">
																														<?php echo $plan['campus_limit'] ?? 1; ?> Campus<?php echo ($plan['campus_limit'] > 1 || $plan['campus_limit'] == 0) ? 'es' : ''; ?> Included
																													</span>
																												</div>
																											</li>
																										</ul>
																									</div>
																								</div>
																							</div>
																						</div>
																					</div>
																				</div>
																		<?php
																				$planIndex++;
																			endif;
																		endforeach;
																		?>
																	</div>
																</div>
															</div>

															<!-- Annual Plans Tab -->
															<div id="wdt-tabs-1" class="wdt-tabs-content">
																<div class="elementor-element elementor-section elementor-top-section elementor-element elementor-element-e628abc elementor-section-full_width elementor-section-height-default elementor-section-height-default">
																	<div class="elementor-container elementor-column-gap-no">
																		<?php
																		$planIndex = 0;
																		foreach ($plans as $plan):
																			$features = parseFeatures($plan['features'], $plan);
																			if ($plan['price_yearly'] > 0):
																				$planClass = '';
																				if ($planIndex == 0) {
																					$planClass = 'elementor-column elementor-col-33 elementor-top-column elementor-element elementor-element-0540ccf';
																				} elseif ($planIndex == 1) {
																					$planClass = 'elementor-column elementor-col-33 elementor-top-column elementor-element elementor-element-4df7b91';
																				} else {
																					$planClass = 'elementor-column elementor-col-33 elementor-top-column elementor-element elementor-element-bf377ad';
																				}

																				// Calculate savings and NGN price
																				$savings = calculateYearlySavings($plan['price_monthly'], $plan['price_yearly']);
																				$monthlyEquivalent = $plan['price_yearly'] / 12;
																				$ngnYearly = getNairaPrice($plan['price_yearly'], $exchange_rate);
																		?>
																				<div class="<?php echo $planClass; ?>" data-id="<?php echo $plan['id']; ?>-yearly" data-element_type="column">
																					<div class="elementor-widget-wrap elementor-element-populated">
																						<div class="elementor-element elementor-element-c654064 start wdt-pricing-table-style-a elementor-widget-mobile_extra__width-initial elementor-widget elementor-widget-wdt-pricing-table">
																							<div class="elementor-widget-container">
																								<div class="wdt-pricing-table-holder wdt-template-custom-template" id="wdt-pricing-table-<?php echo $plan['id']; ?>-yearly">
																									<div class="wdt-pricing-table-header">
																										<div class="wdt-content-title">
																											<h5><a href="#"><?php echo htmlspecialchars($plan['name']); ?> (Annual)</a></h5>
																											<?php if ($savings > 0): ?>
																												<div class="wdt-badge" style="background: #034737; color: white; padding: 2px 10px; border-radius: 12px; font-size: 12px; margin-top: 5px;">
																													Save <?php echo $savings; ?>%
																												</div>
																											<?php endif; ?>
																										</div>
																										<div class="wdt-content-description"><?php echo htmlspecialchars($plan['description']); ?></div>
																									</div>
																									<div class="wdt-pricing-table-pricing">
																										<div class="wdt-pricing-table-pricing-sale">
																											<span class="wdt-pricing-table-pricing-prefix">$</span>
																											<span class="wdt-pricing-table-pricing-sale-price"><?php echo formatPrice($plan['price_yearly']); ?></span>
																											<span class="wdt-pricing-table-pricing-suffix beside">/year</span>
																											<div style="font-size: 14px; color: #666; margin-top: 5px;">
																												(≈ $<?php echo number_format($monthlyEquivalent, 2); ?>/month)
																												<br>
																												(<?php echo $ngnYearly; ?>/year)
																											</div>
																										</div>
																									</div>
																									<div class="wdt-pricing-table-footer">
																										<div class="wdt-content-button">
																											<a href="/platform/school-signup?plan=<?php echo $plan['id']; ?>&billing=yearly" class="wdt-button">Select This Plan</a>
																										</div>
																									</div>
																									<div class="wdt-pricing-table-features">
																										<ul class="wdt-pricing-table-features-list">
																											<?php foreach ($features as $feature): ?>
																												<li class="wdt-pricing-table-feature-included">
																													<div class="wdt-pricing-table-features-list-inner">
																														<span class="wdt-pricing-table-features-list-icon">
																															<div class="wdt-content-icon-wrapper">
																																<div class="wdt-content-icon">
																																	<span><i aria-hidden="true" class="fas fa-check-circle"></i></span>
																																</div>
																															</div>
																														</span>
																														<span class="wdt-pricing-table-features-list-text"><?php echo htmlspecialchars($feature); ?></span>
																													</div>
																												</li>
																											<?php endforeach; ?>
																											<li class="wdt-pricing-table-feature-included">
																												<div class="wdt-pricing-table-features-list-inner">
																													<span class="wdt-pricing-table-features-list-icon">
																														<div class="wdt-content-icon-wrapper">
																															<div class="wdt-content-icon">
																																<span><i aria-hidden="true" class="fas fa-check-circle"></i></span>
																															</div>
																														</div>
																													</span>
																													<span class="wdt-pricing-table-features-list-text">
																														<?php echo $plan['campus_limit'] ?? 1; ?> Campus<?php echo ($plan['campus_limit'] > 1 || $plan['campus_limit'] == 0) ? 'es' : ''; ?> Included
																													</span>
																												</div>
																											</li>
																										</ul>
																									</div>
																								</div>
																							</div>
																						</div>
																					</div>
																				</div>
																		<?php
																				$planIndex++;
																			endif;
																		endforeach;
																		?>
																	</div>
																</div>
															</div>
														</div>
													</div>
												</div>
											</div>
										<?php endif; ?>

										<div class="elementor-element elementor-element-9a4f821 elementor-invisible elementor-widget elementor-widget-text-editor" data-id="9a4f821" data-element_type="widget" data-settings="{&quot;_animation&quot;:&quot;fadeInRight&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}" data-widget_type="text-editor.default">
											<div class="elementor-widget-container">
												<p>*All plans include data isolation, security, and customization for multi-tenant educational institutions.</p>
											</div>
										</div>
										<div class="elementor-element elementor-element-455d03e elementor-invisible elementor-widget elementor-widget-text-editor" data-id="455d03e" data-element_type="widget" data-settings="{&quot;_animation&quot;:&quot;fadeInLeft&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}" data-widget_type="text-editor.default">
											<div class="elementor-widget-container">
												<p>All plans will automatically renew until canceled. Cancel anytime with no long-term commitment.</p>
											</div>
										</div>
										<div class="elementor-element elementor-element-239495f wdt-text-link-1 elementor-invisible elementor-widget elementor-widget-text-editor" data-id="239495f" data-element_type="widget" data-settings="{&quot;_animation&quot;:&quot;fadeInRight&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}" data-widget_type="text-editor.default">
											<div class="elementor-widget-container">
												<p>Need a custom plan for multiple campuses or districts? <a href="/contact">Contact our education specialists</a> for enterprise pricing. Have questions? Contact <a href="mailto:support@academixsuite.com">support@academixsuite.com</a></p>
											</div>
										</div>
										<?php if (empty($plans)): ?>
											<div class="elementor-alert elementor-alert-info">
												<div class="elementor-alert-title">No Pricing Plans Available</div>
												<div class="elementor-alert-description">Please contact our support team for pricing information.</div>
											</div>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</section>

						<section class="elementor-section elementor-top-section elementor-element elementor-element-a3cdad2 elementor-section-boxed elementor-section-height-default elementor-section-height-default" data-id="a3cdad2" data-element_type="section" data-settings="{&quot;wdt_bg_image&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_laptop&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_tablet_extra&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_tablet&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_mobile_extra&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_mobile&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_position&quot;:&quot;center center&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}">
	<div class="elementor-container elementor-column-gap-no">
		<div class="elementor-column elementor-col-100 elementor-top-column elementor-element elementor-element-74e7912" data-id="74e7912" data-element_type="column">
			<div class="elementor-widget-wrap elementor-element-populated">
				<section class="elementor-section elementor-inner-section elementor-element elementor-element-d7cc552 elementor-section-full_width wdt-dark-bg elementor-section-height-default elementor-section-height-default" data-id="d7cc552" data-element_type="section" data-settings="{&quot;wdt_bg_image&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_laptop&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_tablet_extra&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_tablet&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_mobile_extra&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_image_mobile&quot;:{&quot;url&quot;:&quot;&quot;,&quot;id&quot;:&quot;&quot;,&quot;size&quot;:&quot;&quot;},&quot;wdt_bg_position&quot;:&quot;center center&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}">
			<div class="elementor-background-overlay"></div>
			<div class="elementor-container elementor-column-gap-no">
				<div class="elementor-column elementor-col-50 elementor-inner-column elementor-element elementor-element-1dafb08" data-id="1dafb08" data-element_type="column">
					<div class="elementor-widget-wrap elementor-element-populated">
						<div class="elementor-element elementor-element-6aa3574 start elementor-invisible elementor-widget elementor-widget-wdt-heading" data-id="6aa3574" data-element_type="widget" data-settings="{&quot;_animation&quot;:&quot;fadeInLeft&quot;,&quot;title_vertical_align&quot;:&quot;center&quot;,&quot;subtitle_vertical_align&quot;:&quot;center&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}" data-widget_type="wdt-heading.default">
							<div class="elementor-widget-container">
								<div class="wdt-heading-holder" id="wdt-heading-6aa3574">
									<div class="wdt-heading-subtitle-wrapper wdt-heading-align-center"><span class="wdt-heading-subtitle">GET EXPERT GUIDANCE</span></div>
									<h2 class="wdt-heading-title-wrapper wdt-heading-align-center wdt-heading-deco-wrapper"><span class="wdt-heading-title">Transform Your Institution with Our Education Technology Experts</span></h2>
									<div class="wdt-heading-content-wrapper">Our dedicated education specialists are here to help you implement the perfect school management solution. Whether you're a small private school, a multi-campus institution, or an educational network, we provide personalized consultation to ensure successful implementation and adoption.</div>
								</div>
							</div>
						</div>
						
					</div>
				</div>
				<div class="elementor-column elementor-col-50 elementor-inner-column elementor-element elementor-element-6b878ab" data-id="6b878ab" data-element_type="column" data-settings="{&quot;background_background&quot;:&quot;classic&quot;}">
					<div class="elementor-widget-wrap elementor-element-populated">
						<div class="elementor-element elementor-element-4e8984f elementor-invisible elementor-widget elementor-widget-shortcode" data-id="4e8984f" data-element_type="widget" data-settings="{&quot;_animation&quot;:&quot;fadeInRight&quot;,&quot;wdt_animation_effect&quot;:&quot;none&quot;}" data-widget_type="shortcode.default">
							<div class="elementor-widget-container">
								<div class="elementor-shortcode">
									<div class="wpcf7 no-js" id="wpcf7-f21796-p22163-o2" lang="en-US" dir="ltr" data-wpcf7-id="21796">
										<div class="screen-reader-response">
											<p role="status" aria-live="polite" aria-atomic="true"></p>
											<ul></ul>
										</div>
										<form action="/academixsuite/pricing-plan/#wpcf7-f21796-p22163-o2" method="post" class="wpcf7-form init demo" aria-label="Contact form" novalidate="novalidate" data-status="init">
											<fieldset class="hidden-fields-container"><input type="hidden" name="_wpcf7" value="21796"><input type="hidden" name="_wpcf7_version" value="6.1.1"><input type="hidden" name="_wpcf7_locale" value="en_US"><input type="hidden" name="_wpcf7_unit_tag" value="wpcf7-f21796-p22163-o2"><input type="hidden" name="_wpcf7_container_post" value="22163"><input type="hidden" name="_wpcf7_posted_data_hash" value="">
											</fieldset>
											<div class="wdt-form-style-b">
												<div class="col-1">
													<p><span class="wpcf7-form-control-wrap" data-name="first-name"><input size="40" maxlength="400" class="wpcf7-form-control wpcf7-text wpcf7-validates-as-required" aria-required="true" aria-invalid="false" placeholder="First Name*" value="" type="text" name="first-name"></span><span class="wpcf7-form-control-wrap" data-name="last-name"><input size="40" maxlength="400" class="wpcf7-form-control wpcf7-text wpcf7-validates-as-required" aria-required="true" aria-invalid="false" placeholder="Last Name*" value="" type="text" name="last-name"></span>
													</p>
												</div>
												<div class="col-2">
													<p><span class="wpcf7-form-control-wrap" data-name="your-email"><input size="40" maxlength="400" class="wpcf7-form-control wpcf7-email wpcf7-validates-as-required wpcf7-text wpcf7-validates-as-email" aria-required="true" aria-invalid="false" placeholder="Work Email*" value="" type="email" name="your-email"></span><span class="wpcf7-form-control-wrap" data-name="your-number"><input size="40" maxlength="400" class="wpcf7-form-control wpcf7-text wpcf7-validates-as-required" aria-required="true" aria-invalid="false" placeholder="Phone Number*" value="" type="text" name="your-number"></span>
													</p>
												</div>
												<div class="selector">
													<p><select>
															<option value="" disabled="" selected=""> What type of institution do you represent? </option>
															<option value="k12">K-12 School</option>
															<option value="college">College/University</option>
															<option value="training">Training Center</option>
															<option value="network">Educational Network</option>
															<option value="other">Other Educational Institution</option>
														</select>
													</p>
												</div>
												<div class="col-3">
													<p><span class="wpcf7-form-control-wrap" data-name="your-message"><textarea cols="40" rows="10" maxlength="2000" class="wpcf7-form-control wpcf7-textarea" aria-invalid="false" placeholder="Tell us about your institution and what challenges you're facing with school management" name="your-message"></textarea></span>
													</p>
												</div>
												<div class="submit-btn">
													<p><input class="wpcf7-form-control wpcf7-submit has-spinner" type="submit" value="Request Consultation">
													</p>
												</div>
												<div class="checkbox">
													<p><span class="wpcf7-form-control-wrap" data-name="our-lession"><span class="wpcf7-form-control wpcf7-checkbox"><span class="wpcf7-list-item first last"><label><input type="checkbox" name="our-lession[]" value="I agree to receive communications about AcademixSuite products and services"><span class="wpcf7-list-item-label">I agree to receive communications about AcademixSuite products and services</span></label></span></span></span>
													</p>
												</div>
											</div>
											<div class="wpcf7-response-output" aria-hidden="true"></div>
										</form>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>
	</div>
</div>
</div>
</section>
					</div>
				</div><!-- ** Container End ** -->
			</div><!-- **Main - End ** -->


			<!-- **Footer** -->
			<?php
			include_once('../pagefolder/footer.php');
			?><!-- **Footer - End** -->
		</div><!-- **Inner Wrapper - End** -->

	</div><!-- **Wrapper - End** -->

	<script type="speculationrules">
		{"prefetch":[{"source":"document","where":{"and":[{"href_matches":"\/lms\/*"},{"not":{"href_matches":["\/lms\/wp-*.php","\/lms\/wp-admin\/*","\/lms\/wp-content\/uploads\/sites\/12\/*","\/lms\/wp-content\/*","\/lms\/wp-content\/plugins\/*","\/lms\/wp-content\/themes\/lizza-lms\/*","\/lms\/*\\?(.+)"]}},{"not":{"selector_matches":"a[rel~=\"nofollow\"]"}},{"not":{"selector_matches":".no-prefetch, .no-prefetch a"}}]},"eagerness":"conservative"}]}
</script>
	<script type='text/javascript'>
		const lazyloadRunObserver = () => {
			const lazyloadBackgrounds = document.querySelectorAll(`.e-con.e-parent:not(.e-lazyloaded)`);
			const lazyloadBackgroundObserver = new IntersectionObserver((entries) => {
				entries.forEach((entry) => {
					if (entry.isIntersecting) {
						let lazyloadBackground = entry.target;
						if (lazyloadBackground) {
							lazyloadBackground.classList.add('e-lazyloaded');
						}
						lazyloadBackgroundObserver.unobserve(entry.target);
					}
				});
			}, {
				rootMargin: '200px 0px 200px 0px'
			});
			lazyloadBackgrounds.forEach((lazyloadBackground) => {
				lazyloadBackgroundObserver.observe(lazyloadBackground);
			});
		};
		const events = [
			'DOMContentLoaded',
			'elementor/lazyload/observe',
		];
		events.forEach((event) => {
			document.addEventListener(event, lazyloadRunObserver);
		});
	</script>
	<script type='text/javascript'>
		(function() {
			var c = document.body.className;
			c = c.replace(/woocommerce-no-js/, 'woocommerce-js');
			document.body.className = c;
		})();
	</script>
	<link rel='stylesheet' id='wc-blocks-style-css' href='../wp-content/plugins/woocommerce/assets/client/blocks/wc-blocks.css?ver=wc-9.1.4' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-22685-css' href='../wp-content/uploads/sites/12/elementor/css/post-22685.css?ver=1729595947' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-21678-css' href='../wp-content/uploads/sites/12/elementor/css/post-21678.css?ver=1729595948' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-21648-css' href='../wp-content/uploads/sites/12/elementor/css/post-21648.css?ver=1729595948' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-elementor-icons-css' href='../wp-content/uploads/sites/12/elementor/css/custom-widget-icon-list.min.css?ver=6.8.3' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-1090-css' href='../wp-content/uploads/sites/12/elementor/css/post-1090.css?ver=1729595948' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-logo-css' href='../wp-content/plugins/lizza-lms-plus/modules/menu/elementor/widgets/assets/css/logo.css?ver=1.0.2' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-icons-shared-0-css' href='../wp-content/plugins/elementor/assets/lib/font-awesome/css/fontawesome.min.css?ver=5.15.3' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-icons-fa-regular-css' href='../wp-content/plugins/elementor/assets/lib/font-awesome/css/regular.min.css?ver=5.15.3' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-icons-fa-solid-css' href='../wp-content/plugins/elementor/assets/lib/font-awesome/css/solid.min.css?ver=5.15.3' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-icons-fa-brands-css' href='../wp-content/plugins/elementor/assets/lib/font-awesome/css/brands.min.css?ver=5.15.3' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-header-icons-css' href='../wp-content/plugins/lizza-lms-plus/modules/menu/elementor/widgets/assets/css/header-icons.css?ver=1.0.2' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-header-carticons-css' href='../wp-content/plugins/lizza-lms-plus/modules/menu/elementor/widgets/assets/css/header-carticon.css?ver=1.0.2' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-button-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/button/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-1175-css' href='../wp-content/uploads/sites/12/elementor/css/post-1175.css?ver=1729595948' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-heading-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/heading/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='jquery.magnific-popup-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/popup-box/assets/css/jquery.magnific-popup.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-popup-box-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/popup-box/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-21726-css' href='../wp-content/uploads/sites/12/elementor/css/post-21726.css?ver=1729595949' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-pricing-table-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/pricing-table/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-21736-css' href='../wp-content/uploads/sites/12/elementor/css/post-21736.css?ver=1729595949' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-repeater-content-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/common-controls/repeater-contents/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-tabs-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/tabs/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-column-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/common-controls/layout/assets/css/column.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-counter-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/counter/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<style id='wdt-counter-inline-css' type='text/css'>
		@media only screen and (min-width: 481px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 1540px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 1280px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 1024px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 767px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 480px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 100%;
			}
		}


		@media only screen and (min-width: 481px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 1540px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 1280px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 1024px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 767px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 50%;
			}
		}

		@media only screen and (max-width: 480px) {
			#wdt-counter-d6579d4 .wdt-column {
				width: 100%;
			}
		}
	</style>
	<link rel='stylesheet' id='jquery-swiper-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/common-controls/layout/assets/css/swiper.min.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-carousel-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/common-controls/layout/assets/css/carousel.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-icon-box-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/icon-box/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-58-css' href='../wp-content/uploads/sites/12/elementor/css/post-58.css?ver=1729595952' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-21828-css' href='../wp-content/uploads/sites/12/elementor/css/post-21828.css?ver=1729595952' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-accordion-and-toggle-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/accordion-and-toggle/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-21829-css' href='../wp-content/uploads/sites/12/elementor/css/post-21829.css?ver=1729595952' type='text/css' media='all'>
	<link rel='stylesheet' id='elementor-post-21830-css' href='../wp-content/uploads/sites/12/elementor/css/post-21830.css?ver=1729595952' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-mailchimp-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/mailchimp/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-elementor-sections-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/core/sections/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-elementor-widgets-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/core/widgets/assets/css/style.css?ver=1.0.0' type='text/css' media='all'>
	<link rel='stylesheet' id='wdt-e-animations-css' href='../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/assets/css/animations.min.css?ver=1.0.0' type='text/css' media='all'>
	<script type="text/javascript" src="../wp-includes/js/dist/hooks.min.js?ver=4d63a3d491d11ffd8ac6" id="wp-hooks-js"></script>
	<script type="text/javascript" src="../wp-includes/js/dist/i18n.min.js?ver=5e580eb46a90c2b997e6" id="wp-i18n-js"></script>
	<script type="text/javascript" id="wp-i18n-js-after">
		/* <![CDATA[ */
		wp.i18n.setLocaleData({
			'text direction\u0004ltr': ['ltr']
		});
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/contact-form-7/includes/swv/js/index.js?ver=6.1.1" id="swv-js"></script>
	<script type="text/javascript" id="contact-form-7-js-before">
		/* <![CDATA[ */
		var wpcf7 = {
			"api": {
				"root": "https:\/\/lizza.wpengine.com\/lms\/wp-json\/",
				"namespace": "contact-form-7\/v1"
			},
			"cached": 1
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/contact-form-7/includes/js/index.js?ver=6.1.1" id="contact-form-7-js"></script>
	<script type="text/javascript" id="wdt-elementor-addon-core-js-extra">
		/* <![CDATA[ */
		var wdtElementorAddonGlobals = {
			"ajaxUrl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/assets/js/core.js?ver=1.0.0" id="wdt-elementor-addon-core-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/woocommerce/assets/js/sourcebuster/sourcebuster.min.js?ver=9.1.4" id="sourcebuster-js-js"></script>
	<script type="text/javascript" id="wc-order-attribution-js-extra">
		/* <![CDATA[ */
		var wc_order_attribution = {
			"params": {
				"lifetime": 1.0e-5,
				"session": 30,
				"base64": false,
				"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php",
				"prefix": "wc_order_attribution_",
				"allowTracking": true
			},
			"fields": {
				"source_type": "current.typ",
				"referrer": "current_add.rf",
				"utm_campaign": "current.cmp",
				"utm_source": "current.src",
				"utm_medium": "current.mdm",
				"utm_content": "current.cnt",
				"utm_id": "current.id",
				"utm_term": "current.trm",
				"utm_source_platform": "current.plt",
				"utm_creative_format": "current.fmt",
				"utm_marketing_tactic": "current.tct",
				"session_entry": "current_add.ep",
				"session_start_time": "current_add.fd",
				"session_pages": "session.pgs",
				"session_count": "udata.vst",
				"user_agent": "udata.uag"
			}
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.min.js?ver=9.1.4" id="wc-order-attribution-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-pro/modules/woocommerce/listings/elementor/widgets/products/assets/js/swiper.min.js?ver=6.8.3" id="jquery-swiper-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/assets/js/isotope.pkgd.min.js?ver=6.8.3" id="isotope-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/assets/js/matchHeight.js?ver=6.8.3" id="matchheight-js"></script>
	<script type="text/javascript" id="wdt-common-js-extra">
		/* <![CDATA[ */
		var wdtcommonobject = {
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php",
			"noResult": "No Results Found!"
		};
		var wdtcommonobject = {
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php",
			"noResult": "No Results Found!"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/assets/js/common.js?ver=6.8.3" id="wdt-common-js"></script>
	<script type="text/javascript" id="wdt-frontend-js-extra">
		/* <![CDATA[ */
		var wdtfrontendobject = {
			"pluginFolderPath": "https:\/\/lizza.wpengine.com\/lms\/wp-content\/plugins\/",
			"pluginPath": "https:\/\/lizza.wpengine.com\/lms\/wp-content\/plugins\/lizza-lms-wedesigntech-portfolio\/",
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php",
			"purchased": "<p>Purchased<\/p>",
			"somethingWentWrong": "<p>Something Went Wrong<\/p>",
			"outputDivAlert": "Please make sure you have added output shortcode.",
			"printerTitle": "Portfolio Printer",
			"pleaseLogin": "Please login",
			"noMorePosts": "No more posts to load!",
			"elementorPreviewMode": "",
			"primaryColor": "#1e306e",
			"secondaryColor": "#2fa5fb",
			"tertiaryColor": "#d2edf8"
		};
		var wdtfrontendobject = {
			"pluginFolderPath": "https:\/\/lizza.wpengine.com\/lms\/wp-content\/plugins\/",
			"pluginPath": "https:\/\/lizza.wpengine.com\/lms\/wp-content\/plugins\/lizza-lms-wedesigntech-portfolio\/",
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php",
			"purchased": "<p>Purchased<\/p>",
			"somethingWentWrong": "<p>Something Went Wrong<\/p>",
			"outputDivAlert": "Please make sure you have added output shortcode.",
			"printerTitle": "Portfolio Printer",
			"pleaseLogin": "Please login",
			"noMorePosts": "No more posts to load!",
			"elementorPreviewMode": "",
			"primaryColor": "#1e306e",
			"secondaryColor": "#2fa5fb",
			"tertiaryColor": "#d2edf8"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/assets/js/frontend.js?ver=6.8.3" id="wdt-frontend-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/modules/social-share/assets/frontend.js?ver=6.8.3" id="wdt-social-share-frontend-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/ui/core.min.js?ver=1.13.3" id="jquery-ui-core-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/ui/mouse.min.js?ver=1.13.3" id="jquery-ui-mouse-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/ui/slider.min.js?ver=1.13.3" id="jquery-ui-slider-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/assets/js/chosen.jquery.min.js?ver=6.8.3" id="chosen-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/ui/datepicker.min.js?ver=1.13.3" id="jquery-ui-datepicker-js"></script>
	<script type="text/javascript" id="jquery-ui-datepicker-js-after">
		/* <![CDATA[ */
		jQuery(function(jQuery) {
			jQuery.datepicker.setDefaults({
				"closeText": "Close",
				"currentText": "Today",
				"monthNames": ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
				"monthNamesShort": ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
				"nextText": "Next",
				"prevText": "Previous",
				"dayNames": ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"],
				"dayNamesShort": ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
				"dayNamesMin": ["S", "M", "T", "W", "T", "F", "S"],
				"dateFormat": "MM d, yy",
				"firstDay": 1,
				"isRTL": false
			});
		});
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/modules/search/assets/frontend.js?ver=6.8.3" id="wdt-search-frontend-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/modules/media-images/assets/frontend.js?ver=6.8.3" id="wdt-media-images-frontend-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/modules/comments/assets/common.js?ver=6.8.3" id="wdt-comments-common-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/assets/js/single-page.js?ver=6.8.3" id="wdt-modules-singlepage-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/modules/comments/assets/frontend.js?ver=6.8.3" id="wdt-comments-frontend-js"></script>
	<script type="text/javascript" src="../wp-content/themes/lizza-lms/assets/lib/select2/select2.full.js?ver=6.8.3" id="jquery-select2-js"></script>
	<script type="text/javascript" id="post-infinite-js-extra">
		/* <![CDATA[ */
		var lizza_lms_urls = {
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-plus/modules/blog/assets/js/post-infinite.js?ver=1.0.2" id="post-infinite-js"></script>
	<script type="text/javascript" id="post-loadmore-js-extra">
		/* <![CDATA[ */
		var lizza_lms_urls = {
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-plus/modules/blog/assets/js/post-loadmore.js?ver=1.0.2" id="post-loadmore-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-plus/modules/menu/assets/js/mega-menu.js?ver=1.0.2" id="dtplugin-mega-menu-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-pro/modules/auth/assets/js/script.js?ver=1.0.0" id="lizza-pro-auth-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-pro/modules/post/assets/js/comment-form.js?ver=1.0.0" id="comment-form-js"></script>
	<script type="text/javascript" src="../wp-content/themes/lizza-lms/modules/blog/assets/js/isotope.pkgd.js?ver=6.8.3" id="isotope-pkgd-js"></script>
	<script type="text/javascript" src="../wp-content/themes/lizza-lms/modules/blog/assets/js/jquery.bxslider.js?ver=6.8.3" id="jquery-bxslider-js"></script>
	<script type="text/javascript" src="../wp-content/themes/lizza-lms/modules/blog/assets/js/jquery.fitvids.js?ver=6.8.3" id="jquery-fitvids-js"></script>
	<script type="text/javascript" src="../wp-content/themes/lizza-lms/modules/blog/assets/js/jquery.debouncedresize.js?ver=6.8.3" id="jquery-debouncedresize-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-portfolio/assets/js/jquery.magnific-popup.min.js?ver=6.8.3" id="jquery-magnific-popup-js"></script>
	<script type="text/javascript" src="../wp-content/themes/lizza-lms/assets/js/custom.js?ver=6.8.3" id="lizza-jqcustom-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-pro/modules/woocommerce/single/modules/custom-template/elementor/assets/js/jquery.nicescroll.js?ver=6.8.3" id="jquery-nicescroll-js"></script>
	<script type="text/javascript" id="lizza-woo-cart-notification-js-after">
		/* <![CDATA[ */
		jQuery.noConflict();

		jQuery(document).ready(function($) {
			"use strict";

			// After adding product to cart
			$('body').on('added_to_cart', function(e) {

				if ($('.wdt-shop-cart-widget').hasClass('activate-sidebar-widget')) {

					$('.wdt-shop-cart-widget').addClass('wdt-shop-cart-widget-active');
					$('.wdt-shop-cart-widget-overlay').addClass('wdt-shop-cart-widget-active');

					// Nice scroll script

					var winHeight = $(window).height();
					var headerHeight = $('.wdt-shop-cart-widget-header').height();
					var footerHeight = $('.woocommerce-mini-cart-footer').height();

					var height = parseInt((winHeight - headerHeight - footerHeight), 10);

					$('.wdt-shop-cart-widget-content').height(height).niceScroll({
						cursorcolor: "#000",
						cursorwidth: "5px",
						background: "rgba(20,20,20,0.3)",
						cursorborder: "none"
					});

				}

				if ($('.wdt-shop-cart-widget').hasClass('cart-notification-widget')) {

					$('.wdt-shop-cart-widget').addClass('wdt-shop-cart-widget-active');
					$('.wdt-shop-cart-widget-overlay').addClass('wdt-shop-cart-widget-active');
					setTimeout(function() {
						$('.wdt-shop-cart-widget').removeClass('wdt-shop-cart-widget-active');
						$('.wdt-shop-cart-widget-overlay').removeClass('wdt-shop-cart-widget-active');
					}, 2400);

				}

				e.preventDefault();
			});

			$('body').on('click', '.wdt-shop-cart-widget-close-button, .wdt-shop-cart-widget-overlay', function(e) {
				$('.wdt-shop-cart-widget').removeClass('wdt-shop-cart-widget-active');
				$('.wdt-shop-cart-widget-overlay').removeClass('wdt-shop-cart-widget-active');
				e.preventDefault();
			});

		});
		/* ]]> */
	</script>
	<script type="text/javascript" id="lizza-woo-quantity-plus-minus-js-after">
		/* <![CDATA[ */
		jQuery.noConflict();

		jQuery(document).ready(function($) {
			"use strict";

			// Quatity plus & minus button

			jQuery('body').delegate('.quantity .plus, .quantity .minus', 'click', function(e) {

				var $qty = $(this).closest('.quantity').find('.qty'),
					currentVal = parseFloat($qty.val()),
					max = parseFloat($qty.attr('max')),
					min = parseFloat($qty.attr('min')),
					step = $qty.attr('step');

				if (!currentVal || currentVal === '' || currentVal === 'NaN') currentVal = 0;
				if (max === '' || max === 'NaN') max = '';
				if (min === '' || min === 'NaN') min = 0;
				if (step === 'any' || step === '' || step === undefined || parseFloat(step) === 'NaN') step = '1';

				if ($(this).is('.plus')) {
					if (max && (currentVal >= max)) {
						$qty.val(max);
					} else {
						$qty.val(currentVal + parseFloat(step));
					}
				} else {
					if (min && (currentVal <= min)) {
						$qty.val(min);
					} else if (currentVal > 0) {
						$qty.val(currentVal - parseFloat(step));
					}
				}

				$qty.trigger('change');

				e.preventDefault();

			});


		});
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-plus/modules/site-loader/assets/js/site-loader.js?ver=1.0.2" id="site-loader-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/ui/sortable.min.js?ver=1.13.3" id="jquery-ui-sortable-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.donutchart.js?ver=6.8.3" id="donutchart-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.knob.js?ver=6.8.3" id="dtlms-knob-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.knob.custom.js?ver=6.8.3" id="dtlms-knob-custom-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.print.js?ver=6.8.3" id="dtlms-print-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.nicescroll.min.js?ver=6.8.3" id="nicescroll-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.tabs.min.js?ver=6.8.3" id="dtlms-tabs-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.inview.js?ver=6.8.3" id="inview-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/swiper.min.js?ver=6.8.3" id="swiper-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.sticky.js?ver=6.8.3" id="sticky-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.downCount.js?ver=6.8.3" id="downcount-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/isotope.pkgd.min.js?ver=6.8.3" id="isotope-3.0.5-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.scrolltabs.js?ver=6.8.3" id="scrolltab-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.mousewheel.js?ver=6.8.3" id="mousewheel-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/login-logout.js?ver=6.8.3" id="dtlms-login-logout-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/jquery.toggle.click.js?ver=6.8.3" id="dtlms-toggle-click-js"></script>
	<script type="text/javascript" id="dtlms-common-js-extra">
		/* <![CDATA[ */
		var lmscommonobject = {
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php",
			"noResult": "No Results Found!",
			"elementorPreviewMode": ""
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/common.js?ver=6.8.3" id="dtlms-common-js"></script>
	<script type="text/javascript" id="dtlms-frontend-js-extra">
		/* <![CDATA[ */
		var lmsfrontendobject = {
			"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php",
			"noGraph": "No enough data to generate graph!",
			"onRefreshCurriculum": "Would you like to abort this quiz session, which will mark this session as completed ?.",
			"locationAlert1": "To get GPS location please fill address.",
			"locationAlert2": "Please add latitude and longitude",
			"submitCourse": "You can submit course only when you have completed all items in course.",
			"submitClass": "You can submit class only when you have submitted all courses.",
			"confirmRegistration": "Please confirm your registration to this class!",
			"closedRegistration": "Regsitration Closed",
			"primarColor": "rgb(124,255,119)",
			"elementorPreviewMode": ""
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/assets/js/frontend.js?ver=6.8.3" id="dtlms-frontend-js"></script>
	<script type="text/javascript" id="dtlms-quiz-frontend-js-extra">
		/* <![CDATA[ */
		var lmsquizfrontendobject = {
			"quizTimerForegroundColor": "rgb(20,69,47)",
			"quizTimerBackgroundColor": "rgb(124,255,119)",
			"quizTimeout": "Timeout!",
			"onRefresh": "Refreshing this quiz page will mark this session as completed."
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/modules/quiz/assets/frontend.js?ver=6.8.3" id="dtlms-quiz-frontend-js"></script>
	<script type="text/javascript" src="https://maps.googleapis.com/maps/api/js?key&ver=6.8.3" id="dtlms-google-map-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/modules/class/assets/common.js?ver=6.8.3" id="dtlms-class-common-js"></script>
	<script type="text/javascript" id="dtlms-class-frontend-js-extra">
		/* <![CDATA[ */
		var lmsclassfrontendobject = {
			"registrationSuccess": "You have successfully registered with our class!"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/modules/class/assets/frontend.js?ver=6.8.3" id="dtlms-class-frontend-js"></script>
	<script type="text/javascript" id="dtlms-certificate-common-js-extra">
		/* <![CDATA[ */
		var lmscertificatecommonobject = {
			"pluginPath": "https:\/\/lizza.wpengine.com\/lms\/wp-content\/plugins\/lizza-wedesigntech-lms-addon\/modules\/certificate\/",
			"printerTitle": "Certificate Printer"
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/modules/certificate/assets/common.js?ver=6.8.3" id="dtlms-certificate-common-js"></script>
	<script type="text/javascript" id="dtlms-assignment-frontend-js-extra">
		/* <![CDATA[ */
		var lmsassignmentobject = {
			"assignmentNotification": "Please make sure required fields are filled."
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-wedesigntech-lms-addon/modules/assignment/assets/frontend.js?ver=6.8.3" id="dtlms-assignment-frontend-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-plus/modules/menu/elementor/widgets/assets/js/header-icons.js?ver=1.0.2" id="wdt-header-icons-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/heading/assets/js/script.js?ver=6.8.3" id="wdt-heading-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/popup-box/assets/js/jquery.cookie.min.js?ver=6.8.3" id="jquery.cookie-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/popup-box/assets/js/jquery.magnific-popup.min.js?ver=6.8.3" id="jquery.magnific-popup-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/popup-box/assets/js/script.js?ver=6.8.3" id="wdt-popup-box-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/ui/tabs.min.js?ver=1.13.3" id="jquery-ui-tabs-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/tabs/assets/js/jquery.scrolltabs.min.js?ver=6.8.3" id="jquery.scrolltabs-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/tabs/assets/js/script.js?ver=6.8.3" id="wdt-tabs-js"></script>
	<script type="text/javascript" src="https://lizza.wpengine.com/lms/wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/common-controls/layout/assets/js/column.js?ver=6.8.3" id="wdt-column-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/counter/assets/js/jquery.countTo.js?ver=6.8.3" id="jquery-countTo-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/counter/assets/js/script.js?ver=6.8.3" id="wdt-counter-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/common-controls/layout/assets/js/carousel.js?ver=6.8.3" id="wdt-carousel-js"></script>
	<script type="text/javascript" src="../wp-includes/js/jquery/ui/accordion.min.js?ver=1.13.3" id="jquery-ui-accordion-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/accordion-and-toggle/assets/js/script.js?ver=6.8.3" id="wdt-accordion-and-toggle-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/widgets/mailchimp/assets/js/script.js?ver=6.8.3" id="wdt-mailchimp-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/elementor/assets/js/webpack.runtime.min.js?ver=3.23.3" id="elementor-webpack-runtime-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/elementor/assets/js/frontend-modules.min.js?ver=3.23.3" id="elementor-frontend-modules-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/elementor/assets/lib/waypoints/waypoints.min.js?ver=4.0.2" id="elementor-waypoints-js"></script>
	<script type="text/javascript" id="elementor-frontend-js-before">
		/* <![CDATA[ */
		var elementorFrontendConfig = {
			"environmentMode": {
				"edit": false,
				"wpPreview": false,
				"isScriptDebug": false
			},
			"i18n": {
				"shareOnFacebook": "Share on Facebook",
				"shareOnTwitter": "Share on Twitter",
				"pinIt": "Pin it",
				"download": "Download",
				"downloadImage": "Download image",
				"fullscreen": "Fullscreen",
				"zoom": "Zoom",
				"share": "Share",
				"playVideo": "Play Video",
				"previous": "Previous",
				"next": "Next",
				"close": "Close",
				"a11yCarouselWrapperAriaLabel": "Carousel | Horizontal scrolling: Arrow Left & Right",
				"a11yCarouselPrevSlideMessage": "Previous slide",
				"a11yCarouselNextSlideMessage": "Next slide",
				"a11yCarouselFirstSlideMessage": "This is the first slide",
				"a11yCarouselLastSlideMessage": "This is the last slide",
				"a11yCarouselPaginationBulletMessage": "Go to slide"
			},
			"is_rtl": false,
			"breakpoints": {
				"xs": 0,
				"sm": 480,
				"md": 481,
				"lg": 1025,
				"xl": 1440,
				"xxl": 1600
			},
			"responsive": {
				"breakpoints": {
					"mobile": {
						"label": "Mobile Portrait",
						"value": 480,
						"default_value": 767,
						"direction": "max",
						"is_enabled": true
					},
					"mobile_extra": {
						"label": "Mobile Landscape",
						"value": 767,
						"default_value": 880,
						"direction": "max",
						"is_enabled": true
					},
					"tablet": {
						"label": "Tablet Portrait",
						"value": 1024,
						"default_value": 1024,
						"direction": "max",
						"is_enabled": true
					},
					"tablet_extra": {
						"label": "Tablet Landscape",
						"value": 1280,
						"default_value": 1200,
						"direction": "max",
						"is_enabled": true
					},
					"laptop": {
						"label": "Laptop",
						"value": 1540,
						"default_value": 1366,
						"direction": "max",
						"is_enabled": true
					},
					"widescreen": {
						"label": "Widescreen",
						"value": 2400,
						"default_value": 2400,
						"direction": "min",
						"is_enabled": false
					}
				}
			},
			"version": "3.23.3",
			"is_static": false,
			"experimentalFeatures": {
				"e_optimized_css_loading": true,
				"additional_custom_breakpoints": true,
				"container_grid": true,
				"e_swiper_latest": true,
				"e_nested_atomic_repeaters": true,
				"e_onboarding": true,
				"home_screen": true,
				"ai-layout": true,
				"landing-pages": true,
				"e_lazyload": true
			},
			"urls": {
				"assets": "https:\/\/lizza.wpengine.com\/lms\/wp-content\/plugins\/elementor\/assets\/",
				"ajaxurl": "https:\/\/lizza.wpengine.com\/lms\/wp-admin\/admin-ajax.php"
			},
			"nonces": {
				"floatingButtonsClickTracking": "94d20dc8d7"
			},
			"swiperClass": "swiper",
			"settings": {
				"page": [],
				"editorPreferences": []
			},
			"kit": {
				"active_breakpoints": ["viewport_mobile", "viewport_mobile_extra", "viewport_tablet", "viewport_tablet_extra", "viewport_laptop"],
				"viewport_mobile": 480,
				"viewport_mobile_extra": 767,
				"viewport_tablet_extra": 1280,
				"viewport_laptop": 1540,
				"global_image_lightbox": "yes",
				"lightbox_enable_counter": "yes",
				"lightbox_enable_fullscreen": "yes",
				"lightbox_enable_zoom": "yes",
				"lightbox_enable_share": "yes",
				"lightbox_title_src": "title",
				"lightbox_description_src": "description"
			},
			"post": {
				"id": 22163,
				"title": "Pricing%20Plan%20%E2%80%93%20WP%20%E2%80%93%20Lizza%20Site",
				"excerpt": "",
				"featuredImage": false
			}
		};
		/* ]]> */
	</script>
	<script type="text/javascript" src="../wp-content/plugins/elementor/assets/js/frontend.min.js?ver=3.23.3" id="elementor-frontend-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/core/sections/assets/js/script.js?ver=1.0.0" id="wdt-elementor-sections-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/assets/js/parallax-scroll.min.js?ver=1.0.0" id="wdt-parallax-scroll-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/assets/js/parallax.min.js?ver=1.0.0" id="wdt-parallax-js"></script>
	<script type="text/javascript" src="../wp-content/plugins/lizza-lms-wedesigntech-elementor-addon/inc/core/widgets/assets/js/script.js?ver=1.0.0" id="wdt-elementor-widgets-js"></script>
	<script type='text/javascript'>
		document.tidioChatCode = "nxrqsr9kbqcc2jeymwva0ru2upazaf3l";
		(function() {
			function asyncLoad() {
				var tidioScript = document.createElement("script");
				tidioScript.type = "text/javascript";
				tidioScript.async = true;
				tidioScript.src = "//code.tidio.co/nxrqsr9kbqcc2jeymwva0ru2upazaf3l.js";
				document.body.appendChild(tidioScript);
			}
			if (window.attachEvent) {
				window.attachEvent("onload", asyncLoad);
			} else {
				window.addEventListener("load", asyncLoad, false);
			}
		})();
	</script>
</body>

</html>