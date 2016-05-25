$vph = jQuery(window).height();
$isMobile = window.matchMedia("only screen and (max-width: 768px)");


function slideshow_start() {
    jQuery('#slideshow .slideshow-item:gt(0)').hide();
    setInterval(function(){
      jQuery('#slideshow :first-child').fadeTo(1500,0)
         .next('.slideshow-item').fadeTo(1500,1)
         .end().appendTo('#slideshow');}, 
      5000);
}

function ifHome() {
    if (jQuery('body').hasClass('page-template-page-home') ) {
        if ($isMobile.matches) {
            var safezone = 80;
            var width_slide = jQuery("#slideshow").width();
            var height_slide = width_slide / 1.66;
            jQuery("#slideshow").css({'min-height': height_slide + 'px'});
            jQuery("#slideshow").css({'max-height': height_slide + 'px'});
        }
        else {
           var safezone = 80; 
        }
        
        jQuery('#slideshow').css({'height': $vph - safezone + 'px'});
    }
}




// ON READY
jQuery(document).ready(function() {
    ifHome();
    slideshow_start();
});