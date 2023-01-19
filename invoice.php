<?php
require('includes/application_top.php');

class Callback {

    private $tranId, $id, $notification, $status, $amount, $signature;

    public function __construct()
    {
        $this->notification = $this->getData();
        $this->tranId = $this->notification['id'];
        $this->key = MODULE_PAYMENT_INVOICE_API_KEY;
        $this->id = strstr($this->notification["order"]["id"], "-", true);
        $this->amount = $this->notification["order"]["amount"];
        $this->status = $this->notification["status"];
        $this->signature = $this->notification["signature"];

        switch ($this->notification["notification_type"]) {
            case "pay" :
                switch ($this->status) {
                    case "successful":
                        $this->pay();
                        break;
                    case "error":
                        $this->error();
                        break;
                }
                break;
        }
    }

    /**
     * @param string $id - Payment ID
     * @param string $status - Payment status
     * @param string $key - API Key
     * @return string Payment signature
     */
    public function getSignature($id, $status, $key) {
        return md5($id.$status.$key);
    }

    /**
     * @return array
     */
    private function getData() {
        $postData = file_get_contents('php://input');
        return json_decode($postData, true);
    }

    private function check() {
        $order = $this->getOrder();
        if($order == null) {
            echo "OR not found";
            return false;
        }

        if($this->signature != $this->getSignature($this->tranId, $this->status, $this->key)) {
            echo "Sign";
            return false;
        }

        return true;
    }

    private function pay() {
        if(!$this->check()) {
            echo "Error $this->id";
            return;
        }
        echo $this->setStatus(MODULE_PAYMENT_INVOICE_STATUS_PAID);
    }

    private function error() {
        if(!$this->check()) {
            echo "Error";
            return;
        }
        echo $this->setStatus(MODULE_PAYMENT_INVOICE_STATUS_ERROR);
    }

    private function getOrder() {
        $order_query = tep_db_query($sql="select orders_status, currency, currency_value from " . TABLE_ORDERS . " where orders_id = '" . $this->id . "'");
        if (!tep_db_num_rows($order_query)) {
            return null;
        }
        return $order_query;
    }

    private function setStatus($status) {
        tep_db_query($sql="update " . TABLE_ORDERS . " set orders_status = '" . $status . "', last_modified = now() where orders_id = '" . $this->id . "'");
        $sql_data_array = array('orders_id' => $this->id,
            'orders_status_id' => $status,
            'date_added' => 'now()',
            'customer_notified' => '0',
            'comments' => 'Paid');
        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

        return "OK";
    }
}

new Callback();
