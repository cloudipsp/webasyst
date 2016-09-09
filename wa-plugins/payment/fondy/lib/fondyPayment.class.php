<?php

class fondyPayment extends waPayment implements waIPayment
{

    private $url = 'https://api.fondy.eu/api/checkout/redirect/';

    const ORDER_APPROVED = 'approved';
    const ORDER_DECLINED = 'declined';

    const SIGNATURE_SEPARATOR = '|';

    const ORDER_SEPARATOR = ":";


    public function allowedCurrency()
    {
        return array('UAH', 'RUB', 'USD', 'GBP' ,'EUR');
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $order = waOrder::factory($order_data);
        $description = preg_replace('/[^\.\?,\[]\(\):;"@\\%\s\w\d]+/', ' ', $order->description);
        $description = preg_replace('/[\s]{2,}/', ' ', $description);

        if (!in_array($order->currency, $this->allowedCurrency())) {
            throw new waPaymentException('Invalid currency');
        }

        list(, $lang) = explode("_", wa()->getLocale());

        $contact = new waContact(wa()->getUser()->getId());
        list($email) = $contact->get('email', 'value');

        $redirectUrl = $this->getRelayUrl() . '?&fondy_id=' . $this->fondy_id .
                            '&app_id=' . $this->app_id . '&merchants_id=' . $this->merchant_id;;

        $formFields = array(
            'order_id' => $order_data['order_id'] . self::ORDER_SEPARATOR . time(),
            'merchant_id' => $this->fondy_id,
            'order_desc' => $description,
            'amount' => $this->getAmount($order),
            'currency' => $order->currency,
            'server_callback_url' => $redirectUrl,
            'response_url' => $redirectUrl . '&show_user_response=1',
            'lang' => strtolower($lang),
            'sender_email' => $email
        );

        $formFields['signature'] = $this->getSignature($formFields);
		//PRINT_r($this->app_id);DIE;
        $view = wa()->getView();

        $view->assign('form_fields', $formFields);
        $view->assign('form_url', $this->getEndpointUrl());
        $view->assign('auto_submit', $auto_submit);

        return $view->fetch($this->path . '/templates/payment.html');
    }

    private function getAmount($order)
    {
        return round($order->total * 100);
    }

    protected function callbackInit($request)
    {
		
        if (!empty($request['merchants_id'])) {
			//print_r($request); die; 
            $this->app_id = $request[app_id];
            $this->merchant_id = $request[merchants_id];
       
            list($this->order_id,) = explode(self::ORDER_SEPARATOR, $request['order_id']);
        } else {
            throw new waPaymentException('Invalid invoice number');
        }
        return parent::callbackInit($request);
    }

    public function callbackHandler($request)
    {
        if (empty($_POST)) {
            $fap = json_decode(file_get_contents("php://input"));
            $_POST = array();
            foreach ($fap as $key => $val) {
                $_POST[$key] = $val;
            }
            $request = $_POST;
        }
       //print_r ($request);
        $transactionData = $this->formalizeData($request);
        $transactionData['state'] = self::STATE_VERIFIED;
	
        $url = null;

        if (!empty($request['show_user_response'])) {

            if ($request['order_status'] != self::ORDER_APPROVED) {
                $transactionData['state'] = self::STATE_DECLINED;
                // redirect to fail
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transactionData);
                header("Location: $url");
                exit;
            }
		
			$responseSignature = $_POST['signature'];
			if (isset($_POST['response_signature_string'])){
				unset($_POST['response_signature_string']);
			}
			if (isset($_POST['signature'])){
				unset($_POST['signature']);
			}
			if (self::getSignature($_POST) != $responseSignature) {

                $transactionData['state'] = self::STATE_DECLINED;
                // redirect to fail
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transactionData);
                header("Location: $url");
                exit;
            }

            // redirect to success

			 $transactionData = $this->saveTransaction($transactionData, $request);

            $appPaymentMethod = self::CALLBACK_PAYMENT;
            $result = $this->execAppCallback($appPaymentMethod, $transactionData);
            self::addTransactionData($transactionData['id'], $result);



            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transactionData);
            header("Location: $url");
            exit;
        }

		//PRINT_r ($transactionRawData);DIE;
        $appPaymentMethod = self::CALLBACK_PAYMENT;

        if ($request['order_status'] == self::ORDER_DECLINED) {
            $transactionData['state'] = self::STATE_DECLINED;
            $appPaymentMethod = null;
        }
        $responseSignature = $_POST['signature'];
			unset($_POST['response_signature_string']);
			unset($_POST['signature']);
			if (self::getSignature($_POST) != $responseSignature) {

            $transactionData['state'] = self::STATE_DECLINED;
            $appPaymentMethod = null;
            throw new waPaymentException('Invalid signature');
        }

        $transactionData = $this->saveTransaction($transactionData, $request);

        //print_r ($transactionData);
        if ($appPaymentMethod) {

            $result = $this->execAppCallback($appPaymentMethod, $transactionData);
            self::addTransactionData($transactionData['id'], $result);
        }

        echo 'OK';
        return array(
            'template' => false
        );
    }

    protected function formalizeData($transactionRawData)
    {
				//PRINT_r ($transactionRawData);DIE;
        $transactionData = parent::formalizeData($transactionRawData);
        $transactionData['native_id'] = $this->order_id;
        $transactionData['order_id'] = $this->order_id;
        $transactionData['amount'] = ifempty($transactionRawData['amount'], '');
        $transactionData['currency_id'] = $transactionRawData['currency'];
//$transactionData = $this->saveTransaction($transactionData, $request);
        return $transactionData;
    }

    private function getEndpointUrl()
    {
        return $this->url;
    }

    protected function getSignature($data, $encoded = true)
    {
      $data = array_filter($data, function($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);;

        $str = $this->secret_key;
        foreach ($data as $k => $v) {
            $str .= self::SIGNATURE_SEPARATOR . $v;
        }

        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }
}
