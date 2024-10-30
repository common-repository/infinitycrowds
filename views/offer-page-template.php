<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body class="cleanpage" style="background-color: #fff">
<?php
InfcrwdsPlugin()->offer_page->enqueue_page_scripts();
InfcrwdsPlugin()->offer_page->write_page_configs();
?>
<div id="infcrwds-offer-root">
  <div style="width:100%;height:400px;display:flex;justify-content:center;align-items:center;flex-direction:column;">
  <div style="width: 100px;height: 75px;">
    <img src="<?php echo plugins_url( 'assets/imgs/loader.svg', dirname( __FILE__ ) ) ?>" />
  </div>
  <div>Loading Offer...</div>
</div>
<?php wp_footer(); ?>
</body>
</html>
