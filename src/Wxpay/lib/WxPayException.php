<?php

namespace fyflzjz\paypal\Wxpay\lib;

/**
 *
 * 微信支付API异常类
 * @author widyhu
 *
 */
class WxPayException extends \Exception
{

    public function errorMessage()
    {
        return $this->getMessage();
    }
}
