<?php 
if(isset($_POST) && isset($_POST['transaction_id'])) {
    include_once('authentication.php');
    $transaction_id = stripslashes(trim($_POST['transaction_id']));
    $paypal_data = new paypal_data();
    echo $paypal_data->refund_payment($transaction_id);     
} else {
    exit('Direct access denied');
}