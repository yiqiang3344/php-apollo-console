<?php

class ApolloClient
{
    /** @var Log */
    protected $logger; //日志处理对象
    protected $configServer; //apollo服务端地址
    protected $appId; //apollo配置项目的appid
    protected $cluster = 'default';
    protected $clientIp = '127.0.0.1'; //绑定IP做灰度发布用
    protected $notifications = [];
    protected $pullTimeout = 10; //获取某个namespace配置的请求超时时间
    protected $intervalTimeout = 70; //每次请求获取apollo配置变更时的超时时间

    /**
     * ApolloClient constructor.
     * @param string $configServer apollo服务端地址
     * @param string $appId apollo配置项目的appid
     * @param array $namespaces apollo配置项目的namespace
     */
    public function __construct($configServer, $appId, array $namespaces, $logger = null)
    {
        $this->configServer = $configServer;
        $this->appId = $appId;
        $this->logger = $logger;
        foreach ($namespaces as $namespace) {
            $this->notifications[$namespace] = ['namespaceName' => $namespace, 'notificationId' => -1];
        }
    }

    protected function log($messageTag, string $message)
    {
        $logData = [
            'time' => date('Y-m-d H:i:s'),
            'message_tag' => $messageTag,
            'message' => $message,
            'server' => $this->configServer,
            'cluster' => $this->cluster,
            'client_ip' => $this->clientIp,
            'pull_timeout' => $this->pullTimeout,
            'interval_timeout' => $this->intervalTimeout,
            'app_id' => $this->appId,
            'notifications' => $this->notifications,
        ];
        if ($this->logger instanceof Log) {
            $this->logger->log($logData);
        } else {
            echo json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }

    public function setCluster($cluster)
    {
        $this->cluster = $cluster;
    }

    public function setClientIp($ip)
    {
        $this->clientIp = $ip;
    }

    public function setPullTimeout($pullTimeout)
    {
        $pullTimeout = intval($pullTimeout);
        if ($pullTimeout < 1 || $pullTimeout > 300) {
            return;
        }
        $this->pullTimeout = $pullTimeout;
    }

    public function setIntervalTimeout($intervalTimeout)
    {
        $intervalTimeout = intval($intervalTimeout);
        if ($intervalTimeout < 1 || $intervalTimeout > 300) {
            return;
        }
        $this->intervalTimeout = $intervalTimeout;
    }

    //获取多个namespace的配置-无缓存的方式
    public function pullConfigBatch(array $namespaceNames)
    {
        if (!$namespaceNames) return [];
        $multi_ch = curl_multi_init();
        $request_list = [];
        $base_url = rtrim($this->configServer, '/') . '/configs/' . $this->appId . '/' . $this->cluster . '/';
        $query_args = [];
        $query_args['ip'] = $this->clientIp;
        foreach ($namespaceNames as $namespaceName) {
            $request = [];
            $request_url = $base_url . $namespaceName;
            $query_string = '?' . http_build_query($query_args);
            $request_url .= $query_string;
            $ch = curl_init($request_url);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->pullTimeout);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $request['ch'] = $ch;
            $request_list[$namespaceName] = $request;
            curl_multi_add_handle($multi_ch, $ch);
        }

        $active = null;
        // 执行批处理句柄
        do {
            $mrc = curl_multi_exec($multi_ch, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_ch) == -1) {
                usleep(100);
            }
            do {
                $mrc = curl_multi_exec($multi_ch, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        }

        // 获取结果
        $response_list = [];
        foreach ($request_list as $namespaceName => $req) {
            $response_list[$namespaceName] = true;
            $result = curl_multi_getcontent($req['ch']);
            $code = curl_getinfo($req['ch'], CURLINFO_HTTP_CODE);
            $error = curl_error($req['ch']);
            curl_multi_remove_handle($multi_ch, $req['ch']);
            curl_close($req['ch']);
            if ($code == 200) {
                $result = json_decode($result, true);
                $response_list[$namespaceName] = $result;
            } elseif ($code != 304) {
                $this->log('pull_config_error', 'pull config of namespace[' . $namespaceName . '] error:' . ($result ?: $error));
                $response_list[$namespaceName] = false;
            }
        }
        curl_multi_close($multi_ch);
        return $response_list;
    }

    protected function _listenChange(&$ch, $callback = null)
    {
        $base_url = rtrim($this->configServer, '/') . '/notifications/v2?';
        $params = [];
        $params['appId'] = $this->appId;
        $params['cluster'] = $this->cluster;
        do {
            $params['notifications'] = json_encode(array_values($this->notifications));
            $query = http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $base_url . $query);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            if ($httpCode == 200) {
                $res = json_decode($response, true);
                $change_list = [];
                foreach ($res as $r) {
                    if ($r['notificationId'] != $this->notifications[$r['namespaceName']]['notificationId']) {
                        $change_list[$r['namespaceName']] = $r['notificationId'];
                    }
                }
                $response_list = $this->pullConfigBatch(array_keys($change_list));
                $change_result = [];
                foreach ($response_list as $namespaceName => $result) {
                    $result && ($this->notifications[$namespaceName]['notificationId'] = $change_list[$namespaceName]);
                    $result && $change_result[] = $result;
                }
                //如果定义了配置变更的回调，比如重新整合配置，则执行回调
                ($callback instanceof \Closure) && call_user_func($callback, [$change_result]);
            } elseif ($httpCode != 304) {
                throw new \Exception($response ?: $error);
            }
        } while (true);
    }

    /**
     * @param callable $callback 监听到配置变更时的回调处理
     * @return string
     */
    public function start($callback = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->intervalTimeout);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        try {
            $this->_listenChange($ch, $callback);
        } catch (\Exception $e) {
            curl_close($ch);
            return $e->getMessage();
        }
    }
}
