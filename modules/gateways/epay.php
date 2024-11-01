<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * 模块元数据
 */
function epay_MetaData()
{
    return array(
        'DisplayName' => '易支付',
        'APIVersion' => '1.1',
    );
}

/**
 * 配置项函数
 */
function epay_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => '易支付',
        ),
        'pid' => array(
            'Type' => 'text',
            'FriendlyName' => '商户ID',
            'Description' => '您的支付商户ID',
        ),
        'key' => array(
            'Type' => 'password',
            'FriendlyName' => '商户密钥',
            'Description' => '您的支付商户密钥',
        ),
        'gatewayUrl' => array(
            'Type' => 'text',
            'FriendlyName' => '支付网关地址',
            'Description' => '如：https://epay.xxx.com',
            'Default' => 'https://www.baidu.com',
        ),
        'payType' => array(
            'Type' => 'dropdown',
            'FriendlyName' => '支付方式',
            'Options' => array(
                'alipay' => '支付宝',
                'wxpay' => '微信支付',
            ),
            'Description' => '选择默认的支付方式',
        ),
        'handlingFee' => array(
            'Type' => 'text',
            'FriendlyName' => '手续费率 (%)',
            'Description' => '请输入手续费率，例如 1.5 表示 1.5% 仅用于在财务后台中计算手续费数据，不会加到付款账单里',
            'Default' => '1.5',
        ),
    );
}
##此epay_link是接入mapi对接的，如果该易支付不支持mapi.php对接请不要使用此方法
function epay_link($params)
{
    // 系统设置
    $gatewayUrl = rtrim($params['gatewayUrl'], '/');
    $pid = $params['pid'];
    $key = $params['key'];
    $payType = $params['payType'];

    // 订单信息
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = number_format($params['amount'], 2, '.', '');
    $currency = $params['currency'];
    $returnUrl = $params['returnurl'];
    $notifyUrl = $params['systemurl'] . '/modules/gateways/epay/notify.php';

    // 客户信息
    $clientEmail = $params['clientdetails']['email'];
    $clientIP = $_SERVER['REMOTE_ADDR'];

    // 构建请求参数
    $parameter = array(
        'pid' => $pid,
        'type' => $payType,
        'out_trade_no' => $invoiceId,
        'notify_url' => $notifyUrl,
        'return_url' => $returnUrl,
        'name' => $description,
        'money' => $amount,
        'clientip' => $clientIP,
        'sign_type' => 'MD5',
    );

    // 签名处理
    $sign = epay_getSign($parameter, $key);
    $parameter['sign'] = $sign;

    // 发起支付请求
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $gatewayUrl . '/mapi.php');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameter));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // 处理返回结果
    $result = json_decode($response, true);

    if ($result['code'] == 1) {
        // 根据返回的字段类型处理
        if (!empty($result['payurl'])) {
            // 获取支付URL
            $payUrl = $result['payurl'];
            // 生成跳转按钮
            $htmlOutput = '<a href="' . $payUrl . '" target="_blank">点击前往支付</a>';
        } elseif (!empty($result['qrcode'])) {
            // 获取二维码链接
            $qrcodeUrl = $result['qrcode'];
            // 生成二维码图片
            $htmlOutput = '<p>请使用手机扫码支付：</p>';
            $htmlOutput .= '<img src="' . epay_generateQRCode($qrcodeUrl) . '" alt="扫码支付">';
        } elseif (!empty($result['urlscheme'])) {
            // 获取小程序跳转URL
            $urlScheme = $result['urlscheme'];
            // 生成跳转按钮
            $htmlOutput = '<a href="' . $urlScheme . '" target="_blank">打开微信小程序支付</a>';
        } else {
            // 未知的返回类型
            $htmlOutput = '支付网关返回了未知的支付方式。';
        }

        $htmlOutput .= "
<script>
    // 定义定时器变量
    var checkInterval = setInterval(checkPaymentStatus, 5000); // 每5秒检查一次

    function checkPaymentStatus() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '{$params['systemurl']}/modules/gateways/callback/check_epay.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4) {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        console.log(response.message);
                        if (response.status == 'success') {
                            // 支付成功，停止定时器，刷新页面或跳转
                            clearInterval(checkInterval);
                            alert('支付成功，订单已更新！');
                            window.location.href = '{$params['systemurl']}/viewinvoice.php?id={$params['invoiceid']}';
                        }
                    } catch (e) {
                        console.error('服务器返回了无效的响应。');
                    }
                } else {
                    console.error('请求失败，状态码：' + xhr.status);
                }
            }
        };
        var params = 'invoiceid=' + encodeURIComponent('{$params['invoiceid']}') + '&payType=' + encodeURIComponent('{$params['payType']}');
        xhr.send(params);
    }
</script>
";
    } else {
        // 处理错误
        $htmlOutput = '支付网关请求失败：' . $result['msg'];
    }

    return $htmlOutput;
}

/**
 * 生成签名函数
 */
function epay_getSign($params, $key)
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
function epay_generateQRCode($data)
{
    // 引入PHP QR Code库
    require_once __DIR__ . '/lib/phpqrcode.php';
    // 生成二维码图片
    ob_start();
    QRcode::png($data, null, QR_ECLEVEL_L, 4);
    $imageData = ob_get_contents();
    ob_end_clean();
    // 将图像数据编码为base64
    $base64Image = 'data:image/png;base64,' . base64_encode($imageData);
    return $base64Image;
}