<?php

require('dispatcher.php');

try {
    viber_handle_webhook( getallheaders(), file_get_contents("php://input") );
}
catch (Exception $ex) {
    log_error(__FILE__, __LINE__, $ex->getMessage());
}

?>
