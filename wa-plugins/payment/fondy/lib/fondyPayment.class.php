<?php
/**
 * @author DM
 * @name fondy
 * @link https://fondy.eu
 * @link https://portal.fondy.eu/ru/info/api
 * @property string $merchant_id Публичный ключ - идентификатор магазина.
 * @property string $secret_key Приватный ключ
 */

class fondyPayment extends waPayment implements waIPayment {
	private $url = 'https://api.fondy.eu/api/checkout/redirect/';

	const ORDER_APPROVED = 'approved';
    const ORDER_DECLINED = 'declined';
	const SIGNATURE_SEPARATOR = '|';
	const ORDER_SEPARATOR = ":";


	public function allowedCurrency() {
		return array( 'UAH', 'RUB', 'USD', 'GBP', 'EUR' );
	}

	public function payment( $payment_form_data, $order_data, $auto_submit = false ) {
		$order       = waOrder::factory( $order_data );
		$description = preg_replace( '/[^\.\?,\[]\(\):;"@\\%\s\w\d]+/', ' ', $order->description );

		if ( ! in_array( $order->currency, $this->allowedCurrency() ) ) {
			throw new waPaymentException( 'Invalid currency' );
		}

		list( , $lang ) = explode( "_", wa()->getLocale() );

		$contact = new waContact( wa()->getUser()->getId() );
		list( $email ) = $contact->get( 'email', 'value' );

		$redirectUrl = $this->getRelayUrl() . '?&fondy_id=' . $this->fondy_id .
		               '&app_id=' . $this->app_id . '&merchants_id=' . $this->merchant_id;;

		$amount = $this->getAmount( $order );
		$formFields              = array(
			'order_id'            => $order_data['order_id'] . self::ORDER_SEPARATOR . $amount,
			'merchant_id'         => $this->fondy_id,
			'order_desc'          => mb_substr( trim( $description ), 0, 255, "UTF-8" ),
			'amount'              => $amount,
			'currency'            => $order->currency,
			'server_callback_url' => $redirectUrl,
			'response_url'        => $redirectUrl . '&show_user_response=1',
			'lang'                => strtolower( $lang ),
			'sender_email'        => $email
		);
		$formFields['signature'] = $this->getSignature( $formFields );
		$view                    = wa()->getView();
		$view->assign( 'form_fields', $formFields );
		$view->assign( 'form_url', $this->getEndpointUrl() );
		$view->assign( 'auto_submit', $auto_submit );

		return $view->fetch( $this->path . '/templates/payment.html' );
	}

	private function getAmount( $order ) {
		return round( $order->total * 100 );
	}

	protected function callbackInit( $request ) {
		if ( ! empty( $request['merchants_id'] ) ) {
			$this->app_id      = $request['app_id'];
			$this->merchant_id = $request['merchants_id'];

			list( $this->order_id, ) = explode( self::ORDER_SEPARATOR, $request['order_id'] );
		} else {
			throw new waPaymentException( 'Invalid invoice number' );
		}

		return parent::callbackInit( $request );
	}

	public function callbackHandler( $request ) {
		if ( empty( $_POST ) ) {
			$fap   = json_decode( file_get_contents( "php://input" ) );
			$_POST = array();
			foreach ( $fap as $key => $val ) {
				$_POST[ $key ] = $val;
			}
			$request = $_POST;
		}
		$transactionData          = $this->formalizeData( $request );
		$transactionData['state'] = self::STATE_CAPTURED;
		$transactionData['type'] = self::OPERATION_AUTH_CAPTURE;
		$url                      = null;

		if ( ! empty( $request['show_user_response'] ) ) {

			if ( $request['order_status'] != self::ORDER_APPROVED ) {
				// redirect to fail
				$url = $this->getAdapter()->getBackUrl( waAppPayment::URL_FAIL, $transactionData );
				header( "Location: $url" );
				exit;
			}
			//check if signature valid
			$responseSignature = $_POST['signature'];

			if ( self::getSignature( $_POST ) != $responseSignature ) {
				// redirect to fail
				$url = $this->getAdapter()->getBackUrl( waAppPayment::URL_FAIL, $transactionData );
				header( "Location: $url" );
				exit;
			}

			// redirect to success
			$url = $this->getAdapter()->getBackUrl( waAppPayment::URL_SUCCESS, $transactionData );
			header( "Location: $url" );
			exit;
		}

        $appPaymentMethod = self::CALLBACK_PAYMENT;

		if ( $request['order_status'] != self::ORDER_APPROVED ) {
			$transactionData['state'] = self::STATE_DECLINED;
			$appPaymentMethod         = self::CALLBACK_NOTIFY;
		}
        if ($request['reversal_amount'] != 0) {
            $transactionData['type'] = self::OPERATION_REFUND;
            $transactionData['amount'] = $request['reversal_amount'] / 100;
            if ($request['order_status'] == 'reversed') {
                $appPaymentMethod = self::CALLBACK_REFUND;
                $transactionData['state'] = self::STATE_REFUNDED;
            } else {
                $transactionData['state'] = self::STATE_PARTIAL_REFUNDED;
            }
        }
		//check if signature valid
		$responseSignature = $_POST['signature'];

		if ( self::getSignature( $_POST ) != $responseSignature ) {
			$transactionData['state'] = self::STATE_DECLINED;
			throw new waPaymentException( 'Invalid signature' );
		}

		$transactionData = $this->saveTransaction( $transactionData, $request );

        $result = $this->execAppCallback( $appPaymentMethod, $transactionData );

		return $result;
	}

	protected function formalizeData( $transactionRawData ) {

		$transactionData                = parent::formalizeData( $transactionRawData );
		$transactionData['native_id']   = $this->order_id;
		$transactionData['order_id']    = $this->order_id;
		$amount                         = ifempty( $transactionRawData['amount'], '' );
		$transactionData['amount']      = $amount / 100;
		$transactionData['currency_id'] = $transactionRawData['currency'];
		$transactionData['view_data']   = 'Статус заказа: ' . $transactionRawData['order_status'] . ', ID заказа в системе: ' . $transactionRawData['payment_id'];
		$transactionData['result']      = 1;

		return $transactionData;
	}

	private function getEndpointUrl() {
		return $this->url;
	}

	protected function fondy_filter( $var ) {
		return $var !== '' && $var !== null;
	}

	protected function getSignature( $data, $encoded = true ) {
		if ( isset( $data['response_signature_string'] ) ) {
			unset( $data['response_signature_string'] );
		}

		if ( isset( $data['signature'] ) ) {
			unset( $data['signature'] );
		}
		$data = array_filter( $data, array( $this, 'fondy_filter' ) );
		ksort( $data );

		$str = $this->secret_key;
		foreach ( $data as $k => $v ) {
			$str .= self::SIGNATURE_SEPARATOR . $v;
		}

		if ( $encoded ) {
			return sha1( $str );
		} else {
			return $str;
		}
	}
}