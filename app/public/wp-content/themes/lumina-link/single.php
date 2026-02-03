<?php
get_header();
?>
<main class="pt-24 pb-16">
    <div class="container mx-auto px-4 md:px-6">
        <?php
        while ( have_posts() ) :
            the_post();
            ?>
            <article class="bg-white shadow-sm border border-gray-100 rounded-sm p-8">
                <h1 class="post-title text-lumina-navy mb-6"><?php the_title(); ?></h1>
                <div class="content-body">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
</main>
<?php
get_footer();
