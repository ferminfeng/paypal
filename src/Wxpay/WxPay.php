<?php

namespace fyflzjz\paypal\Wxpay;

ini_set('date.timezone', 'Asia/Shanghai');
error_reporting(E_ERROR);

use fyflzjz\paypal\Wxpay\lib\WxPayApi;
use fyflzjz\paypal\Wxpay\lib\WxPayConfig;
use fyflzjz\paypal\Wxpay\lib\WxPayException;
use fyflzjz\paypal\Wxpay\lib\WxPayDataBase;

/**
 *
 * https://pay.weixin.qq.com/wiki/doc/api/index.html
 *
 * APP支付
 * https://pay.weixin.qq.com/wiki/doc/api/app/app.php?chapter=9_1
 *
 * 微信内H5调起支付
 * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=9_1
 *
 * 微信外H5调起支付
 * https://pay.weixin.qq.com/wiki/doc/api/H5.php?chapter=9_20&index=1
 *
 * 扫码支付
 * https://pay.weixin.qq.com/wiki/doc/api/native.php?chapter=9_1
 *
 * 小程序支付
 * https://pay.weixin.qq.com/wiki/doc/api/wxa/wxa_api.php?chapter=9_1
 */
class WxPay
{

    /**
     * WxPay constructor.
     *
     * @param array $config
     */
    public function __construct($config)
    {
        //初始化配置
        $wxPayConfig = new WxPayConfig();
        $wxPayConfig->getConfig($config);
    }

    /**
     * 返回结果给微信服务器
     *
     * @param $data
     */
    public static function resultXmlToWx($data)
    {
        WxPayApi::resultXmlToWx($data);
        exit();
    }

    /**
     * 统一下单
     *
     * @param string $trade_type 支付类型 APP:app支付 JSAPI:网页支付 NATIVE:扫码支付
     * @param string $body
     * @param int    $out_sn
     * @param        $total_fee
     * @param string $attach
     * @param bool   $is_recharge
     * @param string $notify_url
     * @param        $open_id
     *
     * @return array
     */
    public function getPrepayId($trade_type, $body, $out_sn, $total_fee, $attach = '', $is_recharge = false, $notify_url = '', $open_id = '')
    {

        //构造要请求的参数
        $input = new WxPayDataBase();

        //支付类型 APP:app支付 JSAPI:网页支付 NATIVE:扫码支付
        $input->SetTrade_type($trade_type);

        $input->SetBody($body);

        $input->SetOut_trade_no($out_sn);

        $input->SetTotal_fee($total_fee);

        //附加数据，在查询API和支付通知中原样返回
        $input->SetAttach($attach);

        //判定充值不允许使用信用卡
        if ($is_recharge) {
            $input->SetLimit_Pay("no_credit");
        }

        //异步通知url
        $input->SetNotify_url($notify_url);

        $input->SetTime_start(date("YmdHis"));

        //网页支付需要open_id
        if ($trade_type == 'JSAPI') {
            $input->SetOpenid($open_id);
        }

        //扫码支付需要product_id
        if ($trade_type == 'NATIVE') {
            $input->SetProduct_id($out_sn);
        }

        $wxPayApi = new WxPayApi();
        $result = $wxPayApi->unifiedOrder($input);
        return $result;
    }

    /**
     * 创建APP支付参数
     *
     * @param $prepay_id
     *
     * @return array
     */
    public function createAppPayData($prepay_id)
    {
        $array = [
            'appid'     => APPID,
            'noncestr'  => WxPayApi::getNonceStr(),
            'package'   => 'Sign=WXPay',
            'partnerid' => MCHID,
            'prepayid'  => $prepay_id,
            'timestamp' => (string)time(),
        ];

        $array['sign'] = $this->AppMakeSign($array);
        unset($array['appkey']);

        return $array;
    }

    /**
     * 生成签名，本函数不覆盖sign成员变量，如要设置签名需要调用SetSign方法赋值
     *
     * @param $array
     *
     * @return string
     */
    private function AppMakeSign($array)
    {
        //签名步骤一：按字典序排序参数
        ksort($array);
        $string = $this->AppToUrlParams($array);
        //签名步骤二：在string后加入KEY
        $string = $string . "&key=" . KEY;
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);

        return $result;
    }

    /**
     * 格式化参数格式化成url参数
     *
     * @param $array
     *
     * @return string
     */
    private function AppToUrlParams($array)
    {
        $buff = "";
        foreach ($array as $k => $v) {
            if ($k != "sign" && $v != "" && !is_array($v)) {
                $buff .= $k . "=" . $v . "&";
            }
        }

        $buff = trim($buff, "&");

        return $buff;
    }

    /**
     *
     * 通过跳转获取用户的openid，跳转流程如下：
     * 1、设置自己需要调回的url及其其他参数，跳转到微信服务器https://open.weixin.qq.com/connect/oauth2/authorize
     * 2、微信服务处理完成之后会跳转回用户redirect_uri地址，此时会带上一些参数，如：code
     *
     * 网页授权接口微信服务器返回的数据，返回样例如下
     * {"access_token":"ACCESS_TOKEN","expires_in":7200,"refresh_token":"REFRESH_TOKEN","openid":"OPENID","scope":"SCOPE","unionid":"o6_bmasdasdsad6_2sgVt7hMZOPfL"}
     * 其中access_token可用于获取共享收货地址 openid是微信支付jsapi支付接口必须的参数
     *
     * @param $data
     *
     * @return array
     */
    public function getOauth($data)
    {
        //通过code获得openid
        if (!isset($_GET['code'])) {
            //触发微信返回code码
            $baseUrl = urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
            $url = $this->createOauthUrlForCode($baseUrl);
            $url = str_replace("STATE", json_encode($data, JSON_UNESCAPED_UNICODE), $url);
            Header("Location: $url");
            exit();
        } else {
            //获取code码，以获取openid
            $code = $_GET['code'];
            $data = $this->getOpenidFromMp($code);

            return $data;
        }
    }

    /**
     * 构造获取code的url连接
     *
     * @param string $redirectUrl 微信服务器回跳的url，需要url编码
     *
     * @return string 返回构造好的url
     */
    private function createOauthUrlForCode($redirectUrl)
    {
        $urlObj["appid"] = APPID;
        $urlObj["redirect_uri"] = "$redirectUrl";
        $urlObj["response_type"] = "code";
        $urlObj["scope"] = "snsapi_base";
        $urlObj["state"] = "STATE" . "#wechat_redirect";
        $bizString = $this->AppToUrlParams($urlObj);

        return "https://open.weixin.qq.com/connect/oauth2/authorize?" . $bizString;
    }

    /**
     * 通过code从工作平台获取openid、access_token
     *
     * @param string $code 微信跳转回来带上的code
     *
     * @return array
     */
    private function getOpenidFromMp($code)
    {
        $url = $this->createOauthUrlForOpenid($code);
        //初始化curl
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (CURL_PROXY_HOST != "0.0.0.0"
            && CURL_PROXY_PORT != 0
        ) {
            curl_setopt($ch, CURLOPT_PROXY, CURL_PROXY_HOST);
            curl_setopt($ch, CURLOPT_PROXYPORT, CURL_PROXY_PORT);
        }
        //运行curl，结果以jason形式返回
        $res = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($res, true);

        return $data;
    }

    /**
     * 构造获取open和access_toke的url地址
     *
     * @param string $code ，微信跳转带回的code
     *
     * @return string 请求的url
     */
    private function createOauthUrlForOpenid($code)
    {
        $urlObj["appid"] = APPID;
        $urlObj["secret"] = APPSECRET;
        $urlObj["code"] = $code;
        $urlObj["grant_type"] = "authorization_code";
        $bizString = $this->AppToUrlParams($urlObj);

        return "https://api.weixin.qq.com/sns/oauth2/access_token?" . $bizString;
    }

    /**
     * 获取jsapi支付的参数
     *
     * @param $prepay_id
     *
     * @return string
     */
    public function getJsApiPay($prepay_id)
    {
        $timeStamp = time();

        $input = new WxPayDataBase();
        $input->SetAppid(APPID);
        $input->SetTimeStamp("$timeStamp");
        $input->SetNonceStr(WxPayApi::getNonceStr());
        $input->SetPackage("prepay_id=" . $prepay_id);
        $input->SetSignType("MD5");
        $input->SetPaySign($input->MakeSign());
        $parameters = $input->GetValues();

        return $parameters;
    }

    /**
     * 获取共享收货地址js函数需要的参数，json格式可以直接做参数使用
     *
     * @return string
     */
    public function getEditAddressParameters($access_token)
    {
        $data = [];
        $data["appid"] = APPID;
        $data["url"] = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $time = time();
        $data["timestamp"] = "$time";
        $data["noncestr"] = "1234568";
        $data["accesstoken"] = $access_token;
        ksort($data);
        $params = $this->AppToUrlParams($data);
        $addrSign = sha1($params);

        $afterData = [
            "addrSign"  => $addrSign,
            "signType"  => "sha1",
            "scope"     => "jsapi_address",
            "appId"     => APPID,
            "timeStamp" => $data["timestamp"],
            "nonceStr"  => $data["noncestr"],
        ];
        $parameters = json_encode($afterData);

        return $parameters;
    }

    /**
     * 查询订单
     *
     * @param $out_sn
     * @param $trade_no
     *
     * @return array
     */
    public function orderQuery($out_sn, $trade_no)
    {
        //构造要请求的参数
        $input = new WxPayDataBase();

        //通过商户订单号查询
        if ($out_sn != '') {
            $input->SetOut_trade_no($out_sn);
        }

        //通过支付流水号查询
        if ($out_sn == '' && $trade_no != '') {
            $input->SetTransaction_id($trade_no);
        }

        $result = WxPayApi::orderQuery($input);

        return $result;
    }

    /**
     * 退款
     *
     * @param $transaction_id
     * @param $total_fee
     * @param $refund_fee
     *
     * @return lib\成功时返回，其他抛异常
     * @throws WxPayException
     */
    public function send($transaction_id, $total_fee, $refund_fee)
    {
        //构造要请求的参数
        $input = new WxPayDataBase();
        $input->SetTransaction_id($transaction_id);
        $input->SetTotal_fee($total_fee);
        $input->SetRefund_fee($refund_fee);
        $input->SetOut_refund_no(MCHID . date("YmdHis"));
        $input->SetOp_user_id(MCHID);
        //$result = $this->printf_info(WxPayApi::refund($input));
        $result = WxPayApi::refund($input);

        return $result;
    }

    /**
     * 退款查询
     *
     * @param $refund_no
     * @param $batch_no
     * @param $trade_no
     * @param $out_sn
     *
     * @return array
     */
    public function refundQuery($refund_no, $batch_no, $trade_no, $out_sn)
    {
        //构造要请求的参数
        $input = new WxPayDataBase();

        if (!empty($refund_no)) {
            //通过微信退款单号查询
            $input->SetRefund_id($refund_no);
        } elseif (!empty($batch_no)) {
            //通过商户退款单号查询
            $input->SetOut_refund_no($batch_no);
        } elseif (!empty($trade_no)) {
            //通过微信订单号查询
            $input->SetTransaction_id($trade_no);
        } elseif (!empty($out_sn)) {
            //通过商户订单号查询
            $input->SetOut_trade_no($out_sn);
        }

        $result = WxPayApi::refundQuery($input);

        return $result;
    }

    /**
     * 异步通知
     *
     * @return array|bool
     */
    public function check_notify()
    {
        //构造要请求的参数
        $msg = '';
        $result = WxPayApi::notify($msg);

        return $result;
    }

    /**
     * 下载对账单
     *
     * @param $data
     *
     * @return array
     */
    public function downloadBill($data)
    {
        //构造要请求的参数
        $input = new WxPayDataBase();
        //对账单日期
        $input->SetBill_date($data);

        //账单类型
        /*
        ALL，返回当日所有订单信息，默认值
        SUCCESS，返回当日成功支付的订单
        REFUND，返回当日退款订单
        RECHARGE_REFUND，返回当日充值退款订单（相比其他对账单多一栏“返还手续费”）
         */
        $input->SetBill_type('ALL');
        $result = WxPayApi::downloadBill($input);

        return $result;
    }

}

?>