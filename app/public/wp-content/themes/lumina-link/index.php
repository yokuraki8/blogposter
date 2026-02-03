<?php
if ( is_front_page() ) {
    include __DIR__ . '/front-page.php';
    return;
}
get_header();
?>
<main class="pt-24 pb-16">
    <div class="container mx-auto px-4 md:px-6">
        <?php if ( have_posts() ) : ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <article class="bg-white shadow-sm border border-gray-100 rounded-sm p-8 mb-8">
                    <h2 class="text-2xl font-bold text-lumina-navy mb-4">
                        <a href="<?php the_permalink(); ?>" class="hover:text-lumina-orange">
                            <?php the_title(); ?>
                        </a>
                    </h2>
                    <div class="content-body">
                        <?php the_content(); ?>
                    </div>
                </article>
            <?php endwhile; ?>
        <?php else : ?>
            <p>投稿が見つかりませんでした。</p>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();
