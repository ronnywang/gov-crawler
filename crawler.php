<?php

class Crawler
{
    protected $_cache = array();

    public function getContentFromURL($url, &$info)
    {
        $timeout = 10;
        $ret = parse_url($url);
        $domain = $ret['host'];
        $hash = crc32($url);
        $target = "html/{$domain}-{$hash}";
        if (file_exists($target)) {
            list($info, $content) = explode("\n", gzdecode(file_get_contents($target)), 2);
            $info = json_decode($info);
            if ($info->http_code == 0) {
                //unlink($target);
            }
        }

        if (!file_exists($target)) {
            error_log('loading ' . $url);
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $content = curl_exec($curl);
            $info = curl_getinfo($curl);
            file_put_contents($target, gzencode(json_encode($info) . "\n" . $content));
        }

        list($info, $content) = explode("\n", gzdecode(file_get_contents($target)), 2);
        $info = json_decode($info);
        if ($info->http_code == 200) {
            return $content;
        }

        return '';
    }

    public function crawl()
    {
        $keys = array_keys(get_object_vars($this->_cache->pendings));
        if (!$keys) {
            return;
        }
        $domain = array_shift($keys);
        $url = 'http://' . $domain;

        $content = self::getContentFromURL($url, $info);

        if (!$content) {
            $url = urldecode($this->_cache->pendings->{$domain});
            $content = self::getContentFromURL($url);
        }

        preg_match('#<title>([^<]*)#', $content, $matches);
        $title = trim(htmlspecialchars_decode($matches[1]));
        if (!json_encode($title)) {
            $title = trim(htmlspecialchars_decode($matches[1]));
            $title = iconv('big5', 'utf-8', $title);
            if (!json_encode($title)) {
                $title = '';
            }
        }
        preg_match('#Server: (.*)#', $content, $matches);
        $title = str_replace("\n", "", $title);
        fputcsv($this->output, array(
            $domain,
            $title,
            $info->primary_ip,
            trim($matches[1]),
        ));
        $this->_cache->result->{$domain} = $title;
        array(
            'title' => $title,
            'url' => $url,
        );
        error_log($domain. ' ' . $title);
        unset($this->_cache->pendings->{$domain});

        preg_match_all('#"https?://[.A-Z0-9a-z-]*(\.gov\.tw|\.taipei\>)[^"]*"#', $content, $matches);
        foreach ($matches[0] as $url) {
            $url = trim($url, '"');
            $url = htmlspecialchars_decode($url);
            $ret = parse_url($url);
            if (strpos($url, '&_blank')) {
                continue;
            }
            $domain = $ret['host'];
            if (property_exists($this->_cache->result, $domain) or property_exists($this->_cache->pendings, $domain)) {
                continue;
            }
            $this->_cache->pendings->{$domain} = urlencode($url);
        }
        $this->saveCache();
    }

    public function saveCache()
    {
        if (!json_encode($this->_cache)) {
            print_r($this->_cache);
            exit;
        }
        error_log(sprintf("pending:%d, result:%d", count(get_object_vars($this->_cache->pendings)), count(get_object_vars($this->_cache->result))));
        file_put_contents('cache.json', json_encode($this->_cache, JSON_UNESCAPED_UNICODE));
    }

    public function main()
    {
        $this->output = fopen('php://output', 'w');
        fputcsv($this->output, array('domain', 'title', 'ip', 'server'));
        if (false and file_exists('cache.json')) {
            $this->_cache = json_decode(file_get_contents('cache.json'));
        } else {
            $this->_cache = new StdClass;
            $this->_cache->pendings = new StdClass;
            $this->_cache->pendings->{'www.gov.tw'} = true;
            $this->_cache->result = new StdClass;
        }

        while (json_encode($this->_cache->pendings) != "{}") {
            $this->crawl();
        }
    }
}

$c = new Crawler;
$c->main();
