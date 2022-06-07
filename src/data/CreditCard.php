<?php

namespace Netflying\Airwallex\data;

use Netflying\Payment\data\CreditCard as Model;

/**
 *  信用卡数据
 *
 */

class CreditCard extends Model
{

    protected $reference = [
        //3ds sessionId设备会话id
        'threeds_id' => 'string',
        //wp 卡信息密文,默认必须,有值表示走站内直付
        'encrypt_data' => 'string',
    ];
    protected $referenceNull = [
        'threeds_id' => null,
        'encrypt_data' => null,
    ];

}
