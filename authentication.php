<?php
error_reporting(E_ALL);
class paypal_data {
    
    private $wpdb;
    private $access_token;
    private $validation_flag;
    private $sandbox;
    private $endpoint_fragment;
    
    function __construct()
    {   
        require 'wp-load.php'; 
        global $wpdb;
        $this->wpdb = $wpdb;
        /**
         * For sandbox Account
         * if Account is live then change
         * $this->sandbox = false
         */
        $this->sandbox = true;
        $this->endpoint_fragment = ($this->sandbox) ? 'https://api-m.sandbox.paypal.com/':'https://api-m.paypal.com/';

        $all_Headers = $this->getRequestHeaders();
        $authorizationheader = $all_Headers['Authorization'];
        if($authorizationheader=='Bearer 12345667890')
        {
            $this->validation_flag = true;
        } else {
            echo "Authentication Error";
            $this->validation_flag = false;
            die();
        }
        $this->access_token = $this->get_authentication_token();
           
    }

    function getRequestHeaders() {
        $headers = array();
        foreach($_SERVER as $key => $value) {
            if (substr($key, 0, 5) <> 'HTTP_') {
                continue;
            }
            $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$header] = $value;
        }
        return $headers;
    }

 
    /**
     * get_authentication_token function
     * validate if the token is not expired. if it is expired refresh 
     * the token, update it into database and return the updated access_token 
     * @return string $access_token
     */
    function get_authentication_token () {
        
        $access_token ='';
        $auth_data = $this->wpdb->get_results('select * from wp_ssa_paypal_auth where id=1', 'ARRAY_A');
        if($auth_data[0]['expiration_time'] > time()) {
            $token_data = json_decode($auth_data[0]['returned_data'], true);
            $access_token = $token_data['access_token']; 
        } else {
            $username = $auth_data[0]['client_id']; 
            $password = $auth_data[0]['secret_token'];
            $authentication_end_point = $this->endpoint_fragment.$auth_data[0]['end_point'];
            $payload_name = 'grant_type=client_credentials';
            $ch = curl_init($authentication_end_point);
            $authentication_array[] = 'Authorization: Basic '.base64_encode($username.":".$password); 
            $authentication_array[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $authentication_array);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_name);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            $return = curl_exec($ch);
            curl_close($ch);
            $authentication_data = json_decode($return, true);
            $expiration_time = ($authentication_data['expires_in']-60)+time();
            $data_to_update['expiration_time'] = $expiration_time;
            $data_to_update['returned_data'] = $return;
            $data_to_update['creation_time'] = time();
            $this->wpdb->update('wp_ssa_paypal_auth', $data_to_update, array('id' => 1));
            $access_token = $authentication_data['access_token'];
            $this->addLog('authentication', 'Access token refreshed with data: '.$return );
        }
        return $access_token;   
    }
    
    /**
     * fetch the transactions from paypal based on the start date and end date and store it into database
     */

    function get_paypal_transactions($start_date, $end_date) {
        //global $wpdb;
        if($this->validation_flag==false){
            $transactions_return_data['status'] = 'error';
            $transactions_return_data['message'] = 'Authentication Error';
            return json_encode($transactions_return_data);
            
        }
        $endpoint_url = $this->endpoint_fragment.'v1/reporting/transactions?';
        $date_range['start_date'] = date("Y-m-d", strtotime($start_date)).'T00:00:00-0000';
        $date_range['end_date'] = date("Y-m-d", strtotime($end_date)).'T23:59:59-0000';
        $endpoint_url = $endpoint_url . http_build_query($date_range);
        $ch = curl_init($endpoint_url);
        $authentication_array[] = 'Authorization: Bearer '.$this->access_token; 
        $authentication_array[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $authentication_array);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, 0);
        $data = curl_exec($ch);
        curl_close($ch);
        $stored_data['data'] = $data;
        $stored_data['creation_date'] = date('y-m-d H:i:s');
        $stored_data['data_type'] = 'transaction';
        $this->wpdb->insert('wp_dump_transactions', $stored_data);
        if($this->sync_transaction_info()) {
            $transactions_return_data['status'] = 'success';
            $transactions_return_data['message'] = 'Data synced successfully';
            return json_encode($transactions_return_data);
        }

    }

    /**
     * Sync the dumped transaction data with the stored transaction_data and update it into the transaction table
    */

function sync_transaction_info() {
        if($this->validation_flag==false){
            return false;
        }
        //global $wpdb;
        $transaction_table_transactions = array();
        $results = $this->wpdb->get_results( "SELECT * FROM `wp_dump_transactions` order by id desc limit 1", 'ARRAY_A');
        if($results) {
            $data = $results[0]['data'];
            $transaction_data = json_decode($data, true);
            $detailed_transaction_data = $transaction_data['transaction_details'];
            foreach ($detailed_transaction_data as $single_transaction_information) {
                $transaction_details[$single_transaction_information['transaction_info']['transaction_id']] = json_encode($single_transaction_information); 
            }
        }
        $all_paypal_transactions = array_keys($transaction_details);
        $transactions_from_db = $this->wpdb->get_results( "select transaction_id from wp_paypal_transactions", 'ARRAY_A' );
        foreach($transactions_from_db as $individual_transaction) {
            $transaction_table_transactions[] = $individual_transaction['transaction_id']; 
        }
        
        $balanced_transactions  = array_diff($all_paypal_transactions, $transaction_table_transactions);
        if(count($balanced_transactions)>0) {
            
            foreach($balanced_transactions as $transaction_id) {
                $array_to_insert['transaction_id'] = $transaction_id;
                $array_to_insert['transaction_details'] = $transaction_details[$transaction_id];
                $array_to_insert['transaction_status'] = 'eligible_for_refund';
                $array_to_insert['creation_date'] = date('Y-m-d H:i:s');
                $this->wpdb->insert('wp_paypal_transactions', $array_to_insert);
            }
            
        } 
        return true;      
    }

function get_refundable_data_by_transaction($transaction_id) {
    $result_data = array();
    $validation_data = array();
    //Validate the payment status from table
    $validation_data = $this->wpdb->get_results("select * from wp_paypal_transactions where `transaction_status`='eligible_for_refund' and `transaction_id`='".$transaction_id."'", "ARRAY_A");
    if(count($validation_data) > 0) {
        $result_data = $this->wpdb->get_results('select `purchase_key`, `gateway_transaction_id`, `currency`, `amount_paid`  from wp_ssa_payments where gateway_transaction_id="'.$transaction_id.'"', 'ARRAY_A');
    }
    return $result_data;
}
/**
 * Refund the amount based on the transaction id and invoice id
 */


function refund_payment($transaction_id) {
    if($this->validation_flag==false){
        return false;
    }

    $refund_result_data = $this->get_refundable_data_by_transaction($transaction_id);
    if(count($refund_result_data) < 1) {
        $data_to_return['status'] = 'failed';
        $data_to_return['message'] = 'Given Transaction id is not eligible for refund or unavailable in the transaction_data';
        return json_encode($data_to_return);
    }
    $currency_code = $refund_result_data[0]['currency'];
    $transaction_id = $refund_result_data[0]['gateway_transaction_id'];
    $amount = $refund_result_data[0]['amount_paid'];
    $invoice_id = $refund_result_data[0]['purchase_key'];
    $endpoint_url = $this->endpoint_fragment.'v2/payments/captures/'.$transaction_id.'/refund';
    $json_array['amount']['value'] = $amount;
    $json_array['amount']['currency_code'] = $currency_code;
    $json_array['invoice_id'] = $invoice_id;
    $json_array['note_to_payer'] = 'consultancy refund';
    $post_data = json_encode($json_array);
    $ch = curl_init($endpoint_url);
    $authentication_array[] = 'Authorization: Bearer '.$this->access_token; 
    $authentication_array[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $authentication_array);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    $data = curl_exec($ch);
   
    curl_close($ch);
    $dataarray = json_decode($data,true);
    
    if(isset($dataarray['status']) && $dataarray['status']=='COMPLETED') {
        $data_to_update['transaction_status'] = 'refunded'; 
    } else {
        $data_to_update['transaction_status'] = 'issue_in_refund'; 
    }
    $stored_data['data'] = $data;
    $stored_data['creation_date'] = date('y-m-d H:i:s');
    $stored_data['data_type'] = 'refund';
    $this->wpdb->insert('wp_dump_transactions', $stored_data);
    //$data_to_update['transaction_status'] = 'refunded';
    $data_to_update['updation_date'] = date('Y-m-d H:i:s');
    $data_to_update['returned_data'] = $data;
    $this->wpdb->update('wp_paypal_transactions', $data_to_update, array('transaction_id' => $transaction_id));
    if($data_to_update['transaction_status']=='issue_in_refund') {
        $this->addLog('transaction_refund_issue', 'Issue in transaction refunded '.$data );
        $data_to_return['status'] = 'failed';
        $data_to_return['message'] = 'Given Transaction id is not eligible for refund or unavailable in the transaction_data';
        return json_encode($data_to_return); 
    }
    $this->addLog('transaction_refunded', 'Transaction refunded successfully with '.$data );
    $data_to_return['status'] = 'success';
    $data_to_return['message'] = 'Refund completed successfully for given transaction id';
    return json_encode($data_to_return);
}

/**
 * Add Log into the database
 */


function addLog($log_type, $log_details) {
    //authentication, transaction_dump, transaction_refund
    $log_data['log_type'] = $log_type;
    $log_data['log_details'] = $log_details;
    if($this->wpdb->insert('wp_paypal_log', $log_data)) {
        return true;
    }

}

}
    
