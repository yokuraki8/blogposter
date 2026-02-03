<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php bloginfo( 'name' ); ?><?php wp_title( '|', true, 'left' ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'font-sans text-gray-800 bg-white' ); ?>>
<?php wp_body_open(); ?>

<header class="fixed w-full top-0 z-50 bg-white/95 backdrop-blur-sm shadow-md transition-all duration-300">
    <div class="container mx-auto px-4 md:px-6">
        <div class="flex justify-between items-center h-20">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="flex items-center gap-2 group">
                <div class="w-10 h-10 bg-lumina-navy text-white flex items-center justify-center rounded-sm">
                    <i class="fa-solid fa-link text-xl"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-xl font-bold text-lumina-navy leading-none tracking-tight">LUMINA LINK</span>
                    <span class="text-xs text-lumina-gray font-medium tracking-widest">株式会社ルミナリンク</span>
                </div>
            </a>

            <nav class="hidden lg:flex items-center gap-8">
                <a href="<?php echo esc_url( home_url( '/#services' ) ); ?>" class="text-sm font-bold text-gray-600 hover:text-lumina-navy transition-colors">サービス案内</a>
                <a href="<?php echo esc_url( home_url( '/#strengths' ) ); ?>" class="text-sm font-bold text-gray-600 hover:text-lumina-navy transition-colors">選ばれる理由</a>
                <a href="<?php echo esc_url( home_url( '/#works' ) ); ?>" class="text-sm font-bold text-gray-600 hover:text-lumina-navy transition-colors">導入事例</a>
                <a href="<?php echo esc_url( home_url( '/#company' ) ); ?>" class="text-sm font-bold text-gray-600 hover:text-lumina-navy transition-colors">企業情報</a>
            </nav>

            <div class="hidden lg:flex items-center gap-4">
                <div class="text-right">
                    <p class="text-xs text-gray-500 font-medium">お急ぎの方はこちら</p>
                    <p class="text-xl font-roboto font-bold text-lumina-navy leading-none"><i class="fa-solid fa-phone text-sm mr-1"></i>03-1234-5678</p>
                </div>
                <a href="<?php echo esc_url( home_url( '/#contact' ) ); ?>" class="bg-lumina-orange hover:bg-lumina-orangeHover text-white text-sm font-bold py-3 px-6 rounded-sm shadow-md transition-all transform hover:-translate-y-0.5">
                    <i class="fa-regular fa-envelope mr-2"></i>無料見積り
                </a>
            </div>

            <button id="mobile-menu-btn" class="lg:hidden text-lumina-navy focus:outline-none" type="button">
                <i class="fa-solid fa-bars text-2xl"></i>
            </button>
        </div>
    </div>

    <div id="mobile-menu" class="hidden lg:hidden bg-white border-t border-gray-100 absolute w-full shadow-lg">
        <div class="container mx-auto px-4 py-4 flex flex-col gap-4">
            <a href="<?php echo esc_url( home_url( '/#services' ) ); ?>" class="text-gray-700 font-bold py-2 border-b border-gray-100">サービス案内</a>
            <a href="<?php echo esc_url( home_url( '/#strengths' ) ); ?>" class="text-gray-700 font-bold py-2 border-b border-gray-100">選ばれる理由</a>
            <a href="<?php echo esc_url( home_url( '/#works' ) ); ?>" class="text-gray-700 font-bold py-2 border-b border-gray-100">導入事例</a>
            <a href="<?php echo esc_url( home_url( '/#company' ) ); ?>" class="text-gray-700 font-bold py-2 border-b border-gray-100">企業情報</a>
            <div class="mt-4 flex flex-col gap-3">
                <a href="tel:0312345678" class="bg-lumina-navy text-white text-center py-3 rounded-sm font-bold">
                    <i class="fa-solid fa-phone mr-2"></i>電話で相談する
                </a>
                <a href="<?php echo esc_url( home_url( '/#contact' ) ); ?>" class="bg-lumina-orange text-white text-center py-3 rounded-sm font-bold">
                    <i class="fa-regular fa-envelope mr-2"></i>メールで見積依頼
                </a>
            </div>
        </div>
    </div>
</header>
