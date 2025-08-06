<?php
// Enqueue parent theme styles
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('astra-parent', get_template_directory_uri() . '/style.css');
});

add_action('wp_head', 'rishumit_add_gtag_script');
function rishumit_add_gtag_script()
{
    ?>
    <!-- Global site tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-16765175858"></script>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-NWX5NEWFM4"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'AW-16765175858');
      gtag('config', 'G-NWX5NEWFM4');
    </script>
    <?php
}
