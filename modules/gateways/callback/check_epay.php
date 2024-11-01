<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 引入WHMCS的必要文件
require_once '../../../init.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

// 获取POST参数
$invoiceId = $_POST['invoiceid'] ?? '';
$payType = $_POST['payType'] ?? '';

// 检查参数是否存在
if (empty($invoiceId)) {
    echo json_encode(array('status' => 'error', 'message' => '缺少参数 invoiceid'));
    exit;
}

if (empty($payType)) {
    echo json_encode(array('status' => 'error', 'message' => '缺少参数 payType'));
    exit;
}

// 验证 payType 是否为允许的值
$allowedPayTypes = array('alipay', 'wxpay');
if (!in_array($payType, $allowedPayTypes)) {
    echo json_encode(array('status' => 'error', 'message' => '无效的支付方式'));
    exit;
}
$gatewayModuleName = "epay";
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams["type"]) {
    echo json_encode(array('status' => 'error', 'message' => '支付模块未激活'));
    exit;
}

// 获取商户配置
$pid = $gatewayParams['pid'];
$key = $gatewayParams['key'];
$gatewayUrl = rtrim($gatewayParams['gatewayUrl'], '/');

// 构建查询参数
$parameter = array(
    'sign_type' => 'MD5',
);
$sign = getSign($parameter, $key);
$parameter['sign'] = $sign;

// 发送查询请求
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $gatewayUrl . '/api.php?act=order&pid='.$pid.'&out_trade_no='.$invoiceId.'&key='.$key);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameter));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if ($response === false) {
    $curlError = curl_error($ch);
    echo json_encode(array('status' => 'error', 'message' => 'cURL Error: ' . $curlError));
    curl_close($ch);
    exit;
}
curl_close($ch);

// 处理返回结果
$result = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(array('status' => 'error', 'message' => 'JSON解析错误: ' . json_last_error_msg()));
    exit;
}

if ($result['code'] == 1) {
    $status = $result['status']; // 支付状态，1为支付成功，0为未支付
    if ($status == 1) {
        // 支付成功，更新订单状态
        $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams["name"]);
        $transactionId = $result['trade_no'];
        $paymentAmount = $result['money'];
        $paymentFeeRate = $gatewayParams['handlingFee'] / 100;
        $paymentFee = round($paymentAmount * $paymentFeeRate, 2); // 保留两位小数
        echo json_encode(array('status' => 'success', 'message' => '支付成功，订单已更新'));
        // 检查交易是否已经处理过
        if (!checkCbTransID($transactionId)) {
            // 添加支付记录
            addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName);
            logTransaction($gatewayParams["name"], $result, "Successful");
        }
    } else {
        // 支付未完成
        echo json_encode(array('status' => 'pending', 'message' => '支付未完成，请稍后再试'));
    }
} else {
    // 查询失败
    echo json_encode(array('status' => 'error', 'message' => '查询失败：' . $result['msg']));
}

/**
 * 生成签名函数
 */
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
?>