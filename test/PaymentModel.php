<?php
/**
 * User: Fermin
 * Date: 2017/08/15
 * Time: 10:00
 */

namespace app\common\model;

use think\Model;

class PaymentModel extends Model
{

    /**
     * @param array $config
     * @param array $data
     *                          payment_way值含义
     *                                  alipay_app 支付宝App
     *                                  alipay_h5 支付宝H5
     *                                  alipay_pc 支付宝PC
     *                                  wxpay_app 微信App
     *                                  wxpay_h5 微信H5
     *                                  wxpay_small 微信小程序
     *                                  wxpay_native 微信扫码付
     *
     *                                  当为支付宝H5以及支付宝PC唤起支付时直接输出返回参数内data的数据即可
     *                                  data参数内为from表单代码，它会自动提交
     *
     *                          order_type 区分订单类型，便于异步通知时处理订单
     *
     * @return array
     * @throws \Exception
     */
    public function payment($config, $data)
    {

        if (!is_array($data)) {
            return ['code' => '-101', 'msg' => '参数错误', 'data' => []];
        }

        $payment_name = $data['payment_name'];
        $payment_amount = $data['payment_amount'];
        $out_sn = $data['out_sn'];
        $payment_way = $data['payment_way'];
        $order_type = $data['order_type'];

        //文字过长微信唤起支付会失败
        if (mb_strlen($payment_name) > 90) {
            if ($order_type == '01') {
                $payment_name = '充值订单';//可根据订单类型分别设置支付名称
            }
        }

        header("Pragma:no-cache");
        //开始进入支付
        $response = false;
        //异步通知原样返回数据
        $notify_data = ['order_type' => $order_type, 'payment_way' => $payment_way];
        switch ($payment_way) {
            //支付宝H5
            case 'alipay_h5':
                $aop = new \fyflzjz\paypal\AlipayAop\AopClient($config);
                $request = new \fyflzjz\paypal\AlipayAop\request\AlipayTradeWapPayRequest();
                $setBizContent = json_encode(
                    [
                        'body'            => $payment_name,
                        'subject'         => $payment_name,
                        'out_trade_no'    => $out_sn,
                        'timeout_express' => '24h',//该笔订单允许的最晚付款时间，逾期将关闭交易
                        'total_amount'    => $payment_amount,//订单总金额
                        'product_code'    => 'QUICK_WAP_WAY',
                        'passback_params' => urlencode(http_build_query($notify_data)),//回传参数，支付宝会在异步通知时将该参数原样返回。本参数必须进行UrlEncode之后才可以发送给支付宝
                    ]
                );
                $request->setBizContent($setBizContent);

                //同步通知地址
                $request->setReturnUrl('同步通知地址，带http/https');

                //异步通知地址
                $request->setNotifyUrl('异步通知地址，带http/https');

                $response = $aop->pageExecute($request);

                break;

            //支付宝App
            case 'alipay_app':
                $aop = new \fyflzjz\paypal\AlipayAop\AopClient($config);
                $request = new \fyflzjz\paypal\AlipayAop\request\AlipayTradeAppPayRequest();
                $setBizContent = json_encode(
                    [
                        'body'            => $payment_name,
                        'subject'         => $payment_name,
                        'out_trade_no'    => $out_sn,
                        'timeout_express' => '24h',//该笔订单允许的最晚付款时间，逾期将关闭交易
                        'total_amount'    => $payment_amount,//订单总金额
                        'product_code'    => 'QUICK_MSECURITY_PAY',
                        'passback_params' => urlencode(http_build_query($notify_data)),//回传参数，支付宝会在异步通知时将该参数原样返回。本参数必须进行UrlEncode之后才可以发送给支付宝
                    ]
                );
                $request->setBizContent($setBizContent);

                $request->setNotifyUrl('异步通知地址，带http/https');

                //这里和普通的接口调用不同，使用的是sdkExecute
                $response = $aop->sdkExecute($request);

                break;

            //支付宝PC
            case 'alipay_pc':
                $aop = new \fyflzjz\paypal\AlipayAop\AopClient($config);
                $request = new \fyflzjz\paypal\AlipayAop\request\AlipayTradePagePayRequest();
                $setBizContent = json_encode(
                    [
                        'body'            => $payment_name,
                        'subject'         => $payment_name,
                        'out_trade_no'    => $out_sn,
                        'timeout_express' => '24h',//该笔订单允许的最晚付款时间，逾期将关闭交易
                        'total_amount'    => $payment_amount,//订单总金额
                        'product_code'    => 'FAST_INSTANT_TRADE_PAY',
                        'passback_params' => urlencode(http_build_query($notify_data)),//回传参数，支付宝会在异步通知时将该参数原样返回。本参数必须进行UrlEncode之后才可以发送给支付宝
                    ]
                );

                $request->setBizContent($setBizContent);

                //同步通知地址
                $request->setReturnUrl('同步通知地址，带http/https');

                //异步通知地址
                $request->setNotifyUrl('异步通知地址，带http/https');

                $response = $aop->pageExecute($request);

                break;

            //微信App
            case 'wxpay_app':
                $total_fee = $payment_amount * 100;//支付金额

                $attach = urlencode(http_build_query($notify_data));//附加数据，在查询API和支付通知中原样返回

                $notify_url = '填写自己的支付异步通知地址，带http/https';

                $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);

                $trade_type = 'APP';
                $prepay_data = $weiXinPay->getPrepayId($trade_type, $payment_name, $out_sn, $total_fee, $attach, false, $notify_url);

                if ($prepay_data['result_code'] == 'SUCCESS' && $prepay_data['return_code'] == 'SUCCESS') {
                    //获取支付参数
                    $response = $weiXinPay->createAppPayData($prepay_data['prepay_id']);
                    $response['packageValue'] = 'Sign=WXPay';
                } else {
                    //获取prepay_id失败,请重试
                    $prepay_data['支付方式'] = 'wxpay_app';
                }

                break;

            //微信H5
            case 'wxpay_h5':
                $total_fee = $payment_amount * 100;//支付金额

                $attach = urlencode(http_build_query($notify_data));//附加数据，在查询API和支付通知中原样返回

                $notify_url = '填写自己的支付异步通知地址，带http/https';

                $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);

                //1.获取用户openid
                $oauth = $weiXinPay->getOauth($data);
                $openId = $oauth['openid'];
                if ($openId) {
                    //获取prepay_id
                    $trade_type = 'JSAPI';
                    $prepay_data = $weiXinPay->getPrepayId($trade_type, $payment_name, $out_sn, $total_fee, $attach, false, $notify_url, $openId);
                    if ($prepay_data['result_code'] == 'SUCCESS' && $prepay_data['return_code'] == 'SUCCESS') {
                        //获取支付参数
                        $response = $weiXinPay->getJsApiPay($prepay_data['prepay_id']);
                    } else {
                        //获取prepay_id失败,请重试
                        $prepay_data['支付方式'] = 'wxpay_h5';
                    }
                }
                break;

            //微信小程序
            case 'wxpay_small':
                $total_fee = $payment_amount * 100;//支付金额

                $attach = urlencode(http_build_query($notify_data));//附加数据，在查询API和支付通知中原样返回

                $notify_url = '填写自己的支付异步通知地址，带http/https';

                $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);

                //1.获取用户openid
                $openId = $data['open_id'];
                if ($openId) {
                    //获取prepay_id
                    $trade_type = 'JSAPI';
                    $prepay_data = $weiXinPay->getPrepayId($trade_type, $payment_name, $out_sn, $total_fee, $attach, false, $notify_url, $openId);
                    if ($prepay_data['result_code'] == 'SUCCESS' && $prepay_data['return_code'] == 'SUCCESS') {
                        $response = $weiXinPay->getJsApiPay($prepay_data['prepay_id']);
                    } else {
                        //获取prepay_id失败,请重试
                        $prepay_data['支付方式'] = 'wxpay_h5';
                    }
                }
                break;

            //微信扫码付
            case 'wxpay_native':
                $total_fee = $payment_amount * 100;//支付金额

                $attach = urlencode(http_build_query($notify_data));//附加数据，在查询API和支付通知中原样返回

                $notify_url = '填写自己的支付异步通知地址，带http/https';

                $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);

                $trade_type = 'NATIVE';
                $prepay_data = $weiXinPay->getPrepayId($trade_type, $payment_name, $out_sn, $total_fee, $attach, false, $notify_url);

                if ($prepay_data['result_code'] == 'SUCCESS' && $prepay_data['return_code'] == 'SUCCESS') {
                    $response = $prepay_data;
                } else {
                    //获取prepay_id失败,请重试
                    $prepay_data['支付方式'] = 'wxpay_native';
                }

                break;

            default :
                return ['code' => '-102', 'msg' => '参数错误', 'data' => []];
        }

        if ($response) {
            return ['code' => '200', 'msg' => '请求成功', 'data' => $response];
        } else {
            return ['code' => '-103', 'msg' => '请求失败', 'data' => $prepay_data];
        }
    }

    /**
     * 查询支付结果
     *
     * @param array  $config
     * @param string $payment_way
     *                               alipay_app 支付宝App
     *                               alipay_h5 支付宝H5
     *                               alipay_pc 支付宝PC
     *                               wxpay_app 微信App
     *                               wxapy_h5 微信H5
     *                               wxpay_small 微信小程序
     *                               wxpay_native 微信扫码付
     * @param string $out_sn         商户订单号
     * @param string $trade_no       第三方交易流水号
     * @param string $payment_amount 交易金额
     *
     * @return array
     * @throws \Exception
     */
    public function searchPaymentResult($config, $payment_way, $out_sn = '', $trade_no = '')
    {
        if (!in_array($payment_way, ['alipay_app', 'alipay_h5', 'alipay_pc', 'wxpay_app', 'wxapy_h5', 'wxpay_small']) || ($out_sn == '' && $trade_no == '')) {
            return ['code' => '-1', 'msg' => '交易不存在', 'data' => ''];
        }

        //交易查询
        switch ($payment_way) {
            case 'alipay_h5' : //支付宝H5
            case 'alipay_app' : //支付宝App
            case 'alipay_pc' : //支付宝PC
                $aop = new \fyflzjz\paypal\AlipayAop\AopClient($config);
                $request = new \fyflzjz\paypal\AlipayAop\request\AlipayTradeQueryRequest();
                if ($out_sn) {
                    $bizContent = ['out_trade_no' => $out_sn];
                } else {
                    $bizContent = ['trade_no' => $trade_no];
                }
                $setBizContent = json_encode($bizContent);
                $request->setBizContent($setBizContent);
                $result = $aop->execute($request);

                //解析返回数据
                $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
                /*
                 * code
                 *  10000  业务处理成功
                 *  其他code   失败
                 */
                $resultCode = $result->$responseNode->code;
                if (!empty($resultCode) && $resultCode == 10000) {

                    /*
                     * trade_status
                     *  TRADE_SUCCESS   交易支付成功
                     *  TRADE_FINISHED  交易结束，不可退款
                     *  WAIT_BUYER_PAY  交易创建，等待买家付款
                     *  TRADE_CLOSED    未付款交易超时关闭，或支付完成后全额退款
                     */
                    $resultTradeStatus = $result->$responseNode->trade_status;
                    switch ($resultTradeStatus) {
                        //支付成功
                        case 'TRADE_SUCCESS':
                        case 'TRADE_FINISHED':
                            $return = ['code' => '200', 'msg' => '支付成功', 'data' => $result->$responseNode];
                            break;

                        default:
                            $return = ['code' => '3', 'msg' => '交易创建但未支付', 'data' => $result->$responseNode];
                            break;
                    }

                    return $return;
                } else {
                    return ['code' => '-1', 'msg' => '交易不存在', 'data' => $result->$responseNode];
                }
                break;

            case 'wxpay_app' : //微信App
            case 'wxpay_h5' : //微信H5
            case 'wxpay_small' : //微信小程序
                $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);
                $result = $weiXinPay->orderQuery($out_sn, $trade_no);
                /*
                 * return_code  状态码
                 *      SUCCESS/FAIL
                 * result_code  业务结果
                 *      SUCCESS/FAIL
                 * trade_state  交易状态
                 *     SUCCESS—支付成功
                 *     REFUND—转入退款
                 *     NOTPAY—未支付
                 *     CLOSED—已关闭
                 *     REVOKED—已撤销（刷卡支付）
                 *     USERPAYING--用户支付中
                 *     PAYERROR--支付失败(其他原因，如银行返回失败)
                 *
                 */
                //只要查询到交易信息就返回true
                if ($result && $result['return_code'] == 'SUCCESS' && $result['return_code'] == 'SUCCESS') {
                    switch ($result['trade_state']) {
                        //支付成功
                        case 'SUCCESS':
                        case 'REFUND':
                            $return = ['code' => '200', 'msg' => '支付成功', 'data' => $result];
                            break;

                        //支付中状态
                        case 'USERPAYING':
                            $return = ['code' => '2', 'msg' => '交易创建等待支付', 'data' => $result];
                            break;

                        default :
                            $return = ['code' => '3', 'msg' => '未支付', 'data' => $result];
                            break;
                    }

                    return $return;
                } else {
                    return ['code' => '-1', 'msg' => '交易不存在', 'data' => $result];
                }
                break;
            default :
                $return = ['code' => '-1', 'msg' => '交易不存在', 'data' => ''];
                break;
        }

        return $return;
    }

    /**
     * 支付宝单笔退款查询
     *
     * @param $config
     * @param $param
     *
     * @return array
     */
    public function aliPaySearchRefund($config, $param)
    {
        if (!is_array($param)) {
            return ['code' => '-1', 'msg' => '参数错误', 'data' => ''];
        }

        $aop = new \fyflzjz\paypal\AlipayAop\AopClient($config);
        $request = new \fyflzjz\paypal\AlipayAop\request\AlipayTradeFastpayRefundQueryRequest();
        $setBizContent = json_encode(
            [
                'out_trade_no'   => $param['out_sn'],
                'trade_no'       => $param['trade_no'],
                'out_request_no' => $param['batch_no'],
            ]
        );
        $request->setBizContent($setBizContent);
        $result = $aop->execute($request);

        //解析返回数据
        $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
        $result = json_decode(json_encode($result->$responseNode), true);

        if ($result['code'] == '10000' && isset($result['out_trade_no']) && $result['out_trade_no'] == $param['out_sn'] && $result['refund_amount'] == $param['refund_amount']) {
            /*
             * 退款成功result返回数据如下
                [code] => 10000
                [msg] => Success
                [out_request_no] => HZ01RF002
                [out_trade_no] => 41201708151521281912630721
                [refund_amount] => 0.01
                [total_amount] => 0.04
                [trade_no] => 2017081521001004500235698318
             */
            return ['code' => '200', 'msg' => '退款成功', 'data' => $result];
        } else {
            return ['code' => '2', 'msg' => '不存在退款', 'data' => $result];
        }
    }

    /**
     * 支付宝单笔退款
     *
     * @param $config
     * @param $param
     *
     * @return array
     */
    public function aliPayRefund($config, $param)
    {
        if (!is_array($param)) {
            return ['code' => '-1', 'msg' => '参数错误', 'data' => ''];
        }

        $aop = new \fyflzjz\paypal\AlipayAop\AopClient($config);
        $request = new \fyflzjz\paypal\AlipayAop\request\AlipayTradeRefundRequest();
        $setBizContent = json_encode(
            [
                'out_trade_no'   => $param['out_sn'],
                'trade_no'       => $param['trade_no'],
                'refund_amount'  => $param['refund_amount'],
                'refund_reason'  => $param['refund_reason'],
                'out_request_no' => $param['batch_no'],
            ]
        );
        $request->setBizContent($setBizContent);
        $result = $aop->execute($request);

        //解析返回数据
        $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
        $result = json_decode(json_encode($result->$responseNode), true);

        if ($result['code'] == '10000') {
            /*
             * 退款成功时result数据如下
                [code] => 10000
                [msg] => Success
                [buyer_logon_id] => 494***@qq.com
                [buyer_user_id] => 2088402040506506
                [fund_change] => N
                [gmt_refund_pay] => 2017-08-15 15:23:58
                [open_id] => 20881025156365107817916371818250
                [out_trade_no] => 41201708151521281912630721
                [refund_fee] => 0.01
                [send_back_fee] => 0.00
                [trade_no] => 2017081521001004500235698318
             */
            return ['code' => '200', 'msg' => '退款成功', 'data' => $result];
        } else {
            return ['code' => '-2', 'msg' => '退款失败', 'data' => $result];
        }
    }

    /**
     * 微信单笔退款查询
     *
     * @param $config
     * @param $param
     *
     * @return array
     */
    public function wxSearchRefund($config, $param)
    {
        if (!is_array($param)) {
            return ['code' => '-1', 'msg' => '参数错误', 'data' => ''];
        }

        //查询微信退款
        $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);

        $result = $weiXinPay->refundQuery($param['refund_no'], $param['batch_no'], $param['trade_no'], $param['out_sn']);
        if ($result && $result['return_code'] == 'SUCCESS' && $result['result_code'] == 'SUCCESS') {
            return ['code' => '200', 'msg' => '退款成功', 'data' => $result];
        } else {
            return ['code' => '2', 'msg' => '不存在退款', 'data' => $result];
        }
    }

    /**
     * 微信单笔退款
     *
     * @param $config
     * @param $param
     *
     * @return array
     */
    public function wxRefund($config, $param)
    {
        if (!is_array($param)) {
            return ['code' => '-1', 'msg' => '参数错误', 'data' => ''];
        }

        //查询微信退款
        $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);

        $result = $weiXinPay->send($param['trade_no'], $param['total_fee'] * 100, $param['refund_amount'] * 100);
        if ($result && $result['return_code'] == 'SUCCESS' && $result['result_code'] == 'SUCCESS') {
            return ['code' => '200', 'msg' => '退款成功', 'data' => $result];
        } else {
            return ['code' => '-2', 'msg' => '退款失败', 'data' => $result];
        }
    }

    /**
     * 查询对账单下载地址
     *
     * @param $config
     * @param $param
     *
     * @return array
     */
    public function getBillDownload($config, $param)
    {
        if (!is_array($param)) {
            return ['code' => '-1', 'msg' => '参数错误', 'data' => ''];
        }

        if (in_array($param['payment_way'], ['alipay_app', 'alipay_h5', 'alipay_pc'])) {

            $aop = new \fyflzjz\paypal\AlipayAop\AopClient($config);
            $request = new \fyflzjz\paypal\AlipayAop\request\AlipayDataDataserviceBillDownloadurlQueryRequest();
            $setBizContent = json_encode(['bill_type' => 'trade', 'bill_date' => $param['ali_date']]);
            $request->setBizContent($setBizContent);
            $result = $aop->execute($request);

            //解析返回数据
            $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
            $result = json_decode(json_encode($result->$responseNode), true);

            if ($result['code'] == '10000') {
                //下载对账单
            }

        } elseif (in_array($param['payment_way'], ['wxpay_app', 'wxapy_h5', 'wxpay_small'])) {
            $weiXinPay = new \fyflzjz\paypal\Wxpay\WxPay($config);
            $result = $weiXinPay->downloadBill($param['wx_date']);

            //下载对账单

        }

        if ($result) {
            return ['code' => '200', 'msg' => '请求成功', 'data' => $result];
        } else {
            return ['code' => '-2', 'msg' => '数据为空', 'data' => ''];
        }

    }


}
