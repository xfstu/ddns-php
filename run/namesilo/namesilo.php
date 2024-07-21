<?php

use GuzzleHttp\Client;

$linux_path = str_replace('\\', '/', '/../vendor/autoload.php');
include_once __DIR__ . $linux_path;
date_default_timezone_set('Asia/Shanghai');
function get($url, $test = false)
{
  if ($test) {
    echo "debug模式：url=" . $url . "\n";
    return;
  }
  $client = new Client();
  $response = $client->request('GET', $url);
  return $response->getBody();
}

class DDns
{
  private $domain;
  private $key;
  private $subDomian = [];
  private $networkCard;
  private $confPaht = '/home/config';

  function __construct()
  {
    $this->setConfig();
  }

  private function setConfig()
  {
    if (!file_exists($this->confPaht . '/config.json')) {
      $mkdir = mkdir($this->confPaht, 0777, true);
      if ($mkdir == false) {
        echo '创建文件夹失败';
        sleep(10);
        return $this->setConfig();
      }
      $init = [
        "networkCard" => "enp1s0",
        "domain" => null,
        "key" => null,
        "subDomian" => [
          [
            "type" => "AAAA",
            "host" => "cname",
            "ttl" => 3600
          ]
        ]
      ];
      file_put_contents($this->confPaht . '/config.json', json_encode($init));
      return $this->setConfig();
    }
    $config = json_decode(file_get_contents($this->confPaht . '/config.json'), true);
    if (!$config['domain'] || !$config['key']) {
      echo "请先配置域名或key\n";
      sleep(10);
      return $this->setConfig();
    }
    $this->domain = $config['domain'];
    $this->key = $config['key'];
    $this->subDomian = $config['subDomian'];
    $this->networkCard = $config['networkCard'];
    echo "配置成功：\n域名：{$this->domain};\nkey：{$this->key}；\n网络卡：{$this->networkCard}；\n";
    foreach ($this->subDomian as $row) {
      echo "主机：{$row['host']}，类型：{$row['type']}，TTL：{$row['ttl']}。\n";
    }
  }


  public function getAddress()
  {
    $networkCard = $this->networkCard;
    $command = "ip -6 addr show $networkCard | grep 'inet6 ' | awk '{print $2}' | cut -d'/' -f1";
    exec($command, $ipAddrs);
    $isIp = '127.0.0.1';
    foreach ($ipAddrs as $ip) {
      if (substr($ip, 0, 1) == '2') {
        $isIp = $ip;
      }
    }
    #echo "当前主机IP：$isIp \n";
    return $isIp;
  }

  private function checkHost($host, $type)
  {
    $subDomian = $this->subDomian;
    foreach ($subDomian as $row) {
      if ($row['host'] . '.' . $this->domain == $host && $row['type'] == $type) {
        return $row;
      }
    }
  }

  public function getDns()
  {
    $response = get("https://www.namesilo.com/api/dnsListRecords?version=1&type=xml&key={$this->key}&domain={$this->domain}");
    $body = $response;
    $xml = simplexml_load_string($body);
    $records = $xml->reply->resource_record;
    $cache = [];
    foreach ($records as $row) {
      $cache[] = $row;
    }
    if (count($cache) == 0) {
      echo "从服务商获取失败，正在重新获取\n";
      sleep(3);
      return $this->getDns();
    }
    file_put_contents($this->confPaht  . '/cache.json', json_encode($cache));
    echo "从服务商获取配置成功，路径：{$this->confPaht}/cache.json\n";
    return json_decode(json_encode($cache), true);
  }
  public function check()
  {
    //先检查缓存
    $cache = null;
    if (file_exists($this->confPaht .  '/cache.json')) {
      $cache = json_decode(file_get_contents($this->confPaht .  '/cache.json'), true);
    }
    if ($cache) {
      $array = $cache;
    } else {
      $array = $this->getDns();
    }
    $ip = $this->getAddress();
    $updateArray = [];
    foreach ($array as $row) {
      $updateHost = $this->checkHost($row['host'], $row['type']);
      if ($updateHost && $row['value'] != $ip) {
        $updateRow = $row;
        $updateRow['host'] = $updateHost['host'];
        $updateArray[] = $updateRow;
      }
    }
    return $updateArray;
  }

  public function start()
  {
    $updateArray = $this->check();

    if (function_exists('fastcgi_finish_request')) {
      // fastcgi_finish_request(); //FPM模式下使用，非阻塞运行
    }
    if ($updateArray) {
      echo "当前时间：" . date('Y-m-d H:i:s') . "\n";
      foreach ($updateArray as $row) {
        $rrid = $row['record_id'];
        $host = $row['host'];
        $addr = $this->getAddress();
        get("https://www.namesilo.com/api/dnsUpdateRecord?version=1&type=xml&key={$this->key}&domain={$this->domain}&rrid=" . $rrid . '&rrhost=' . $host . '&rrvalue=' . $addr . '&rrttl=3600', false);
        echo "【{$host}】 已更新IP：$addr\n";
      }
      //更新完了就删除缓存
      unlink($this->confPaht . '/cache.json');
      echo "全部subdomain更新完毕，30分钟后重新检查。\n";
    } else {
      echo "当前时间：" . date('Y-m-d H:i:s') . "。没有更新IP，30分钟后重新检查。\n";
    }
    sleep(1800);
    return $this->start();
  }
}

$ddns = new DDns();
$ddns->start();
