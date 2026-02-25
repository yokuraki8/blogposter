<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function lumina_link_enqueue_assets() {
    wp_enqueue_style(
        'lumina-link-fonts',
        'https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&family=Roboto:wght@500;700&display=swap',
        array(),
        null
    );

    wp_enqueue_style(
        'lumina-link-fontawesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
        array(),
        '6.4.0'
    );

    wp_enqueue_script(
        'lumina-link-tailwind',
        'https://cdn.tailwindcss.com',
        array(),
        null,
        false
    );

    $tailwind_config = <<<JS
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    lumina: {
                        navy: '#0F2540',
                        lightNavy: '#1a3c66',
                        gray: '#A4A9AD',
                        orange: '#F08301',
                        orangeHover: '#d17200',
                        bg: '#F5F7FA'
                    }
                },
                fontFamily: {
                    sans: ['"Noto Sans JP"', 'sans-serif'],
                    roboto: ['"Roboto"', 'sans-serif']
                }
            }
        }
    };
JS;

    wp_add_inline_script( 'lumina-link-tailwind', $tailwind_config, 'before' );

    wp_enqueue_style( 'lumina-link-style', get_stylesheet_uri(), array(), '0.1.0' );
}
add_action( 'wp_enqueue_scripts', 'lumina_link_enqueue_assets' );
