<?php
get_header();
?>
<main>
    <section class="hero-bg hero-full flex items-center relative overflow-hidden">
        <div class="container mx-auto px-4 md:px-6 relative z-10">
            <div class="max-w-3xl">
                <span class="bg-lumina-orange text-white text-xs md:text-sm font-bold px-3 py-1 rounded-sm mb-4 inline-block tracking-wider">地域密着・最短即日対応</span>
                <h1 class="text-3xl md:text-5xl lg:text-6xl font-bold text-white leading-tight mb-6 text-shadow">
                    地域の「困った」に、<br class="hidden md:block">
                    技術とスピードで応える。
                </h1>
                <p class="text-gray-200 text-lg md:text-xl mb-8 leading-relaxed max-w-2xl text-shadow">
                    空調、電気、給排水。施設設備の主治医として、<br>
                    あなたのビジネス環境を支え続けます。
                </p>
                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="#contact" class="bg-lumina-orange hover:bg-lumina-orangeHover text-white text-center text-lg font-bold py-4 px-8 rounded-sm shadow-lg transition-all transform hover:-translate-y-1">
                        まずは無料で相談・見積り
                        <i class="fa-solid fa-chevron-right ml-2 text-sm"></i>
                    </a>
                    <a href="#services" class="bg-white/10 hover:bg-white/20 backdrop-blur-sm border border-white text-white text-center text-lg font-bold py-4 px-8 rounded-sm transition-all">
                        サービス一覧を見る
                    </a>
                </div>
            </div>
        </div>
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce text-white/70 hidden md:block">
            <span class="text-xs tracking-widest uppercase mb-2 block text-center">Scroll</span>
            <i class="fa-solid fa-chevron-down text-xl"></i>
        </div>
    </section>

    <section id="strengths" class="py-16 md:py-24 bg-white relative z-20">
        <div class="container mx-auto px-4 md:px-6">
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white p-8 rounded-sm shadow-xl border-t-4 border-lumina-navy transform md:-translate-y-16">
                    <div class="w-16 h-16 bg-lumina-bg rounded-full flex items-center justify-center mb-6 mx-auto text-lumina-navy">
                        <i class="fa-solid fa-stopwatch text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-center text-lumina-navy mb-4">スピード対応</h3>
                    <p class="text-gray-600 text-sm leading-relaxed text-center">
                        お電話から最短即日で現地調査へ伺います。地域密着だからこそできるフットワークで、設備の停止時間を最小限に抑えます。
                    </p>
                </div>
                <div class="bg-white p-8 rounded-sm shadow-xl border-t-4 border-lumina-navy transform md:-translate-y-16">
                    <div class="w-16 h-16 bg-lumina-bg rounded-full flex items-center justify-center mb-6 mx-auto text-lumina-navy">
                        <i class="fa-solid fa-file-invoice-dollar text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-center text-lumina-navy mb-4">明朗会計・詳細報告</h3>
                    <p class="text-gray-600 text-sm leading-relaxed text-center">
                        作業前には必ずお見積りを提示。完了後には写真付きの報告書を提出し、何が原因でどう直したのかを丁寧にご説明します。
                    </p>
                </div>
                <div class="bg-white p-8 rounded-sm shadow-xl border-t-4 border-lumina-navy transform md:-translate-y-16">
                    <div class="w-16 h-16 bg-lumina-bg rounded-full flex items-center justify-center mb-6 mx-auto text-lumina-navy">
                        <i class="fa-solid fa-screwdriver-wrench text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-center text-lumina-navy mb-4">ワンストップ施工</h3>
                    <p class="text-gray-600 text-sm leading-relaxed text-center">
                        空調のついでに電気工事も、といったご要望もお任せください。複数の業者を手配する手間を省き、窓口を一本化できます。
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section id="services" class="py-20 bg-lumina-bg">
        <div class="container mx-auto px-4 md:px-6">
            <div class="text-center mb-16">
                <span class="text-lumina-orange font-bold tracking-widest uppercase text-sm">Our Services</span>
                <h2 class="text-3xl md:text-4xl font-bold text-lumina-navy mt-2 mb-4">建物設備のすべてを、ワンストップで</h2>
                <div class="w-16 h-1 bg-lumina-orange mx-auto rounded-full"></div>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="group bg-white rounded-sm overflow-hidden shadow-md hover:shadow-xl transition-all duration-300">
                    <div class="p-6">
                        <div class="w-12 h-12 bg-lumina-bg rounded-full flex items-center justify-center mb-4 text-lumina-navy">
                            <i class="fa-solid fa-snowflake text-xl"></i>
                        </div>
                        <h3 class="font-bold text-lg text-lumina-navy mb-2">空調設備</h3>
                        <p class="text-sm text-gray-600 leading-relaxed">業務用エアコンの点検・洗浄・修理・更新に対応します。</p>
                    </div>
                </div>
                <div class="group bg-white rounded-sm overflow-hidden shadow-md hover:shadow-xl transition-all duration-300">
                    <div class="p-6">
                        <div class="w-12 h-12 bg-lumina-bg rounded-full flex items-center justify-center mb-4 text-lumina-navy">
                            <i class="fa-solid fa-faucet-drip text-xl"></i>
                        </div>
                        <h3 class="font-bold text-lg text-lumina-navy mb-2">給排水設備</h3>
                        <p class="text-sm text-gray-600 leading-relaxed">漏水や詰まりなど、トラブルの原因を迅速に特定します。</p>
                    </div>
                </div>
                <div class="group bg-white rounded-sm overflow-hidden shadow-md hover:shadow-xl transition-all duration-300">
                    <div class="p-6">
                        <div class="w-12 h-12 bg-lumina-bg rounded-full flex items-center justify-center mb-4 text-lumina-navy">
                            <i class="fa-solid fa-bolt-lightning text-xl"></i>
                        </div>
                        <h3 class="font-bold text-lg text-lumina-navy mb-2">電気設備</h3>
                        <p class="text-sm text-gray-600 leading-relaxed">分電盤・照明・配線の点検から更新提案まで対応します。</p>
                    </div>
                </div>
                <div class="group bg-white rounded-sm overflow-hidden shadow-md hover:shadow-xl transition-all duration-300">
                    <div class="p-6">
                        <div class="w-12 h-12 bg-lumina-bg rounded-full flex items-center justify-center mb-4 text-lumina-navy">
                            <i class="fa-solid fa-fire-extinguisher text-xl"></i>
                        </div>
                        <h3 class="font-bold text-lg text-lumina-navy mb-2">防災設備</h3>
                        <p class="text-sm text-gray-600 leading-relaxed">消防設備の法定点検や報告書作成を代行します。</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="works" class="py-20 bg-white">
        <div class="container mx-auto px-4 md:px-6">
            <div class="grid lg:grid-cols-2 gap-12">
                <div>
                    <span class="text-lumina-orange font-bold tracking-widest uppercase text-sm">Works</span>
                    <h2 class="text-3xl md:text-4xl font-bold text-lumina-navy mt-2 mb-6">導入事例</h2>
                    <p class="text-gray-600 leading-relaxed mb-8">
                        さまざまな業種のお客様からご依頼をいただいています。状況に合わせた最適な提案で、業務への影響を最小限に抑えます。
                    </p>

                    <div class="space-y-6">
                        <div class="border border-gray-100 rounded-sm p-6 shadow-sm">
                            <h3 class="text-lg font-bold text-lumina-navy mb-2">飲食店A社</h3>
                            <p class="text-sm text-gray-600 leading-relaxed">厨房の空調復旧により営業停止を回避。</p>
                        </div>
                        <div class="border border-gray-100 rounded-sm p-6 shadow-sm">
                            <h3 class="text-lg font-bold text-lumina-navy mb-2">工場B社</h3>
                            <p class="text-sm text-gray-600 leading-relaxed">受水槽の清掃で衛生リスクを低減。</p>
                        </div>
                        <div class="border border-gray-100 rounded-sm p-6 shadow-sm">
                            <h3 class="text-lg font-bold text-lumina-navy mb-2">商業施設C社</h3>
                            <p class="text-sm text-gray-600 leading-relaxed">照明更新で消費電力を15%削減。</p>
                        </div>
                    </div>
                </div>

                <div>
                    <span class="text-lumina-orange font-bold tracking-widest uppercase text-sm">News</span>
                    <h2 class="text-3xl md:text-4xl font-bold text-lumina-navy mt-2 mb-6">お知らせ</h2>
                    <div class="bg-white rounded-sm shadow-sm border border-gray-100 divide-y divide-gray-100">
                        <a href="#" class="block p-6 hover:bg-gray-50 transition-colors group">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 mb-2">
                                <time class="text-sm text-gray-400 font-roboto">2026.01.15</time>
                                <span class="text-xs bg-lumina-navy text-white px-2 py-0.5 rounded-sm w-fit">お知らせ</span>
                            </div>
                            <h3 class="text-gray-700 font-bold group-hover:text-lumina-orange transition-colors">冬季の凍結・漏水対策チェックリスト公開</h3>
                        </a>
                        <a href="#" class="block p-6 hover:bg-gray-50 transition-colors group">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 mb-2">
                                <time class="text-sm text-gray-400 font-roboto">2026.01.10</time>
                                <span class="text-xs bg-gray-500 text-white px-2 py-0.5 rounded-sm w-fit">キャンペーン</span>
                            </div>
                            <h3 class="text-gray-700 font-bold group-hover:text-lumina-orange transition-colors">防災設備の定期点検キャンペーン実施</h3>
                        </a>
                        <a href="#" class="block p-6 hover:bg-gray-50 transition-colors group">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 mb-2">
                                <time class="text-sm text-gray-400 font-roboto">2026.01.05</time>
                                <span class="text-xs bg-lumina-navy text-white px-2 py-0.5 rounded-sm w-fit">施工事例</span>
                            </div>
                            <h3 class="text-gray-700 font-bold group-hover:text-lumina-orange transition-colors">施工事例を追加しました</h3>
                        </a>
                    </div>
                    <div class="mt-6 text-right">
                        <a href="#" class="text-sm font-bold text-lumina-navy hover:underline">過去のお知らせ一覧 <i class="fa-solid fa-angle-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="company" class="py-20 bg-lumina-bg">
        <div class="container mx-auto px-4 md:px-6">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <span class="text-lumina-orange font-bold tracking-widest uppercase text-sm">Company</span>
                    <h2 class="text-3xl md:text-4xl font-bold text-lumina-navy mt-2 mb-6">地域に根ざした設備パートナー</h2>
                    <p class="text-gray-600 leading-relaxed mb-6">
                        株式会社ルミナリンクは、空調・電気・給排水・防災設備の工事とメンテナンスをワンストップで提供しています。現場の状況を丁寧に把握し、無理のない改善と運用を重視しています。
                    </p>
                    <ul class="text-sm text-gray-600 space-y-3">
                        <li><strong>所在地:</strong> 東京都足立区梅田2-8-10 ルミナビル1F</li>
                        <li><strong>設立:</strong> 2016年4月</li>
                        <li><strong>事業内容:</strong> 設備工事、保守点検、緊急対応、設備更新提案</li>
                        <li><strong>対応エリア:</strong> 足立区・北区・葛飾区を中心に都内近郊</li>
                    </ul>
                </div>
                <div class="bg-white p-8 shadow-lg rounded-sm">
                    <h3 class="text-xl font-bold text-lumina-navy mb-4">3つの安心</h3>
                    <div class="space-y-4 text-sm text-gray-600">
                        <p>・現地調査を丁寧に行い、原因を可視化します。</p>
                        <p>・見積りを明確にし、不要な工事は行いません。</p>
                        <p>・施工後の写真付き報告で再発防止を支援します。</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="posts" class="py-20 bg-white">
        <div class="container mx-auto px-4 md:px-6">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6 mb-10">
                <div>
                    <span class="text-lumina-orange font-bold tracking-widest uppercase text-sm">Posts</span>
                    <h2 class="text-3xl md:text-4xl font-bold text-lumina-navy mt-2">最新の投稿</h2>
                </div>
                <?php
                $posts_page_id  = (int) get_option( 'page_for_posts' );
                $posts_page_url = $posts_page_id ? get_permalink( $posts_page_id ) : '';
                ?>
                <?php if ( $posts_page_url ) : ?>
                    <a href="<?php echo esc_url( $posts_page_url ); ?>" class="text-sm font-bold text-lumina-navy hover:text-lumina-orange transition-colors">
                        すべての投稿を見る <i class="fa-solid fa-angle-right"></i>
                    </a>
                <?php endif; ?>
            </div>

            <?php
            $latest_posts = new WP_Query(
                array(
                    'post_type'           => 'post',
                    'post_status'         => 'publish',
                    'posts_per_page'      => 6,
                    'ignore_sticky_posts' => true,
                )
            );
            ?>

            <?php if ( $latest_posts->have_posts() ) : ?>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php while ( $latest_posts->have_posts() ) : ?>
                        <?php $latest_posts->the_post(); ?>
                        <?php
                        $post_content_raw   = get_post_field( 'post_content', get_the_ID() );
                        $post_content_plain = wp_strip_all_tags( strip_shortcodes( $post_content_raw ) );
                        if ( function_exists( 'mb_substr' ) ) {
                            $post_content_excerpt = mb_substr( $post_content_plain, 0, 200 );
                            if ( mb_strlen( $post_content_plain ) > 200 ) {
                                $post_content_excerpt .= '…';
                            }
                        } else {
                            $post_content_excerpt = substr( $post_content_plain, 0, 200 );
                            if ( strlen( $post_content_plain ) > 200 ) {
                                $post_content_excerpt .= '…';
                            }
                        }
                        $post_thumbnail_html = get_the_post_thumbnail(
                            get_the_ID(),
                            'medium_large',
                            array(
                                'class'   => 'w-full h-auto block transition-transform duration-300 group-hover:scale-105',
                                'loading' => 'lazy',
                            )
                        );
                        $post_thumbnail_fallback_url = '';
                        if ( ! $post_thumbnail_html ) {
                            $post_thumbnail_fallback_url = (string) get_post_meta( get_the_ID(), '_blog_poster_og_image', true );
                        }
                        ?>
                        <article class="group bg-white border border-gray-100 rounded-sm shadow-sm p-6 hover:shadow-md transition-shadow">
                            <?php if ( $post_thumbnail_html ) : ?>
                                <a href="<?php the_permalink(); ?>" class="block mb-5 overflow-hidden rounded-sm bg-gray-100" style="aspect-ratio: 16 / 9;">
                                    <?php echo $post_thumbnail_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </a>
                            <?php elseif ( '' !== $post_thumbnail_fallback_url ) : ?>
                                <a href="<?php the_permalink(); ?>" class="block mb-5 overflow-hidden rounded-sm bg-gray-100" style="aspect-ratio: 16 / 9;">
                                    <img src="<?php echo esc_url( $post_thumbnail_fallback_url ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" class="w-full h-auto block transition-transform duration-300 group-hover:scale-105" loading="lazy">
                                </a>
                            <?php else : ?>
                                <a href="<?php the_permalink(); ?>" class="block mb-5 overflow-hidden rounded-sm bg-gray-100" style="aspect-ratio: 16 / 9;">
                                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-slate-100 to-slate-200 text-slate-500">
                                        <span class="text-xs tracking-widest uppercase">No Image</span>
                                    </div>
                                </a>
                            <?php endif; ?>
                            <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>" class="text-xs text-gray-500 font-roboto">
                                <?php echo esc_html( get_the_date( 'Y.m.d' ) ); ?>
                            </time>
                            <h3 class="mt-3 text-xl font-bold text-lumina-navy leading-snug">
                                <a href="<?php the_permalink(); ?>" class="hover:text-lumina-orange transition-colors">
                                    <?php the_title(); ?>
                                </a>
                            </h3>
                            <p class="mt-4 text-sm text-gray-600 leading-relaxed">
                                <?php echo esc_html( $post_content_excerpt ); ?>
                            </p>
                        </article>
                    <?php endwhile; ?>
                </div>
                <?php wp_reset_postdata(); ?>
            <?php else : ?>
                <div class="bg-lumina-bg border border-gray-100 rounded-sm p-8 text-center">
                    <p class="text-gray-600">表示できる投稿がまだありません。</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section id="contact" class="py-20 bg-lumina-navy relative overflow-hidden">
        <div class="absolute inset-0 opacity-10" style="background-image: repeating-linear-gradient(45deg, #fff 0, #fff 1px, transparent 0, transparent 50%); background-size: 10px 10px;"></div>

        <div class="container mx-auto px-4 md:px-6 relative z-10 text-center">
            <h2 class="text-3xl md:text-4xl font-bold text-white mb-6">お見積り・現地調査は無料です</h2>
            <p class="text-gray-300 mb-10 text-lg">
                設備の不調、新規導入、コスト削減のご相談など、<br class="hidden sm:block">
                まずはお気軽にお問い合わせください。<br>
                <span class="text-sm mt-2 block opacity-80">※しつこい営業は一切いたしません。相見積もりも歓迎です。</span>
            </p>

            <div class="flex flex-col md:flex-row justify-center items-stretch gap-6 max-w-4xl mx-auto">
                <div class="bg-white p-8 rounded-sm shadow-lg flex-1">
                    <p class="text-sm font-bold text-lumina-navy mb-2 flex items-center justify-center">
                        <span class="w-2 h-2 bg-lumina-orange rounded-full mr-2 animate-pulse"></span>
                        お急ぎの方はお電話ください
                    </p>
                    <a href="tel:0312345678" class="block text-4xl font-roboto font-bold text-lumina-navy hover:text-lumina-orange transition-colors mb-2">
                        03-1234-5678
                    </a>
                    <p class="text-xs text-gray-500">受付時間：平日 9:00〜18:00 / 土曜 9:00〜15:00</p>
                </div>

                <a href="#" class="bg-lumina-orange hover:bg-lumina-orangeHover text-white p-8 rounded-sm shadow-lg flex-1 flex flex-col justify-center items-center group transition-colors">
                    <i class="fa-regular fa-envelope text-4xl mb-3 group-hover:scale-110 transition-transform"></i>
                    <span class="text-xl font-bold">Webからお問い合わせ</span>
                    <span class="text-sm opacity-90 mt-1">24時間受付中 / 最短当日返信</span>
                </a>
            </div>
        </div>
    </section>
</main>
<?php
get_footer();
