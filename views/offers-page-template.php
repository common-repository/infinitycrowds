<?php
get_header();
?>
<!-- <body class="cleanpage" style="background-color: #fff"> -->
<?php
InfcrwdsPlugin()->offer_page->enqueue_offers_page_scripts();
InfcrwdsPlugin()->offer_page->write_offers_page_configs();
?>
<div id="infcrwds-offer-root">
  <div style="width:100%;height:400px;display:flex;justify-content:center;align-items:center;flex-direction:column;">
  <div style="width: 100px;height: 75px;">
    <img src="<?php echo plugins_url( 'assets/imgs/loader.svg', dirname( __FILE__ ) ) ?>" />
  </div>
  <div>Loading Offers...</div>
</div>
<?php get_footer(); ?>

