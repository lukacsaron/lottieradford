<?php
/**
 * Template Name: Home Page
 *
 * Template for displaying a page without sidebar even if a sidebar widget is published
 *
 * @package understrap
 */

get_header(); ?>
<?php $attached_images = get_post_meta($post->ID, "wpcf-gallery-item", false);
        if ($attached_images[0]=="") { ?>

        <!-- If there are no custom fields, show nothing -->

        <?php } else { ?>
    
    <div id="slideshow" class="relative">
       <?php foreach($attached_images as $attached_images) {
            echo '<div class="slideshow-item" style="background-image:url('.$attached_images.');"></div>';
            } ?>
    </div>

        <?php } ?>

<div class="wrapper" id="page-wrapper">
    
    <div  id="content" class="container">
        
	   <div id="primary" class="col-md-12 content-area">

            <main id="main" class="site-main" role="main">

                <?php while ( have_posts() ) : the_post(); ?>

                    <?php get_template_part( 'loop-templates/content', 'page' ); ?>

                    <?php
                        // If comments are open or we have at least one comment, load up the comment template
                        if ( comments_open() || get_comments_number() ) :

                            comments_template();
                        
                        endif;
                    ?>

                <?php endwhile; // end of the loop. ?>

            </main><!-- #main -->
           
	    </div><!-- #primary -->
        
    </div><!-- Container end -->
    
</div><!-- Wrapper end -->

<?php get_footer(); ?>