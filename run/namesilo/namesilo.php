<?php

use GuzzleHttp\Client;

$linux_path = str_replace('\\', '/', '/../vendor/autoload.php');
include_once __DIR__ . $linux_path;
date_default_timezone_set('Asia/Shanghai');
function get($url, $test = false)
{
  if ($test) {
    // echo $url;
    return;
  }
  $client = new Client();
  $response = $client->request('GET', $url);
  return $response->getBody();
}

class DDns
{
  public $address;

  private $domain;
  private $key;
  private $subDomian;
  private $networkCard;
  private $index=4;
  private $timestamp=0;

  function __construct()
  {
    if (!file_exists('/home/config.json')) {
      echo "未找到config.json，请放至/home目录下 \n";
      sleep(10);
      $ddns = new DDns();
      $ddns->start();
    }
    $config = json_decode(file_get_contents('/home/config.json'), true);
    $this->domain = $config['domain'];
    $this->key = $config['key'];
    $this->subDomian = $config['subDomian'];
    $this->networkCard = $config['networkCard'];
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
    $this->address = $isIp;
    echo "当前主机IP：$isIp \n";
    return $isIp;
  }

  public function getDns()
  {
    $response = get("https://www.namesilo.com/api/dnsListRecords?version=1&type=xml&key={$this->key}&domain={$this->domain}");
    $body = $response;
    $xml = simplexml_load_string($body);
    $ipList = $xml->reply->resource_record;
    $array = [];
    $updateDomain = $this->subDomian;
    foreach ($ipList as $row) {
      if (in_array($row->host, $updateDomain) && $row->type == 'AAAA') {
        $host = explode('.', $row->host);
        $row->host = $host[0];
        $array[] = json_decode(json_encode($row), true);
      }
    }
    file_put_contents(__DIR__ . '/cache.json', json_encode($array));
    return $array;
  }
  public function check()
  {
    //先检查缓存
    $cache = null;
    if (file_exists(__DIR__ . '/cache.json')) {
      $cache = json_decode(file_get_contents(__DIR__ . '/cache.json'), true);
    }
    if ($cache) {
      $array = $cache;
    } else {
      $array = $this->getDns();
    }
    $ip = $this->getAddress();
    $updateArray = [];
    foreach ($array as $row) {
      if ($row['value'] != $ip) {
        $updateArray[] = $row;
      }
    }
    return $updateArray;
  }
  public function start()
  {
    $this->index++;
    $updateArray = $this->check();

    if (function_exists('fastcgi_finish_request')) {
      // fastcgi_finish_request(); //FPM模式下使用，非阻塞运行
    }
    if ($updateArray) {
      echo "当前时间：" . date('Y-m-d H:i:s') . "。新IP：" . $this->address . "\n";
      foreach ($updateArray as $row) {
        $rrid = $row['record_id'];
        $host = $row['host'];
        $addr = $this->getAddress();
        get("https://www.namesilo.com/api/dnsUpdateRecord?version=1&type=xml&key={$this->key}&domain={$this->domain}&rrid=" . $rrid . '&rrhost=' . $host . '&rrvalue=' . $addr . '&rrttl=3600', false);
        echo "$host 更新成功\n";
      }
      //更新完了就删除缓存
      unlink(__DIR__ . '/cache.json');
    }
    if ($this->index == 0) {
      echo date('Y-m-d H:i:s')." 更新完毕，休息24小时\n";
      sleep(86400);
      return $this->start();
    }
    if ($this->index == 1) {
      echo date('Y-m-d H:i:s')." 1天了还没有更新，休息12小时\n";
      sleep(43200);
      return $this->start();
    }
    if ($this->index >= 2 && $this->index <4) {
      echo date('Y-m-d H:i:s')." 1.5天还没有更新，间隔6小时，查询2次\n";
      sleep(21600);
      return $this->start();
    }
    if ($this->index >= 4 && $this->index <28) {
      echo date('Y-m-d H:i:s')." 2天还没有更新，间隔1小时，查询24次\n";
      sleep(3600);
      return $this->start();
    }
    if ($this->index >= 28) {
      echo date('Y-m-d H:i:s')." 3天还没有更新，1分钟查询1次\n";
      sleep(60);
      return $this->start();
    }
  }
}

$ddns = new DDns();
$ddns->start();
