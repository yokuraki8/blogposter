<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
    <footer class="bg-gray-900 text-white pt-16 pb-8 border-t border-gray-800">
        <div class="container mx-auto px-4 md:px-6">
            <div class="grid md:grid-cols-4 gap-12 mb-12">
                <div class="col-span-1 md:col-span-1">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="w-8 h-8 bg-white text-lumina-navy flex items-center justify-center rounded-sm">
                            <i class="fa-solid fa-link"></i>
                        </div>
                        <span class="text-lg font-bold">LUMINA LINK</span>
                    </div>
                    <p class="text-sm text-gray-400 leading-relaxed mb-4">
                        〒123-0000<br>
                        東京都足立区梅田2-8-10<br>
                        ルミナビル 1F
                    </p>
                    <div class="flex gap-4">
                        <a href="#" class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center hover:bg-lumina-orange transition-colors"><i class="fa-brands fa-facebook-f text-sm"></i></a>
                        <a href="#" class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center hover:bg-lumina-orange transition-colors"><i class="fa-brands fa-instagram text-sm"></i></a>
                        <a href="#" class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center hover:bg-lumina-orange transition-colors"><i class="fa-brands fa-x-twitter text-sm"></i></a>
                    </div>
                </div>

                <div class="col-span-1">
                    <h4 class="text-sm font-bold text-gray-300 uppercase tracking-widest mb-6">Service</h4>
                    <ul class="text-sm text-gray-400 space-y-3">
                        <li><a href="<?php echo esc_url( home_url( '/#services' ) ); ?>" class="hover:text-lumina-orange transition-colors">空調設備メンテナンス</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/#services' ) ); ?>" class="hover:text-lumina-orange transition-colors">給排水設備メンテナンス</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/#services' ) ); ?>" class="hover:text-lumina-orange transition-colors">電気設備保守点検</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/#services' ) ); ?>" class="hover:text-lumina-orange transition-colors">防災設備点検</a></li>
                    </ul>
                </div>

                <div class="col-span-1">
                    <h4 class="text-sm font-bold text-gray-300 uppercase tracking-widest mb-6">Company</h4>
                    <ul class="text-sm text-gray-400 space-y-3">
                        <li><a href="<?php echo esc_url( home_url( '/#company' ) ); ?>" class="hover:text-lumina-orange transition-colors">会社概要</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/#works' ) ); ?>" class="hover:text-lumina-orange transition-colors">導入事例</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/#contact' ) ); ?>" class="hover:text-lumina-orange transition-colors">お問い合わせ</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/#contact' ) ); ?>" class="hover:text-lumina-orange transition-colors">採用情報</a></li>
                    </ul>
                </div>

                <div class="col-span-1">
                    <h4 class="text-sm font-bold text-gray-300 uppercase tracking-widest mb-6">Area</h4>
                    <p class="text-sm text-gray-400 leading-relaxed">
                        東京都全域、埼玉県南部、千葉県西部、神奈川県北部<br>
                        <span class="text-xs mt-2 block text-gray-500">※詳細はお問い合わせください</span>
                    </p>
                </div>
            </div>

            <div class="border-t border-gray-800 pt-8 text-center md:text-left flex flex-col md:flex-row justify-between items-center gap-4">
                <p class="text-xs text-gray-500">&copy; Lumina Link Co., Ltd. All Rights Reserved.</p>
                <p class="text-xs text-gray-600">Designed for Trust &amp; Reliability.</p>
            </div>
        </div>
    </footer>

<?php wp_footer(); ?>
<script>
    const luminaMenuBtn = document.getElementById('mobile-menu-btn');
    const luminaMenu = document.getElementById('mobile-menu');

    if (luminaMenuBtn && luminaMenu) {
        luminaMenuBtn.addEventListener('click', () => {
            luminaMenu.classList.toggle('hidden');
        });

        document.querySelectorAll('#mobile-menu a').forEach(link => {
            link.addEventListener('click', () => {
                luminaMenu.classList.add('hidden');
            });
        });
    }
</script>
</body>
</html>
