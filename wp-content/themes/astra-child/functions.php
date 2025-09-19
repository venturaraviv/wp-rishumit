<?php
// Enqueue parent theme styles
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('astra-parent', get_template_directory_uri() . '/style.css');
});

function add_conversion_tracking_to_thank_you()
{
    // Check if we're on the payment success page
    if (is_page_template('template-payment-success.php')) {
        ?>
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-NWX5NEWFM4"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());

          gtag('config', 'G-NWX5NEWFM4');
        </script>
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=AW-16765175858"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());

          gtag('config', 'AW-16765175858');
        </script>
        <!-- Event snippet for add conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/L_DhCIjd8v4ZELKQobo-'});
        </script>
        <!-- Event snippet for הרשמה conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/b9wVCNzJrf4ZELKQobo-'});
        </script>
        <!-- Event snippet for newborn conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/l5ZWCK_iiIIaELKQobo-'});
        </script>
        <!-- Event snippet for death conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/NLP6CNDSxoEaELKQobo-'});
        </script>
        <!-- Event snippet for idf conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/XO1qCLuphoEaELKQobo-'});
        </script>
        <!-- Event snippet for summary conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/nfpwCPiCiIIaELKQobo-'});
        </script>
        <!-- Event snippet for attach conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/oT9pCLKp6IIaELKQobo-'});
        </script>
        <!-- Event snippet for birth conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/6A2ICKPs5IMaELKQobo-'});
        </script>
        <!-- Event snippet for נסח conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/R3mYCNeEoLsaELKQobo-'});
        </script>
        <!-- Event snippet for אסט conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/IQfeCLiE1boaELKQobo-'});
        </script>
        <!-- Event snippet for tax conversion page -->
        <script>
          gtag('event', 'conversion', {'send_to': 'AW-16765175858/4P8YCOSQyYMaELKQobo-'});
        </script>
        <?php
    }
}
add_action('wp_head', 'add_conversion_tracking_to_thank_you');
