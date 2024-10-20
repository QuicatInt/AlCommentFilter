<?php

use Typecho\Widget\Helper\Form\Element\Text;

/**
 * AICommentFilter
 *
 * @package AICommentFilter
 * @version 1.0.0
 * @author QuicatInt
 * @link https://blog.catseek.uk
 */
class AICommentFilter_Plugin implements Typecho_Plugin_Interface
{
    // 用于存储Access Token的缓存文件路径
    private static $tokenCacheFile = __DIR__ . '/token_cache.json';

    /**
     * 插件激活
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('AICommentFilter_Plugin', 'filter');
        return _t('插件已激活，请配置相关参数。');
    }

    /**
     * 插件禁用
     */
    public static function deactivate()
    {
        return _t('插件已禁用。');
    }

    /**

     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {

        $desc = new Text('desc', NULL, '', '插件介绍：', '<p><a href="https://blog.catseek.uk/index.php/archives/35/" target="_blank">插件使用方法</a></p>');
        $form->addInput($desc);
        echo '<script>window.onload = function(){document.getElementsByName("desc")[0].type = "hidden";} </script>'; 

        // 选择审核服务
        $service = new Typecho_Widget_Helper_Form_Element_Radio(
            'service',
            array(
                'baidu' => '百度云',
                'tencent' => '腾讯云',
            ),
            'baidu',  // 缺省选择百度云
            _t('选择内容审核服务'),
            _t('请选择使用百度云或腾讯云进行内容审核')
        );
        $form->addInput($service->addRule('required', _t('请选择一个内容审核服务')));

        // 百度云 API Key
        $ak = new Typecho_Widget_Helper_Form_Element_Text('baidu_ak', null, '', _t('百度API Key'), _t('请填写您的百度智能云内容审核 API Key'));
        $ak->input->setAttribute('class', 'baidu-config');
        $form->addInput($ak);

        // 百度云 Secret Key
        $sk = new Typecho_Widget_Helper_Form_Element_Text('baidu_sk', null, '', _t('百度Secret Key'), _t('请填写您的百度智能云内容审核 Secret Key'));
        $sk->input->setAttribute('class', 'baidu-config');
        $form->addInput($sk);

        // 腾讯云 SecretId
        $tencent_secretId = new Typecho_Widget_Helper_Form_Element_Text('tencent_secretId', null, '', _t('腾讯云 SecretId'), _t('请填写您的腾讯云 SecretId'));
        $tencent_secretId->input->setAttribute('class', 'tencent-config');
        $form->addInput($tencent_secretId);

        // 腾讯云 SecretKey
        $tencent_secretKey = new Typecho_Widget_Helper_Form_Element_Text('tencent_secretKey', null, '', _t('腾讯云 SecretKey'), _t('请填写您的腾讯云 SecretKey'));
        $tencent_secretKey->input->setAttribute('class', 'tencent-config');
        $form->addInput($tencent_secretKey);

        // 腾讯云 Region
        $tencent_region = new Typecho_Widget_Helper_Form_Element_Text('tencent_region', null, 'ap-guangzhou', _t('腾讯云 Region'), _t('请填写您的腾讯云 Region，缺省值为 ap-guangzhou'));
        $tencent_region->input->setAttribute('class', 'tencent-config');
        $form->addInput($tencent_region);

        // 使用自定义JS脚本，根据选择的服务动态显示配置项
        $script = <<<EOT
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function () {
    function toggleFields() {
        var service = document.querySelector('input[name="service"]:checked').value;
        var baiduOptions = document.querySelectorAll('.baidu-config');
        var tencentOptions = document.querySelectorAll('.tencent-config');
        
        if (service === 'baidu') {
            baiduOptions.forEach(function(el) { el.closest('.typecho-option').style.display = ''; });
            tencentOptions.forEach(function(el) { el.closest('.typecho-option').style.display = 'none'; });
        } else {
            baiduOptions.forEach(function(el) { el.closest('.typecho-option').style.display = 'none'; });
            tencentOptions.forEach(function(el) { el.closest('.typecho-option').style.display = ''; });
        }
    }
    
    toggleFields();
    
    document.querySelectorAll('input[name="service"]').forEach(function(input) {
        input.addEventListener('change', toggleFields);
    });
});
</script>
EOT;
echo $script;
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 评论过滤方法
     */
    public static function filter($comment, $post)
    {
        // 获取插件设置
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AICommentFilter');
        $service = $options->service;

        if ($service == 'baidu') {
            // 使用百度智能云内容审核
            $ak = $options->baidu_ak;
            $sk = $options->baidu_sk;

            // 如果未设置API Key或Secret Key，跳过审核
            if (empty($ak) || empty($sk)) {
                return $comment;
            }

            // 获取Access Token
            $accessToken = self::getBaiduAccessToken($ak, $sk);

            // 进行评论内容审核
            $content = $comment['text'];
            $apiUrl = 'https://aip.baidubce.com/rest/2.0/solution/v1/text_censor/v2/user_defined?access_token=' . $accessToken;
            $postData = array('text' => $content);
            $apiResponse = self::httpPost($apiUrl, http_build_query($postData), 'baidu');

            $result = json_decode($apiResponse, true);

            if (!isset($result['conclusionType'])) {
                throw new Typecho_Plugin_Exception(_t('百度内容审核API调用失败: ' . json_encode($result)));
            }

            // 判断审核结果
            if ($result['conclusionType'] != 1) { // 1: 合规
                throw new Typecho_Widget_Exception(_t('评论内容不合规，包含敏感词或违规信息'));
            }

            return $comment;
        } 
        
        elseif ($service == 'tencent') {
            // 使用腾讯云内容审核
            $secretId = $options->tencent_secretId;
            $secretKey = $options->tencent_secretKey;
            $region = $options->tencent_region;

            // 如果未设置SecretId或SecretKey，跳过审核
            if (empty($secretId) || empty($secretKey) || empty($region)) {
                return $comment;
            }

            // 请求参数
            $action = 'TextModeration';
            $version = '2020-12-29';
            $host = 'tms.tencentcloudapi.com';
            $serviceName = 'tms';
            $algorithm = 'TC3-HMAC-SHA256';
            $timestamp = time();
            $date = gmdate('Y-m-d', $timestamp);

            // 腾讯云请求
            $httpRequestMethod = 'POST';
            $canonicalUri = '/';
            $canonicalQueryString = '';
            $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:$host\n";
            $signedHeaders = 'content-type;host';

        
            $payload = json_encode(array(
                'Content' => base64_encode($comment['text']),
            ), JSON_UNESCAPED_UNICODE);

            $hashedRequestPayload = hash('SHA256', $payload);
            $canonicalRequest = "$httpRequestMethod\n$canonicalUri\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$hashedRequestPayload";

            // 腾讯云签名
            $credentialScope = "$date/$serviceName/tc3_request";
            $hashedCanonicalRequest = hash('SHA256', $canonicalRequest);
            $stringToSign = "$algorithm\n$timestamp\n$credentialScope\n$hashedCanonicalRequest";

            $secretDate = hash_hmac('SHA256', $date, 'TC3' . $secretKey, true);
            $secretService = hash_hmac('SHA256', $serviceName, $secretDate, true);
            $secretSigning = hash_hmac('SHA256', 'tc3_request', $secretService, true);
            $signature = hash_hmac('SHA256', $stringToSign, $secretSigning);

            $authorization = "$algorithm Credential=$secretId/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

            // 签名拼接完成


            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json; charset=utf-8',
                'Host' => $host,
                'X-TC-Action' => $action,
                'X-TC-Version' => $version,
                'X-TC-Timestamp' => strval($timestamp),
                'X-TC-Region' => $region,
            );

            $curlHeaders = array();
            foreach ($headers as $key => $value) {
                $curlHeaders[] = $key . ': ' . $value;
            }

            // 请求及响应
            $apiResponse = self::httpPost('https://' . $host, $payload, 'tencent', $curlHeaders);

            $result = json_decode($apiResponse, true);

            // 腾讯云错误处理
            if (isset($result['Response']['Error'])) {
                $errorCode = $result['Response']['Error']['Code'];
                $errorMessage = $result['Response']['Error']['Message'];
                // 错误码列表
                switch ($errorCode) {
                    case 'AuthFailure.SignatureExpire':
                        throw new Typecho_Plugin_Exception(_t('签名过期。请检查服务器时间是否准确，并确保请求的时间戳与服务器时间相差不超过五分钟。'));
                        break;
                    case 'AuthFailure.SecretIdNotFound':
                        throw new Typecho_Plugin_Exception(_t('密钥不存在。请检查您的SecretId是否正确，或者密钥是否被禁用。'));
                        break;
                    case 'AuthFailure.SignatureFailure':
                        throw new Typecho_Plugin_Exception(_t('签名错误。请检查SecretKey是否正确，或签名生成过程是否有误。'));
                        break;
                    case 'AuthFailure.TokenFailure':
                        throw new Typecho_Plugin_Exception(_t('临时证书 Token 错误。请检查您的临时密钥是否正确。'));
                        break;
                    case 'AuthFailure.InvalidSecretId':
                        throw new Typecho_Plugin_Exception(_t('密钥非法。请确保您使用的是有效的云 API 密钥。'));
                        break;
                    case 'InternalError.ErrTextTimeOut':
                        throw new Typecho_Plugin_Exception(_t('请求超时。请稍后重试。'));
                        break;
                    case 'InvalidParameter.ErrAction':
                        throw new Typecho_Plugin_Exception(_t('错误的Action参数。请检查接口调用参数是否正确。'));
                        break;
                    case 'InvalidParameter.ErrTextContentLen':
                        throw new Typecho_Plugin_Exception(_t('请求的文本长度过长。请确保文本长度不超过10000个字符。'));
                        break;
                    case 'InvalidParameter.ErrTextContentType':
                        throw new Typecho_Plugin_Exception(_t('文本类型错误，需要Base64编码的文本。'));
                        break;
                    case 'InvalidParameter.ParameterError':
                        throw new Typecho_Plugin_Exception(_t('参数错误。请检查请求参数是否正确。'));
                        break;
                    case 'InvalidParameterValue.ErrFileContent':
                        throw new Typecho_Plugin_Exception(_t('FileContent不可用，传入的Base64编码无法转换成标准UTF-8内容。'));
                        break;
                    case 'InvalidParameterValue.ErrTextContentLen':
                        throw new Typecho_Plugin_Exception(_t('请求的文本长度超过限制。'));
                        break;
                    case 'InvalidParameterValue.ErrTextContentType':
                        throw new Typecho_Plugin_Exception(_t('请求的文本格式错误，需要Base64编码格式的文本。'));
                        break;
                    case 'RequestLimitExceeded':
                        throw new Typecho_Plugin_Exception(_t('请求次数超过频率限制。请减少请求频率。'));
                        break;
                    case 'UnauthorizedOperation.Unauthorized':
                        throw new Typecho_Plugin_Exception(_t('未开通权限/无有效套餐包/账号已欠费。请检查您的账户状态。'));
                        break;
                    default:
                        // 其他错误
                        throw new Typecho_Plugin_Exception(_t('腾讯云内容审核API调用失败: ' . $errorMessage . ' (错误码: ' . $errorCode . ')'));
                }
            }

            if (!isset($result['Response']['Suggestion'])) {
                throw new Typecho_Plugin_Exception(_t('腾讯云内容审核API调用失败: ' . $apiResponse));
            }

            // 判断审核结果
            $suggestion = $result['Response']['Suggestion'];
            if ($suggestion == 'Block' || $suggestion == 'Review') {
                $label = isset($result['Response']['Label']) ? $result['Response']['Label'] : 'Unknown';
                $keywords = isset($result['Response']['Keywords']) ? implode(', ', $result['Response']['Keywords']) : '';
                $score = isset($result['Response']['Score']) ? $result['Response']['Score'] : 0;

                // 记录详细审核信息到日志
                error_log("腾讯云审核结果: Label=$label, Suggestion=$suggestion, Keywords=$keywords, Score=$score");

                throw new Typecho_Widget_Exception(_t('评论内容不合规，包含敏感词或违规信息'));
            }

            return $comment;
        }
}

        /**
         * 获取百度Access Token
         */
        private static function getBaiduAccessToken($ak, $sk)
        {
            // 检查缓存文件是否存在且未过期
            if (file_exists(self::$tokenCacheFile)) {
                $data = json_decode(file_get_contents(self::$tokenCacheFile), true);
                if (isset($data['access_token']) && $data['expires_in'] > time()) {
                    return $data['access_token'];
                }
            }

            // 获取新的Access Token
            $url = 'https://aip.baidubce.com/oauth/2.0/token';
            $postData = array(
                'grant_type' => 'client_credentials',
                'client_id' => $ak,
                'client_secret' => $sk
            );
            $response = self::httpPost($url, http_build_query($postData), 'baidu');

            // 解析响应
            $result = json_decode($response, true);
            if (isset($result['access_token'])) {
                // 缓存Access Token
                $result['expires_in'] += time(); // 计算过期时间
                file_put_contents(self::$tokenCacheFile, json_encode($result));

                return $result['access_token'];
            } else {
                throw new Typecho_Plugin_Exception(_t('获取百度Access Token失败: ' . $response));
            }
        }

        private static function httpPost($url, $data = '', $service = 'baidu', $additionalHeaders = array())
        {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

            // 设置请求头
            $headers = array();
            if ($service == 'baidu') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } elseif ($service == 'tencent') {
                $headers = array_merge($headers, $additionalHeaders);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // SSL设置
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                $error_msg = '网络请求错误: ' . curl_error($ch);
                error_log($error_msg); // 将错误写入日志
                throw new Typecho_Plugin_Exception(_t($error_msg));
            }

            if ($service == 'tencent') {
                error_log("Tencent API Response: " . $response);
            } elseif ($service == 'baidu') {
                error_log("Baidu API Response: " . $response);
            }

            curl_close($ch);

            return $response;
        }

        private static function buildCanonicalRequest($method, $uri, $queryString, $headers, $signedHeaders, $payload)
        {
            $canonicalHeaders = '';
            foreach ($signedHeaders as $header) {
                $canonicalHeaders .= strtolower($header) . ':' . trim($headers[$header]) . "\n";
            }

            $signedHeadersStr = implode(';', $signedHeaders);
            $hashedPayload = hash('SHA256', $payload);

            $canonicalRequest = "$method\n$uri\n$queryString\n$canonicalHeaders\n$signedHeadersStr\n$hashedPayload";
            return $canonicalRequest;
        }
    }
    ?>
