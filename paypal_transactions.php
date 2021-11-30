<?php 
include_once('authentication.php'); 
$start_date = date('Y-m-d', strtotime('-30 days'));
$end_date = date('Y-m-d');
$paypal_authentication_data = new paypal_data();
echo $paypal_authentication_data->get_paypal_transactions($start_date, $end_date);