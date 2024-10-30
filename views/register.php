<div class='wrap infcrwds-root'><h2>Infinitycrowds - Store Registration</h2>
    <form method='post'>
    <table class='form-table'>
        <?php wp_nonce_field('infcrwds_registration_form') ?>
        <fieldset>
            <h2 class='inf-register-title'>Register this store to Infinitycrowds.</h2></br></br>
            <tr valign='top'>
            <th scope='row'><div>Currency:</div></th>			 			  
            <td><div><input type='text' name='infcrwds_currency' /></div></td>
            </tr>
            <tr valign='top'>
            <th scope='row'><div>Language:</div></th>			 			  
            <td><div><input type='text' name='infcrwds_language' /></div></td>
            </tr>
            <tr valign='top'>
            <th scope='row'></th>
            <td><div><input type='submit' name='infcrwds_register' value='Register' class='button-primary submit-btn' /></div></td>
            </tr>			  
        </fieldset>			
        </table>
    </form>
    <div class='infcrwds-terms'>By registering I accept the <a href='https://www.infinitycrowds.com/terms-of-service' target='_blank'>Terms of Use</a> and recognize that a 'Powered by Infinitycrowds' link will appear on the bottom of my Infinitycrowds widget.</div>
    <form method='post'>
        <div class="inf-btnmsg">Already registered to Infinitycrowds?<input type='submit' name='log_in_button' value='click here' class='button-secondary not-user-btn' /></div>
    </form>
</div>