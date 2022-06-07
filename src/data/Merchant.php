<?php

namespace Netflying\Airwallex\data;

use Netflying\Payment\data\Merchant as MerchantModel;

/**
 * 支付通道基础数据结构
 */
class Merchant extends MerchantModel
{
    protected $apiAccount = [
        'client_id' => 'string',
        'api_key' => 'string',
        'publishable_key' => 'string',
        'webhook_secret' => 'string',
    ];
    protected $apiAccountNull = [
        'client_id' => null,
        'api_key' => null,
        'publishable_key' => null,
        'webhook_secret' => null,
    ];
    protected $apiData = [
        /**
         * API请求的URL
         * /api/v1/pa/payment_intents/create
         */
        'endpoint' => 'string',
        /**
         * sandbox: https://pci-api-demo.airwallex.com
         * live: https://pci-api.airwallex.com
         */
        'endpoint_domain' => 'string',
        /**
         * 请求后确认地址
         * '/api/v1/pa/payment_intents/{$id}/confirm'
         */
        'endpoint_confirm' => 'string',
        /**
         * token url
         * /api/v1/authentication/login
         * token 一般20分钟失效，在此期间可以缓存起来
         */
        'token_url' => 'string',
        /**
         * 注册指纹设备session_id所需的org_id
         * https://h.online-metrix.net/fp/tags.js?org_id=<org ID>&session_id=<session ID>
         */
        'org_id' => 'string',
        /**
         * 提交完成跳回地址,支持sn={$sn}变量
         * 无notify_url 返回地址，再去获取订单状态信息
         */
        'return_url' => 'string',
        //成功
        'success_url' => 'string',
        //取消
        'cancel_url' => 'string',
    ];
    protected $apiDataNull = [
        'endpoint'   => null,
        'endpoint_domain' => null,
        'token_url' => null,
        'org_id' => null,
        'return_url' => null,
        'success_url' => null,
        'cancel_url' => null,
    ];
}
