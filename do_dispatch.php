<?php

require('dispatcher.php');

try {
    viber_check_webhooks();
}
catch (Exception $ex) {
    log_error(__FILE__, __LINE__, $ex->getMessage());
}

try {
    tg_check_updates();
}
catch (Exception $ex) {
    log_error(__FILE__, __LINE__, $ex->getMessage());
}

try {
    check_mailboxes();
}
catch (Exception $ex) {
    log_error(__FILE__, __LINE__, $ex->getMessage());
}

?>
