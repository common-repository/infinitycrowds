<div class='wrap infcrwds-root'>
    <h2>Infinitycrowd</h2>
    <h4>Status - <?php echo $ic_status ?></h4>
    <?php if($settings_enabled) { ?>
    <div class="paper dashboard-link">
        <div>
            <h4>
                To create and manage offers, customize the look and feel of our widgets and view analytics enter your dasboard.
            </h4>
        </div>
        <div>
            <a class='inf-button' href="<?php echo $dashboard_link ?>" target="_blank">Dashboard</a>
        </div>
    </div>
    <?php } ?>
    <div class='buttons-container'>
        <form method="post" id="infcrwds_logout_form">
            <input type='submit' name='infcrwds_logout' value='Logout' class='button-secondary' id='infcrwds_logout'/>
        </form>
    </div>
    <?php if($settings_enabled) { ?>
    <form  method='post' id='infcrwds_settings_form'>
        <table class='form-table'>
             <?php wp_nonce_field('infcrwds_settings_form'); ?>
            <fieldset>
                <tr valign='top'><th scope='row'>Enable debug mode</th>
                    <td><input type='checkbox' name='logs_enabled' <?php echo ($logs_enabled ? 'checked' : '') ?> /></td>
                    <td><p class='description'>Enabling logs will output all plugin actions into <i>infcrwds.log</i></p></td>
                </tr>
                <tr valign='top'>
                    <th scope='row'>Enabled Payment Gateways</th>
                    <td>
                        <div>
                            <select name="gateways[]" multiple>
                                <?php foreach($supported_gateways as $gateway=>$name) { ?>
                                     <option <?php
                                         if($gateways !== null && in_array($gateway, $gateways)) { ?>
                                            <?php echo 'selected="selected"' ?> 
                                        <?php }  ?>  value="<?php echo $gateway?>"><?php echo $name?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </td>
                </tr>
            </fieldset>
            </table></br>			  		
            <div class='buttons-container'>
        
            <input type='submit' name='infcrwds_settings' value='Update' class='button-primary' id='save_infcrwds_settings'/>
    </form>
    <?php } ?>
</div>