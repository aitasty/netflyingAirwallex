<?php

namespace Netflying\Airwallex\lib;

use Exception;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayInterface;
use Netflying\Payment\lib\Request;
use Netflying\Payment\data\Address;

use Netflying\Payment\data\Merchant;
use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;


class Airwallex extends PayInterface
{
    protected $merchant = null;
    //日志对象
    protected $log = '';
    //强制开启3ds,测试环境需要
    protected $force3ds = false;
    //获取请求token,有时效
    protected $token = '';
    //通过payment_intent_id获取订单sn
    protected $snPaymentIntentId = null;

    public function __construct(Merchant $Merchant, $Log = '')
    {
        $this->merchant($Merchant);
        $this->log($Log);
    }

    public function getForce3ds()
    {
        return $this->force3ds;
    }
    public function setForce3ds($is3ds)
    {
        $this->force3ds = (bool)$is3ds;
        return $this;
    }
    public function getToken()
    {
        return $this->token;
    }
    public function setToken($token)
    {
        $this->token = $token;
        return $this;
    }
    /**
     * 初始化商户
     * @param Merchant $Merchant
     * @return self
     */
    public function merchant(Merchant $Merchant)
    {
        $this->merchant = $Merchant;
        return $this;
    }
    public function merchantUrl(Order $Order)
    {
        $sn = $Order['sn'];
        $merchant = $this->merchant;
        $apiData = $merchant['api_data'];
        $sn = $Order['sn'];
        $urlReplace = function ($val) use ($sn) {
            return str_replace('{$sn}', $sn, $val);
        };
        $urlData = Utils::modeData([
            'return_url' => '',
            'success_url' => '',
            'cancel_url' => ''
        ], $apiData, [
            'return_url' => $urlReplace,
            'success_url' => $urlReplace,
            'cancel_url' => $urlReplace
        ]);
        $apiData = array_merge($apiData, $urlData);
        $merchant->setApiData($apiData);
        $this->merchant = $merchant;
        return $this;
    }
    /**
     * 日志对象
     */
    public function log($Log = '')
    {
        $this->log = $Log;
        return $this;
    }
    /**
     * 获取接口请求token
     *
     * @return void
     */
    public function requestToken()
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['token_url'];
        $res = $this->request($url);
        if ($res['code'] == 200) {
            $result = json_decode($res['body']);
            return isset($result['token']) ? $result['token'] : '';
        }
        return '';
    }

    public function purchase(Order $Order): Redirect
    {
        $this->merchantUrl($Order);
        $apiData = $this->merchant['api_data'];
        $orderData = $this->orderData($Order);
        $createPayUrl = $apiData['endpoint_domain'] . $apiData['endpoint'];
        $createResponse = $this->request($createPayUrl, $orderData);
        $createResponse = json_decode($createResponse, TRUE);
        if (!empty($createResponse['id']) && $createResponse['status'] == 'REQUIRES_PAYMENT_METHOD') {
            //确认提交
            $confirmUrl = str_replace('{$id}', $createResponse['id'], $apiData['endpoint_confirm']);
            $confirmUrl = $apiData['endpoint_domain'] . $confirmUrl;
            $billingData = $this->billingData($Order);
            $creditData = $this->creditData($Order);
            $deviceData = $this->deviceData($Order);
            $creditData['billing'] = $billingData;
            $paymentMethod = [
                'type' => 'card',
                //'billing' => $billing,
                'card' => $creditData
            ];
            $paymentMethodOptions = [
                'card' => [
                    'auto_capture' => true,
                ]
            ];
            $device = [
                'device_id' => $deviceData['device_id']
            ];
            $returnUrl = Rt::buildUri($apiData['return_url'], ['3ds' => 1, 'paymentIntentId' => $createResponse['id'], 'sn' => $Order['sn']]);
            $post = [
                'request_id' => Utils::dayipSn(),
                'payment_method' => $paymentMethod,
                'payment_method_options' => $paymentMethodOptions,
                'device' => $device,
                'device_data' => $deviceData,
                'return_url' => $returnUrl, //注意:第一次需要把orderId带上,否则后续返回将无orderId
            ];
            return $this->requestRedirect($confirmUrl, $post, $Order);
        } else {
            throw new Exception('payment create failed!');
        }
        return $this->errorRedirect();
    }
    /**
     * return_url 返回地址逻辑处理,跳转校验
     */
    public function returnConfirm($data)
    {
        //$data = Rt::receive();

    }

    /**
     * 注入根据payment_intent_id获取订单sn方法
     *
     * @param \Closure $fn
     * @return this
     */
    public function snPaymentIntentId(\Closure $fn)
    {
        $this->snPaymentIntentId = $fn;
        return $this;
    }

    /**
     * 异步通知回调
     */
    public function notify()
    {
        $json = file_get_contents('php://input');
        //验证有效信息
        $header = $_SERVER;
        $timestamp = isset($header['HTTP_X_TIMESTAMP']) ? $header['HTTP_X_TIMESTAMP'] : time();
        $signature = isset($header['HTTP_X_SIGNATURE']) ? $header['HTTP_X_SIGNATURE'] : '';
        if (empty($signature)) {
            throw new Exception('signature error');
        }
        if (hash_hmac('sha256', $timestamp . $json, $this->config['webhookSecret']) != $signature) {
            throw new Exception('signature invalid');
        }
        //状态处理
        $data = json_decode($json, true);
        if (empty($data)) {
            throw new Exception('data error');
        }
        $name = $data['name'];
        $response = Utils::mapData([
            'sn' => '',
            'status_descrip' => '',
            'currency' => '',
            'amount' => 0,
            'pay_id' => '',
            'pay_sn' => '',
            'payment_intent_id', //如果sn为空,需要通过该参数获取
            'latest_payment_attempt' => [],
            'response_status' => '',
        ], $data['data']['object'], [
            'sn' => 'merchant_order_id',
            'pay_id' => 'id',    //payment intent id & 退款id
            'pay_sn' => 'request_id', //交易流水订单号
            'response_status' => 'status'
        ]);

        if (empty($response['sn']) && $this->snPaymentIntentId instanceof \Closure) {
            $response['sn'] = call_user_func_array($this->snPaymentIntentId, [$response['payment_intent_id']]);
        }
        $merchant = $this->merchant;
        $payment['type'] = $merchant['type'];
        $payment['merchant'] = $merchant['merchant'];
        $payment['fee'] = 0;
        $payment['pay_time'] = !empty($data['createAt']) ? strtotime($data['createAt']) : 0;
        $payment['status_descrip'] = isset($response['latest_payment_attempt']['status']) ? $response['latest_payment_attempt']['status'] : $response['response_status'];
        $status = 0;
        //交易状态
        switch ($name) {
            case 'payment_intent.created': //创建订单
                break;
            case 'payment_intent.cancelled':
                break;
            case 'payment_intent.succeeded': //支付处理成功
                $status = 1;
                break;
            case 'refund.received':
                break;
            case 'refund.processing':   //退款中
                break;
            case 'refund.succeeded':   //退款完成
                $status = -1;
                break;
        }
        $payment['status'] = $status;
        //return billing address
        $pb = isset($response['latest_payment_attempt']['payment_method']['billing']) ? $response['latest_payment_attempt']['payment_method']['billing'] : [];
        if (!empty($pb)) {
            $address = $pb['address'];
            $billing1 = Utils::mapData([
                'first_name'      => '',
                'last_name'       => '',
                'phone' => '',
                'email' => ''
            ],$pb,[
                'phone' => 'phone_number'
            ]);
            $billing2 = Utils::mapData([
                'country_code' => '',
                'region' => '',
                'city' => '',
                'district' => '',
                'postal_code' => '',
                'street_address' => ''
            ], $address, [
                'region' => 'state',
                'street_address' => 'street'
            ]);
            $Billing = new Address(array_merge($billing1, $billing2));
            $payment['address']['billing'] = $Billing;
        }
        return new OrderPayment($payment);
    }

    /**
     *
     * @param string $url
     * @param array $data
     * @param array $param
     * @return Redirect
     */
    protected function requestRedirect($url, $data, Order $Order)
    {
        $this->merchantUrl($Order);
        $apiData = $this->merchant['api_data'];
        $res = $this->request($url, $data);
        $json = json_decode($res['body'], true);
        if ($res['code'] != 200) {
            return $this->errorRedirect();
        }
        $rsStatus = isset($json['status']) ? strtoupper($json['status']) : '';
        if (empty($status)) {
            return $this->errorRedirect();
        }
        //success or exception 
        $code = isset($json['code']) ? $json['code'] : '';
        $message = isset($json['message']) ? $json['message'] : '';
        $rsStatus = stripos($message, 'SUCCEEDED') !== false ? 'SUCCEEDED' : $rsStatus;
        if ($rsStatus == 'SUCCEEDED') {
            $successUrl = $apiData['success_url'];
            return $this->toRedirect($successUrl);
        } elseif ($rsStatus == 'CANCELLED') { //The PaymentIntent has been cancelled. Uncaptured funds will be returned.
            $cancelUrl = $apiData['cancel_url'];
            return $this->toRedirect($cancelUrl, ['status' => -1]);
        } elseif ($rsStatus == 'REQUIRES_CUSTOMER_ACTION') {
            //has next action
            $nextAction = isset($json['next_action']) ? $json['next_action'] : '';
            $nextData = isset($nextAction['data']) ? $nextAction['data'] : [];
            $nextUrl = isset($nextAction['url']) ? $nextAction['url'] : '';
            //前端模拟form post提交跳转到next_url,跳转到自动定位到->$return_url (旧版:jwt; 新版: threeDSMethodData->creq->..->return_url)
            //The 3D Secure 2 flow will provide a response in the return_url you earlier provided.
            //The encrypted content you have received contains the device details that the issuer requires.
            if (!empty($nextData['jwt'])) {
                $nextData['JWT'] = $nextData['jwt'];
                $nextData['BIN'] = $Order['credit_card_data']['card_number'];
                //unset($nextData['jwt']);
            }
            $nextData['next_action'] = 1; //标记为继续确认?
            return $this->toRedirect($nextUrl, $nextData, 'post');
        } else {
            // $status == 'CANCELLED' 
            // The PaymentIntent has been cancelled. Uncaptured funds will be returned.
            // $status == 'REQUIRES_PAYMENT_METHOD'
            //1. Populate payment_method when calling confirm
            //2. This value is returned if payment_method is either null, or the payment_method has failed during confirm,
            //   and a different payment_method should be provide
            // $status == 'REQUIRES_CAPTURE'            
            //See next_action for the details. For example next_action=capture indicates that capture is outstanding.
            throw new \Exception($message, $code);
        }
        return $this->errorRedirect();
    }
    /**
     * 错误请求结果
     * 
     * @return Redirect
     */
    protected function errorRedirect()
    {
        return new Redirect([
            'status' => 0, //请求异常，非跳转
            'url' => '',
            'type' => 'get',
            'params' => [],
            'exception' => []
        ]);
    }
    /**
     * 跳转
     * @param string $url 跳转链接，为空跳转到失败页
     * @param array $data
     * @param string $type
     * @return void
     */
    protected function toRedirect($url, $data = [], $type = 'get')
    {
        return new Redirect([
            'status' => 1,  //不论是否有url,必跳
            'url' => $url,
            'type' => $type,
            'params' => $data,
            'exception' => []
        ]);
    }

    /**
     * 订单数据结构
     *
     * @param Order $Order
     * @return array
     */
    protected function orderData(Order $Order)
    {
        $shipping = $this->shippingData($Order);
        $data = [
            'merchant_order_id' => $Order['sn'], //The order ID created in merchant's order system that corresponds to this PaymentIntent
            'request_id' => Utils::dayipSn(), //Unique request ID specified by the merchant
            'amount' => $Order['purchase_amount'],    //Payment amount. This is the order amount you would like to charge your customer.
            'currency' => $Order['currency'], //Payment currency
            'descriptor' => $Order['descript'], //Descriptor that will display to the customer. For example, in customer's credit card statement
            'order' => [
                'shipping' => $shipping
            ]
        ];
        if ($this->force3ds) {
            $data['payment_method_options']['card']['risk_control']['three_domain_secure_action'] = "FORCE_3DS";
        }
        return $data;
    }
    /**
     * 信用卡bin信息
     * @param Order $order
     * @return array
     */
    protected function creditData(Order $Order)
    {
        $card = $Order['credit_card_data'];
        $credit = [
            'number' => $card['card_number'],  //Card number
            'expiry_month' => $card['expiry_month'], //Two digit number representing the card’s expiration month
            'expiry_year' => $card['expiry_year'], //Four digit number representing the card’s expiration year
            'cvc' => $card['cvc'],  //CVC code of this card
            'name' => $card['holder_name']  //Card holder name
        ];
        return $credit;
    }
    protected function shippingData(Order $Order)
    {
        return $this->addressData($Order, 'shipping');
    }
    protected function billingData(Order $Order)
    {
        return $this->addressData($Order, 'billing');
    }
    /**
     * 地址数据模型
     *
     * @param Order $order
     * @param string $type [shipping,billing]
     * @return void
     */
    protected function addressData(Order $Order, $type)
    {
        $address = $Order['address'];
        $orderAddress = $address[$type];
        $data  = [
            'city' => $orderAddress['city'],  //required: City of the address,1-50 characters long
            'country_code' => $orderAddress['country_code'], //required: country code (2-letter ISO 3166-2 country code)
            'street' => $orderAddress['street_address'], //required: 1-200 characters long, Should not be a Post Office Box address, please enter a valid address
        ];
        //address optional 
        if (!empty($orderAddress['region'])) { //State or province of the address,1-50 characters long
            $data['state'] = $orderAddress['region'];
        }
        if (!empty($orderAddress['postal_code'])) { //Postcode of the address, 1-50 characters long
            $data['postcode'] = $orderAddress['postal_code'];
        }
        $addressData = [
            'first_name'   => $orderAddress['first_name'],
            'last_name'    => $orderAddress['last_name'],
            'address'      => $data
        ];
        if (!empty($orderAddress['phone'])) { //
            $addressData['phone_number'] = $orderAddress['phone'];
        }
        if (!empty($orderAddress['email'])) { //文档无该参数,加入也无效
            $addressData['email'] = $orderAddress['email'];
        }
        return $addressData;
    }
    /**
     * 客户端设备信息
     */
    protected function deviceData(Order $Order)
    {
        $card = $Order['credit_card_data'];
        $device = $Order['device_data'];
        $deviceData = [
            'device_id' => $card['threeds_id'],
            'language' => $device['language'],
            'screen_color_depth' => $device['screen_color_depth'],
            'screen_height' => $device['screen_height'],
            'screen_width' => $device['screen_width'],
            'timezone' => $device['timezone'],
        ];
        $browser = [
            'java_enabled' => $device['java_enabled'],
            'javascript_enabled' => true,
            'user_agent' => $Order['user_agent']
        ];
        $deviceData['browser'] = $browser;
        return $deviceData;
    }

    protected function request($url, $data = [])
    {
        $merchant = $this->merchant;
        $headers = [];
        $headers['Content-Type'] = "application/json; charset=utf-8";
        $token = $this->getToken();
        if (empty($token)) {
            $headers['x-api-key'] = $merchant['api_key'];
            $headers['x-client-id'] = $merchant['client_id'];
        } else {
            $headers['region'] = 'string';
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $res = Request::create(new RequestCreate([
            'type' => 'post',
            'url' => $url,
            'headers' => $headers,
            'data' => $data,
            'log' => $this->log
        ]));
        return $res;
    }
}
