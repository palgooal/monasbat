<?php get_header(); ?>

<?php get_template_part('page-parts/general-title-section'); ?>

<?php get_template_part('page-parts/general-before-wrap'); ?>

<?php if ( category_description() ) : ?>
    <div class="archive-description"><?php echo category_description(); ?></div>
<?php endif; ?>

<?php
// Get current category info
$category = get_category( get_query_var('cat') );

// Get knowledge base sections
$kb_sections = get_categories( array( 'hide_empty' => 0 ) );

// Filter kb_sections to only include direct children of current category
$kb_sections = array_filter($kb_sections, function($section) use ($category) {
    return $section->parent == $category->term_id;
});

// If current category has sub-categories, show the kb wall
if ( $kb_sections ): ?>
    <div class="row kb-archive">
        <?php foreach ( $kb_sections as $section ): ?>
            <div class="col-xs-12 col-sm-6 col-md-4">
                <div class="kb_section">
                    <h4 class="kb-section-name">
                        <i class="icon icon-folder-open-empty"></i>
                        <a href="<?php echo esc_url( get_term_link( $section ) ); ?>" title="<?php echo esc_attr( $section->name ); ?>"><?php echo esc_html( $section->name ); ?></a>
                    </h4>
                    <ul class="kb-wall-list">
                        <?php
                        // Display sub sections
                        foreach ( $kb_sections as $sub_section ) {
                            if ( $section->term_id == $sub_section->parent ) {
                                ?>
                                <li class="kb-section-name">
                                    <i class="icon icon-folder-empty"></i>
                                    <a href="<?php echo esc_url( get_term_link( $sub_section ) ); ?>" rel="bookmark" title="<?php echo esc_attr( $sub_section->name ); ?>"><?php echo esc_html( $sub_section->name ); ?></a>
                                </li>
                                <?php
                            }
                        }

                        // Fetch posts in the root section
                        $kb_args = array(
                            'post_type' => 'post',
                            'posts_per_page' => apply_filters( 'kleo_kb_category_posts_per_page', 5 ),
                            'category__in' => $section->term_id,
                        );
                        $the_query = new WP_Query( $kb_args );

                        // Display posts in the root section
                        if ( $the_query->have_posts() ) :
                            while ( $the_query->have_posts() ) : $the_query->the_post();
                                ?>
                                <li class="kb-article-name">
                                    <i class="icon icon-doc-text"></i>
                                    <a href="<?php echo esc_url( get_permalink( $the_query->ID ) ); ?>" rel="bookmark" title="<?php echo esc_attr( get_the_title( $the_query->ID ) ); ?>"><?php echo esc_html( get_the_title( $the_query->ID ) ); ?></a>
                                </li>
                                <?php
                            endwhile;
                            ?>
                            <li class="kb-view-more">
                                <a href="<?php echo esc_url( get_term_link( $section ) ); ?>" title="<?php echo esc_attr( $section->name ); ?>" class="btn-default">
                                    <?php echo esc_html__( '+ More from', 'kleo' ) . ' ' . esc_html( $section->name ); ?>
                                </a>
                            </li>
                            <?php
                        endif;

                        wp_reset_postdata();
                        ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
<?php else: // If current category doesn't have sub-categories, show the kb wall
    if ( have_posts() ) : ?>
        <ul class="kb-archive-listing">
            <?php while ( have_posts() ) : the_post(); ?>
                <li class="kb-article-name">
                    <h2 class="post-title">
                        <i class="icon icon-doc-text"></i>
                        <a href="<?php echo esc_url( get_permalink() ); ?>" rel="bookmark" title="<?php echo esc_attr( get_the_title() ); ?>">
                            <?php echo esc_html( get_the_title() ); ?>
                        </a>
                    </h2>
                    <?php echo kleo_excerpt( 100 ); ?>
                    <a href="<?php echo esc_url( get_permalink() ); ?>" class="btn btn-default">
                        <?php echo esc_html__( 'Read more', 'kleo' ); ?>
                    </a>
                    <br><br>
                </li>
            <?php endwhile; ?>
        </ul>
        
        <?php
        echo kleo_pagination( '', false );
        ?>
    <?php else: ?>
        <p><?php echo esc_html__( 'Sorry, there are no articles here yet.' , 'kleo' ); ?></p>
        <?php
    endif;
endif;
?>

<?php get_template_part('page-parts/general-after-wrap'); ?>

<?php get_footer();
