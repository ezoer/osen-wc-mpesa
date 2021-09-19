<?php

namespace Osen\Woocommerce\Mpesa;

/**
 * @package MPesa For WooCommerce
 * @subpackage C2B Library
 * @author Osen Concepts < hi@osen.co.ke >
 * @version 2.0.0
 * @since 0.18.01
 */

/**
 *
 */
class C2B
{
    /**
     * @param string  | Environment in use    | live/sandbox
     */
    public $env = 'sandbox';

    /**
     * @param string | Daraja App Consumer Key   | lipia/validate
     */
    public $appkey;

    /**
     * @param string | Daraja App Consumer Secret   | lipia/validate
     */
    public $appsecret;

    /**
     * @param string | Online Passkey | lipia/validate
     */
    public $passkey;

    /**
     * @param string  | Head Office Shortcode | 123456
     */
    public $headoffice;

    /**
     * @param string  | Business Paybill/Till | 123456
     */
    public $shortcode;

    /**
     * @param integer | Identifier Type   | 1(MSISDN)/2(Till)/4(Paybill)
     */
    public $type = 4;

    /**
     * @param string | Validation URI   | lipia/validate
     */
    public $validate;

    /**
     * @param string  | Confirmation URI  | lipia/confirm
     */
    public $confirm;

    /**
     * @param string  | Reconciliation URI  | lipia/reconcile
     */
    public $reconcile;

    /**
     * @param string  | Timeout URI   | lipia/reconcile
     */
    public $timeout;

	/**
	 * @param string  | Timeout URI   | lipia/reconcile
	 */
	public $initiator;

	/**
	 * @param string  | Timeout URI   | lipia/reconcile
	 */
	public $password;

	/**
	 * @param string  | Encryption Signature
	 */
	public $signature;

	/**
	 * @param string  | generated/Stored Token
	 */
	public $token;

	/**
	 * @param string  | Base API URL
	 */
	private $url = 'https://api.safaricom.co.ke';

    public function __construct($vendor_id = null)
    {
        if (is_null($vendor_id)) {
            $c2b = get_option('woocommerce_mpesa_settings');
            $config = array(
                'env'        => $c2b['env'] ?? 'sandbox',
                'appkey'     => $c2b['key'] ?? '9v38Dtu5u2BpsITPmLcXNWGMsjZRWSTG',
                'appsecret'  => $c2b['secret'] ?? 'bclwIPkcRqw61yUt',
                'headoffice' => $c2b['headoffice'] ?? '174379',
                'shortcode'  => $c2b['shortcode'] ?? '174379',
                'initiator' => $c2b['initiator'] ?? 'test',
                'password' => $c2b['password'] ?? 'lipia',
                'type'       => $c2b['idtype'] ?? 4,
                'passkey'    => $c2b['passkey'] ?? 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
                'validate'   => home_url('wc-api/lipwa?action=validate/'),
                'confirm'    => home_url('wc-api/lipwa?action=confirm/'),
                'reconcile'  => home_url('wc-api/lipwa?action=reconcile/'),
                'timeout'    => home_url('wc-api/lipwa?action=timeout/'),
            );
        } else {
            $config = array(
                'env'        => get_user_meta($vendor_id, 'mpesa_env', true) ?? 'sandbox',
                'appkey'     => get_user_meta($vendor_id, 'mpesa_key', true) ?? '9v38Dtu5u2BpsITPmLcXNWGMsjZRWSTG',
                'appsecret'  => get_user_meta($vendor_id, 'mpesa_secret', true) ?? 'bclwIPkcRqw61yUt',
                'headoffice' => get_user_meta($vendor_id, 'mpesa_store', true) ?? '174379',
                'shortcode'  => get_user_meta($vendor_id, 'mpesa_shortcode', true) ?? '174379',
                'initiator' => get_user_meta($vendor_id, 'mpesa_initiator', true) ?? 'test',
                'password' => get_user_meta($vendor_id, 'mpesa_password', true) ?? 'lipia',
                'type'       => get_user_meta($vendor_id, 'mpesa_type', true) ?? 4,
                'passkey'    => get_user_meta($vendor_id, 'mpesa_passkey', true) ?? 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
                'validate'   => home_url('wc-api/lipwa?action=validate/'),
                'confirm'    => home_url('wc-api/lipwa?action=confirm/'),
                'reconcile'  => home_url('wc-api/lipwa?action=reconcile/'),
                'timeout'    => home_url('wc-api/lipwa?action=timeout/'),
            );
        }

		if ($config['env'] === 'sanbox') {
			$this->url =  'https://sandbox.safaricom.co.ke';
		}

        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Function to generate access token
     * @return string/mixed
     */
    public function authorize($token = null)
    {
        if (is_null($token) || !$token) {
            $endpoint = $this->url . '/oauth/v1/generate?grant_type=client_credentials';

            $credentials = base64_encode($this->appkey . ':' . $this->appsecret);
            $response    = wp_remote_get(
                $endpoint,
                array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . $credentials,
                    ),
                )
            );

            $return      = is_wp_error($response) ? 'null' : json_decode($response['body']);
            $this->token = isset($return->access_token) ? $return->access_token : '';
            set_transient('mpesa_token', $this->token, 60 * 55);
        } else {
            $this->token = $token;
        }

        return $this;
    }

    /**
     * Function to process response data for validation
     * @param callable $callback - Optional callable function to process the response - must return boolean
     * @return array
     */
    public function validate($callback, $data)
    {
        if (is_null($callback) || empty($callback)) {
            return array(
                'ResultCode' => 0,
                'ResultDesc' => 'Success',
            );
        } else {
            if (!call_user_func_array($callback, array($data))) {
                return array(
                    'ResultCode' => 1,
                    'ResultDesc' => 'Failed',
                );
            } else {
                return array(
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                );
            }
        }
    }

    /**
     * Function to process response data for confirmation
     * @param callable $callback - Optional callable function to process the response - must return boolean
     * @return array
     */
    public function confirm($callback, $data)
    {
        if (is_null($callback) || empty($callback)) {
            return array(
                'ResultCode' => 0,
                'ResultDesc' => 'Success',
            );
        } else {
            if (!call_user_func_array($callback, array($data))) {
                return array(
                    'ResultCode' => 1,
                    'ResultDesc' => 'Failed',
                );
            } else {
                return array(
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                );
            }
        }
    }

    /**
     * Function to register validation and confirmation URLs
     * @param string $env - Environment for which to register URLs
     * @return bool/array
     */
    public function register($callback = null)
    {
        $endpoint = $this->url . '/mpesa/c2b/v1/registerurl';

        $post_data = array(
            'ShortCode'       => $this->headoffice,
            'ResponseType'    => 'Cancelled',
            'ConfirmationURL' => $this->confirm,
            'ValidationURL'   => $this->validate,
        );
        $data_string = json_encode($post_data);

        $response = wp_remote_post(
            $endpoint,
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->token,
                ),
                'body'    => $data_string,
            )
        );
        $result = is_wp_error($response)
            ? array('errorCode' => 1, 'errorMessage' => $response->get_error_message())
            : json_decode($response['body'], true);

        return is_null($callback)
            ? $result
            : call_user_func($callback, $result);
    }

    /**
     * Function to process request for payment
     * @param string $phone     - Phone Number to send STK Prompt Request to
     * @param string $amount    - Amount of money to charge
     * @param string $reference - Account to show in STK Prompt
     * @param string $trxdesc   - Transaction Description(optional)
     * @param string $remark    - Remarks about transaction(optional)
     * @return array
     */
    public function request($phone, $amount, $reference, $trxdesc = 'WooCommerce Payment', $remark = 'WooCommerce Payment')
    {
        $phone     = preg_replace('/^0/', '254', str_replace("+", "", $phone));
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->headoffice . $this->passkey . $timestamp);
        $endpoint  = $this->url . '/mpesa/stkpush/v1/processrequest';

        $post_data = array(
            'BusinessShortCode' => $this->headoffice,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => ($this->type == 4) ? 'CustomerPayBillOnline' : 'BuyGoodsOnline',
            'Amount'            => round($amount),
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->reconcile,
            'AccountReference'  => $reference,
            'TransactionDesc'   => $trxdesc,
            'Remark'            => $remark,
        );

        $data_string = json_encode($post_data);
        $response    = wp_remote_post(
            $endpoint,
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->token,
                ),
                'body'    => $data_string,
            )
        );
        return is_wp_error($response)
            ? array('errorCode' => 1, 'errorMessage' => $response->get_error_message())
            : json_decode($response['body'], true);
    }

    /**
     * Function to process response data for reconciliation
     * @param callable $callback - Optional callable function to process the response - must return boolean
     * @return bool/array
     */
    public function reconcile($args)
    {
        $callback = isset($args[0]) ? $args[0] : 'wc_mpesa_reconcile';
        $data     = isset($args[1]) ? $args[1] : null;

        if (is_null($data)) {
            $response = json_decode(file_get_contents('php://input'), true);
            $response = isset($response['Body']) ? $response['Body'] : array();
        } else {
            $response = $data;
        }

        return is_null($callback)
            ? array('resultCode' => 0, 'resultDesc' => 'Reconciliation successful')
            : (call_user_func_array($callback, array($response))? array('resultCode' => 0, 'resultDesc' => 'Reconciliation successful')
            : array('resultCode' => 1, 'resultDesc' => 'Reconciliation failed'));
    }

    /**
     * Reverse a Transaction
     *
     * @param string $transaction
     * @param Integer $amount
     * @param Integer $receiver
     * @param string $receiver_type
     * @param string $remarks
     * @param string $occassion
     *
     * @return array Result
     */
    public function reverse(
        $transaction,
        $amount,
        $receiver = "",
        $receiver_type = 3,
        $remarks = "Transaction Reversal",
        $occasion = "Transaction Reversal",
        $callback = null
    ) {
        $phone     = preg_replace('/^0/', '254', str_replace("+", "", $receiver));
        $endpoint  = $this->url . '/mpesa/reversal/v1/request';
        $env       = $this->env;
        $plaintext = $this->password;
        $publicKey = file_get_contents(__DIR__ . "/cert/{$env}/cert.cer");

        openssl_public_encrypt($plaintext, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
        $password = base64_encode($encrypted);

        $post_data = array(
            "CommandID"              => "TransactionReversal",
            "Initiator"              => $this->initiator,
            "SecurityCredential"     => $password,
            "TransactionID"          => $transaction,
            "Amount"                 => $amount,
            "ReceiverParty"          => $phone,
            "RecieverIdentifierType" => $receiver_type,
            "ResultURL"              => $this->result,
            "QueueTimeOutURL"        => $this->timeout,
            "Remarks"                => $remarks,
            "Occasion"               => $occasion,
        );

        $data_string = json_encode($post_data);
        $response    = wp_remote_post(
            $endpoint,
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->token,
                ),
                'body'    => $data_string,
            )
        );

        $result = is_wp_error($response)
            ? array('errorCode' => 1, 'errorMessage' => $response->get_error_message())
            : json_decode($response['body'], true);

        return is_null($callback)
            ? $result
            : $callback($result);
    }

    /**
     * Function to process response data if system times out
     * @param callable $callback - Optional callable function to process the response - must return boolean
     * @return bool/array
     */
    public function timeout($callback = null, $data = null)
    {
        if (is_null($data)) {
            $response = json_decode(file_get_contents('php://input'), true);
            $response = isset($response['Body']) ? $response['Body'] : array();
        } else {
            $response = $data;
        }

        if (is_null($callback)) {
            return true;
        } else {
            return call_user_func_array($callback, array($response));
        }
    }
}
