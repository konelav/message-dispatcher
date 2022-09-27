<?php

require('dispatcher.php');

try {
    viber_check_webhooks( true, false );
}
catch (Exception $ex) {
    log_error(__FILE__, __LINE__, $ex->getMessage());
}

?>
