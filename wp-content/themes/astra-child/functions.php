<?php
// Enqueue parent theme styles
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style( 'astra-parent', get_template_directory_uri() . '/style.css' );
});

