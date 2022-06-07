<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-07 10:44:57
 */

namespace Netflying\AirwallexTest;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request;
use Netflying\PaymentTest\Data;
use Netflying\Payment\common\Openssl;

use Netflying\Worldpay\data\Merchant;

use Netflying\Payment\data\CreditCard;
use Netflying\Payment\data\CreditCardSsl;
use Netflying\Payment\data\RequestCreate;

class Airwallex
{

    protected $url = '';

    public $type = 'Airwallex';

    protected $merchant = [];

    protected $creditCard = [];

    protected $CreditCardSsl = [];

    /**
     * @param $url 回调通知等相对路径
     *
     * @param string $url 站点回调通知相对路径
     */
    public function __construct($url = '')
    {
        $this->url = $url;
    }

    /**
     * 商家数据结构
     *
     * @return this
     */
    public function setMerchant(array $realMerchant = [])
    {
        $url = $this->url . '?type=' . $this->type;
        $returnUrl = $url . '&act=return_url&async=0&sn={$sn}';
        $successUrl = $url . '&act=success_url&async=0&sn={$sn}';
        $cancelUrl = $url . '&act=cancel_url&async=0&sn={$sn}';
        $merchant = [
            'type' => $this->type,
            'is_test' => 1,
            'merchant' => '****',
            'api_account' => [
                'client_id' => '*****',
                'api_key' => '*****',
                'publishable_key' => '*****',
                'webhook_secret' => '*****',
            ],
            'api_data' => [
                'endpoint'   => '/api/v1/pa/payment_intents/create',
                'endpoint_domain' => 'https://pci-api-demo.airwallex.com',
                'token_url' => '/api/v1/authentication/login',
                'org_id' => '******',
                'return_url' => $returnUrl,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]
        ];
        $merchant = Utils::arrayMerge($merchant, $realMerchant);
        $this->merchant = $merchant;
        return $this;
    }
    public function setCreditCard()
    {
        $this->creditCard = new CreditCard([
            'card_number'    => '4000000000000002',
            'expiry_month'   => '12',
            'expiry_year'    => '2025',
            'cvc'            => '123',
            'holder_name'    => 'join jack',
            'reference' => [
                'threeds_id' => 'asdfasdfasdfasdf',
                'encrypt_data' => 'adsfasdfasdfasdfadsf',
            ]
        ]);
        return $this;
    }
    public function setCreditCardSsl()
    {
        $this->setCreditCard();
        $card = $this->creditCard;
        $this->creditCardSsl = new CreditCardSsl([
            'encrypt' => Openssl::encrypt($card)
        ]);
        return $this;
    }

    /**
     * 提交支付
     *
     * @return Redirect
     */
    public function pay()
    {
        $Data = new Data;
        $Order = $Data->order();
        //设置卡信息
        $this->setCreditCardSsl();
        $Order->setCreditCard($this->creditCardSsl);
        $Log = new Log;
        $Merchant = new Merchant($this->merchant);
        $class = "Netflying\\" . $this->type . "\\lib\\" . $this->type;
        $Payment = new $class($Merchant);
        $redirect = $Payment->log($Log)->purchase($Order);
        return $redirect;
    }
}
