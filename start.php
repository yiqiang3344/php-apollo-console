<?php

require_once './ApolloClient.php';
require_once './Log.php';

if (!function_exists('pcntl_fork')) die('PCNTL functions not available on this PHP installation');

$configServer = 'http://docker.for.mac.localhost:8080';
$cluster = 'default'; //集群配置
$clientIp = '127.0.0.1'; //客户端ip，用于灰度发布
$pullTimeout = 10; //获取某个namespace配置的请求超时时间
$intervalTimeout = 70; //每次请求获取apollo配置变更时的超时时间
$logPath = '/var/log/apollo'; //apollo日志目录

$systemAppId = 'system'; //系统配置应用
$systemAplicationNamespaces = 'default.json'; //系统配置命名空间
$configPath = '/data/appoloConfig'; //统一保存配置的目录

$logger = Log::instance($logPath);
$client = new ApolloClient($configServer, $systemAppId, [$systemAplicationNamespaces], $logger);
$client->setCluster($cluster);
$client->setClientIp($clientIp);
$client->setPullTimeout($pullTimeout);
$client->setIntervalTimeout($intervalTimeout);
$pids = [];

$masterPid = posix_getpid();

//先监听system应用配置命名空间，获取要监听的系统及命名空间配置列表
$ret = $client->start(function ($results) use (&$pids, $configServer, $configPath, $cluster, $clientIp, $pullTimeout, $intervalTimeout, $logger, $masterPid) {
    $logger->log([
        'message_tag' => 'master_process_update',
        'message' => '应用配置更新',
        'config' => json_encode($results, JSON_UNESCAPED_UNICODE),
        'pid' => $masterPid,
    ]);
    $applications = json_decode($results[0][0]['configurations']['content'], true);
    foreach ($applications as $_appId => $_namespaces) {
        //已有子进程的系统配置有变更，则结束子进程
        if (isset($pids[$_appId])) {
            $logger->log([
                'message_tag' => 'sub_process_kill',
                'message' => '有变更结束进程',
                'app_id' => $_appId,
                'sub_pid' => $pids[$_appId],
                'pid' => $masterPid,
            ]);
            posix_kill($pids[$_appId], SIGKILL);
            unset($pids[$_appId]);
        }

        //命名空间列表为空则表示不再同步配置
        if (empty($_namespaces)) {
            continue;
        }

        //fork子进程来监听配置更新
        $_pid = pcntl_fork();
        if ($_pid == -1) {
            throw new Exception('fork error');
        }
        if (!$_pid) {
            $mypid = posix_getpid();
            $tmpNamespaces = [];
            foreach ($_namespaces as $_namespace) {
                $tmpNamespaces[] = $_namespace;
            }
            $client = new ApolloClient($configServer, $_appId, $tmpNamespaces, $logger);
            $client->setCluster($cluster);
            $client->setClientIp($clientIp);
            $client->setPullTimeout($pullTimeout);
            $client->setIntervalTimeout($intervalTimeout);
            $ret = $client->start(function ($results) use ($_appId, $configPath, $logger, $mypid, $masterPid) {
                $dir = $configPath . '/' . $_appId;
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775);
                }
                foreach ($results[0] as $row) {
                    if (strpos($row['namespaceName'], '.json') !== false) {
                        $content = '<?php' . PHP_EOL . 'return ' . var_export(json_decode($row['configurations']['content'], true), true) . ';';
                        $fileName = str_replace('.json', '.php', $row['namespaceName']);
                    } elseif (strpos($row['namespaceName'], '.txt') !== false) {
                        $content = $row['configurations']['content'];
                        $fileName = str_replace('.txt', '.php', $row['namespaceName']);
                    } else {
                        continue;
                    }
                    $filePath = $dir . '/' . $fileName;
                    file_put_contents($filePath, $content);
                    $logger->log([
                        'message_tag' => 'sub_process_update',
                        'message' => '配置[' . $filePath . ']更新',
                        'config' => $content,
                        'app_id' => $_appId,
                        'sub_pid' => $mypid,
                        'pid' => $masterPid,
                    ]);
                }
            });
            $logger->log([
                'message_tag' => 'sub_process_error',
                'message' => $ret,
                'app_id' => $_appId,
                'sub_pid' => $mypid,
                'pid' => $masterPid,
            ]);
            exit;
        }
        $pids[$_appId] = $_pid;
    }
});
$logger->log([
    'message_tag' => 'master_process_error',
    'message' => $ret,
    'pid' => $masterPid,
]);
foreach ($pids as $pid) {
    posix_kill($pid, SIGKILL);
}