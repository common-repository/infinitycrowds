<?php
    $email = isset($_POST['email']) ? $_POST['email'] : '';
?>
<div class='wrap infcrwds-root'><h2>Infinitycrowd Login</h2>
    <form method='post'>
    <table class='form-table'>
        <?php wp_nonce_field('infcrwds_login_form') ?>
        <fieldset>
            <h2 class='inf-register-title'>Login to your account</h2></br></br>
            <tr valign='top'>
            <th scope='row'><div>Email address:</div></th>			 			  
            <td><div><input type='text' name='infcrwds_email' value='<?php $email ?>' /></div></td>
            </tr>
            <tr valign='top'>
            <th scope='row'><div>Password:</div></th>			 			  
            <td><div><input type='password' name='infcrwds_password' /></div></td>
            </tr>
            <tr valign='top'>
            <th scope='row'></th>
            <td><div><input type='submit' name='infcrwds_login' value='Login' class='button-primary submit-btn' /></div></td>
            </tr>			  
        </fieldset>
        </table>
    </form>
    <div class="inf-btnmsg">Don't have an account? <a href="https://platform.infinitycrowd.io/signup" target="_blank">signup here</a></div>
</div>