<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

$gatewayModuleName = 'epay';
##生成签名
function getSign($params, $key)
{
    // 按照参数名ASCII码从小到大排序
    ksort($params);
    $signStr = '';
    foreach ($params as $k => $v) {
        if ($v == '' || $k == 'sign' || $k == 'sign_type') continue;
        $signStr .= $k . '=' . $v . '&';
    }
    $signStr = rtrim($signStr, '&');
    // 拼接商户密钥
    $signStr .= $key;
    // 生成签名
    $sign = md5($signStr);
    return $sign;
}
// 获取网关配置
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// 获取请求参数
$receivedData = $_GET;

// 必要参数
$pid = $receivedData['pid'];
$trade_no = $receivedData['trade_no'];
$out_trade_no = $receivedData['out_trade_no'];
$type = $receivedData['type'];
$name = $receivedData['name'];
$money = $receivedData['money'];
$trade_status = $receivedData['trade_status'];
$sign = $receivedData['sign'];
$sign_type = $receivedData['sign_type'];

// 验证签名
$signData = $receivedData;
unset($signData['sign'], $signData['sign_type']);
$expectedSign = getSign($signData, $gatewayParams['key']);

if ($sign !== $expectedSign) {
    logTransaction($gatewayParams['name'], $receivedData, '签名验证失败');
    die('签名验证失败');
}

if ($trade_status == 'TRADE_SUCCESS') {
    echo 'success'; // 返回success表示通知已处理
    // 验证发票ID
    $invoiceId = checkCbInvoiceID($out_trade_no, $gatewayParams['name']);
    // 检查交易是否已处理
    checkCbTransID($trade_no);
    // 添加支付记录
    $paymentAmount = $money;
    $transactionId = $trade_no;
    $paymentFeeRate = $gatewayParams['handlingFee'] / 100;
    $paymentFee = round($paymentAmount * $paymentFeeRate, 2); // 保留两位小数
    addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName);
    // 记录交易日志
    logTransaction($gatewayParams['name'], $receivedData, '成功');
} else {
    logTransaction($gatewayParams['name'], $receivedData, '交易未成功');
    die('交易未成功');
}