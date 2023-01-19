<?php
include "InvoiceSDK/RestClient.php";
include "InvoiceSDK/CREATE_PAYMENT.php";
include "InvoiceSDK/CREATE_TERMINAL.php";
include "InvoiceSDK/common/ITEM.php";
include "InvoiceSDK/common/ORDER.php";
include "InvoiceSDK/common/SETTINGS.php";
include "InvoiceSDK/GET_TERMINAL.php";

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
        return $this->getTerminal();
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

    private function createPayment()
    {
        global $insert_id, $cart, $order;

        $sum = $order->info['total'];
        $currency = $order->info['currency'];
        $terminal = $this->getTerminal();

        if ($terminal == null) {
            echo "<script type='text/javascript'> alert('Возникла ошибка при создании терминала, попробуйте позже'); </script>";
            return;
        }

        $create_payment = new CREATE_PAYMENT();
        $create_payment->order = $this->getOrder($sum, $currency, $insert_id);
        $create_payment->settings = $this->getSettings();
        $create_payment->receipt = $this->getReceipt();

        $info = $this->getRest()->CreatePayment($create_payment);

        if ($info == null or $info->error != null) {
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

    /**
     * @return INVOICE_ORDER
     */
    private function getOrder($sum, $currency, $insert_id)
    {
        $order = new INVOICE_ORDER();
        $order->amount = $this->$sum;
        $order->id = "$this->$insert_id" . "-" . bin2hex(random_bytes(5));
        $order->currency = $currency;

        return $order;
    }

    /**
     * @return SETTINGS
     */
    private function getSettings()
    {
        $settings = new SETTINGS();
        $settings->terminal_id = $this->terminal;
        $settings->success_url = HTTP_SERVER;
        $settings->fail_url = HTTP_SERVER;

        return $settings;
    }

    /**
     * @return ITEM
     */
    private function getReceipt()
    {
        $receipt = array();

        return $receipt;
    }

    public function getTerminal()
    {
        $tid = MODULE_PAYMENT_INVOICE_TERMINAL;
        $restClient = $this->getRest();

        $terminal = new GET_TERMINAL();
        $terminal->alias =  $tid;
        $info = $restClient->GetTerminal($terminal);

        if ($tid == null or empty($tid) || $info->id == null || $info->id != $terminal->alias) {
            $request = new CREATE_TERMINAL();
            $request->name = "osCommerce";
            $request->type = "dynamical";
            $request->description = "osCommerce terminal";
            $request->defaultPrice = 0;

            $response = $restClient->CreateTerminal($request);

            if ($response == null or $response->error != null) {
                return false;
            } else {
                $this->updateTerminal($response->id);
                $this->terminal = $response->id;
                $tid = $response->id;
            }
        }
        return $tid;
    }

    private function getRest()
    {
        $login = MODULE_PAYMENT_INVOICE_LOGIN;
        $key = MODULE_PAYMENT_INVOICE_API_KEY;

        return new RestClient($login, $key);
    }


    function install()
    {
        global $cfgModules, $language;
        $module_language_directory = $cfgModules->get('payment', 'language_directory');
        include_once($module_language_directory . $language . "/modules/payment/invoice.php");

        $success_id = $this->createOrderStatus(MODULE_PAYMENT_INVOICE_STATUS_SUCCESS_TITLE);
        $err_id = $this->createOrderStatus(MODULE_PAYMENT_INVOICE_STATUS_ERROR_TITLE);

        $this->createField(MODULE_PAYMENT_INVOICE_TERMINAL_TITLE, "MODULE_PAYMENT_INVOICE_TERMINAL");
        $this->createField(MODULE_PAYMENT_INVOICE_API_KEY_TITLE, "MODULE_PAYMENT_INVOICE_API_KEY");
        $this->createField(MODULE_PAYMENT_INVOICE_LOGIN_TITLE, "MODULE_PAYMENT_INVOICE_LOGIN");
        $this->createField(MODULE_PAYMENT_INVOICE_STATUS_SUCCESS_TITLE, "MODULE_PAYMENT_INVOICE_STATUS_PAID", $success_id);
        $this->createField(MODULE_PAYMENT_INVOICE_STATUS_ERROR_TITLE, "MODULE_PAYMENT_INVOICE_STATUS_ERROR", $err_id);
    }

    function createField($title, $key, $default = '')
    {
        tep_db_query(
            "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values (
            '" . $title . "', 
            '$key', 
            '$default', 
            '', 
            '6', '0', now())"
        );
    }

    function updateTerminal($id)
    {
        $this->updateValue("MODULE_PAYMENT_INVOICE_TERMINAL", $id);
    }

    function updateValue($key, $value)
    {
        tep_db_query("update " . TABLE_CONFIGURATION . " SET configuration_value = '" . $value . "' WHERE configuration_key='$key'");
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

    function createOrderStatus($title)
    {
        $q = tep_db_query("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_name = '" . $title . "' limit 1");
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
        } else {
            $status = tep_db_fetch_array($q);
            $status_id = $status['orders_status_id'];
        }
        return $status_id;
    }
}
