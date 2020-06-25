<?php
include "InvoiceSDK/RestClient.php";
include "InvoiceSDK/CREATE_PAYMENT.php";
include "InvoiceSDK/CREATE_TERMINAL.php";
include "InvoiceSDK/common/ITEM.php";
include "InvoiceSDK/common/ORDER.php";
include "InvoiceSDK/common/SETTINGS.php";

class invoice
{
    var $code, $title, $description, $enabled;
    private $terminal = "";

    function invoice()
    {
        $this->code = 'invoice';
        $this->title = MODULE_PAYMENT_INVOICE_TEXT_TITLE;
        $this->description = MODULE_PAYMENT_INVOICE_TEXT_DESCRIPTION;
        $this->enabled = true;
    }

    function update_status()
    {
        return false;
    }

    function javascript_validation()
    {
        return false;
    }

    function selection()
    {
        return array('id' => $this->code, 'module' => $this->title);
    }

    function pre_confirmation_check()
    {
        return false;
    }

    function confirmation()
    {
        return $this->checkOrCreateTerminal();
    }

    function process_button()
    {

        return false;
    }

    function before_process()
    {
        return false;
    }

    function after_process()
    {
        global $insert_id, $cart, $order;

        $this->createPayment();
    }

    function output_error()
    {
        return false;
    }

    function check()
    {
        return true;
    }

    private function createPayment() {
        global $insert_id, $cart, $order;

        $sum = $order->info['total'];
        $currency = $order->info['currency'];
        $account = $insert_id;

        if(!$this->checkOrCreateTerminal()) {
            echo "<script type='text/javascript'> alert('Возникла ошибка при создании терминала, попробуйте позже'); </script>";
            return;
        }

        $invoice_order = new INVOICE_ORDER($sum);
        $invoice_order->currency = $currency;
        $invoice_order->id = $insert_id;

        $settings = new SETTINGS($this->terminal);
        $settings->success_url = HTTP_SERVER;

        $create_payment = new CREATE_PAYMENT($invoice_order,$settings,null);
        $info = $this->getRest()->CreatePayment($create_payment);

        if($info == null or $info->error != null) {
            echo "<script type='text/javascript'> alert('Возникла ошибка при создании платежа, попробуйте позже'); </script>";
            return;
        }
        $cart->reset(true);
        tep_session_unregister('sendto');
        tep_session_unregister('billto');
        tep_session_unregister('shipping');
        tep_session_unregister('payment');
        tep_session_unregister('comments');
        tep_redirect($info->payment_url);
    }

    private function checkOrCreateTerminal() {
        if(MODULE_PAYMENT_INVOICE_TERMINAL == null or empty(MODULE_PAYMENT_INVOICE_TERMINAL)) {
            $info = $this->createTerminal();
            if($info == null or $info->error != null) {
                return false;
            } else {
                $this->updateTerminal($info->id);
                $this->terminal = $info->id;
                return true;
            }
        }
        $this->terminal = MODULE_PAYMENT_INVOICE_TERMINAL;
        return true;
    }

    private function createTerminal() {

        $restClient = $this->getRest();

        $create_terminal = new CREATE_TERMINAL("osCommerce");
        $create_terminal->type = "dynamical";

        return $restClient->CreateTerminal($create_terminal);
    }

    private function getRest() {
        $login= MODULE_PAYMENT_INVOICE_LOGIN;
        $key = MODULE_PAYMENT_INVOICE_API_KEY;

        return new RestClient($login, $key);
    }

    function install()
    {

        global $cfgModules, $language;
        $module_language_directory = $cfgModules->get('payment', 'language_directory');
        include_once($module_language_directory.$language."/modules/payment/invoice.php");

        $success_id = $this->createOrderStatus(MODULE_PAYMENT_INVOICE_STATUS_SUCCESS_TITLE);
        $err_id = $this->createOrderStatus(MODULE_PAYMENT_INVOICE_STATUS_ERROR_TITLE);

        $this->createField(MODULE_PAYMENT_INVOICE_TERMINAL_TITLE, "MODULE_PAYMENT_INVOICE_TERMINAL");
        $this->createField(MODULE_PAYMENT_INVOICE_API_KEY_TITLE, "MODULE_PAYMENT_INVOICE_API_KEY");
        $this->createField(MODULE_PAYMENT_INVOICE_LOGIN_TITLE, "MODULE_PAYMENT_INVOICE_LOGIN");
        $this->createField(MODULE_PAYMENT_INVOICE_STATUS_SUCCESS_TITLE, "MODULE_PAYMENT_INVOICE_STATUS_PAID", $success_id);
        $this->createField(MODULE_PAYMENT_INVOICE_STATUS_ERROR_TITLE, "MODULE_PAYMENT_INVOICE_STATUS_ERROR", $err_id);
    }

    function createField($title, $key, $default = '') {
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values (
            '".$title."', 
            '$key', 
            '$default', 
            '', 
            '6', '0', now())"
        );
    }

    function updateTerminal($id) {
        $this->updateValue("MODULE_PAYMENT_INVOICE_TERMINAL", $id);
    }

    function updateValue($key, $value) {
        tep_db_query("update ". TABLE_CONFIGURATION . " SET configuration_value = '".$value."' WHERE configuration_key='$key'");
    }

    function remove()
    {
        tep_db_query("delete from " . TABLE_CONFIGURATION .
            " where configuration_key in ('" . implode("', '", $this->keys()) . "')");

    }

    function keys()
    {
        return array(
            'MODULE_PAYMENT_INVOICE_LOGIN',
            'MODULE_PAYMENT_INVOICE_API_KEY',
            'MODULE_PAYMENT_INVOICE_TERMINAL',
            'MODULE_PAYMENT_INVOICE_STATUS_ERROR',
            "MODULE_PAYMENT_INVOICE_STATUS_PAID"
        );
    }

    function createOrderStatus( $title ){
        $q = tep_db_query("select orders_status_id from ".TABLE_ORDERS_STATUS." where orders_status_name = '".$title."' limit 1");
        if (tep_db_num_rows($q) < 1) {
            $q = tep_db_query("select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS);
            $row = tep_db_fetch_array($q);
            $status_id = $row['status_id'] + 1;
            $languages = tep_get_languages();
            $qf = tep_db_query("describe " . TABLE_ORDERS_STATUS . " public_flag");
            if (tep_db_num_rows($qf) == 1) {
                foreach ($languages as $lang) {
                    tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name, public_flag) values ('" . $status_id . "', '" . $lang['id'] . "', " . "'" . $title . "', 1)");
                }
            } else {
                foreach ($languages as $lang) {
                    tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) values ('" . $status_id . "', '" . $lang['id'] . "', " . "'" . $title . "')");
                }
            }
        }else{
            $status = tep_db_fetch_array($q);
            $status_id = $status['orders_status_id'];
        }
        return $status_id;
    }
}