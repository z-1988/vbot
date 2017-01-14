<?php
/**
 * Created by PhpStorm.
 * User: Hanson
 * Date: 2016/12/9
 * Time: 21:10
 */

namespace Hanson\Robot\Core;


use Endroid\QrCode\QrCode;
use GuzzleHttp\Client;
use Hanson\Robot\Collections\Account;
use Hanson\Robot\Collections\ContactFactory;
use Hanson\Robot\Collections\Group;
use Hanson\Robot\Support\Console;
use Hanson\Robot\Support\ObjectAble;
use QueryPath\Exception;
use Symfony\Component\DomCrawler\Crawler;

class Server
{

    use ObjectAble;

    static $instance;

    protected $uuid;

    protected $redirectUri;

    public $skey;

    public $sid;

    public $uin;

    public $passTicket;

    public $deviceId;

    public $baseRequest;

    public $syncKey;

    public $syncKeyStr;

    public $config;

    public $messageHandler;

    protected $debug = false;

    const BASE_URI = 'https://wx.qq.com/cgi-bin/mmwebwx-bin';

    const BASE_HOST = 'wx.qq.com';

    public function __construct($config = [])
    {
        $this->config = $config;

        $this->config['debug'] = $this->config['debug'] ?? false;
    }

    /**
     * @param array $config
     * @return Server
     */
    public static function getInstance($config = [])
    {
        if(!static::$instance){
            static::$instance = new Server($config);
        }

        return static::$instance;
    }

    /**
     * start a wechat trip
     */
    public function run()
    {
        $this->prepare();
        $this->init();
        Console::log('[INFO] init success!');

        $this->statusNotify();
        Console::log('[INFO] begin to init contacts');
        $this->initContact();
        Console::log('[INFO] init contacts success!');

        MessageHandler::getInstance()->listen();
    }

    public function prepare()
    {
        $this->getUuid();
        $this->generateQrCode();
        Console::log('[INFO] please scan qrcode to login');

        $this->waitForLogin();
        $this->login();
        Console::log('[INFO] login success!');
    }

    /**
     * get uuid
     *
     * @throws \Exception
     */
    protected function getUuid()
    {
        $content = http()->get('https://login.weixin.qq.com/jslogin', [
            'appid' => 'wx782c26e4c19acffb',
            'fun' => 'new',
            'lang' => 'zh_CN',
//            '_' => time() * 1000 . random_int(1, 999)
            '_' => time()
        ]);

        preg_match('/window.QRLogin.code = (\d+); window.QRLogin.uuid = \"(\S+?)\"/', $content, $matches);

        if(!$matches){
            throw new \Exception('fail to get uuid');
        }

        $this->uuid = $matches[2];
    }

    /**
     * generate a login qrcode
     */
    public function generateQrCode()
    {
        $url = 'https://login.weixin.qq.com/l/' . $this->uuid;

        $qrCode = new QrCode($url);

        $file = $this->config['tmp'] . 'login_qr_code.png';

        $qrCode->save($file);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            system($file);
        }
    }

    /**
     * waiting user to login
     *
     * @throws \Exception
     */
    protected function waitForLogin()
    {
        $retryTime = 10;
        $tip = 1;

        while($retryTime > 0){
            $url = sprintf('https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?tip=%s&uuid=%s&_=%s', $tip, $this->uuid, time());

            $content = http()->get($url);

            preg_match('/window.code=(\d+);/', $content, $matches);

            $code = $matches[1];
            switch($code){
                case '201':
                    Console::log('[INFO] please confirm to login');
                    $tip = 0;
                    break;
                case '200':
                    preg_match('/window.redirect_uri="(\S+?)";/', $content, $matches);
                    $this->redirectUri = $matches[1] . '&fun=new';
                    Console::log('登录URL:'.$this->redirectUri);
                    return;
                case '408':
                    Console::log('[ERROR] login timeout. please try 1 second later.');
                    $tip = 1;
                    $retryTime -= 1;
                    sleep(1);
                    break;
                default:
                    Console::log("[ERROR] login fail. exception code：$code . please try 1 second later.");
                    $tip = 1;
                    $retryTime -= 1;
                    sleep(1);
                    break;
            }
        }

        throw new \Exception('[ERROR] login fail!');
    }

    /**
     * login wechat
     * @return bool
     * @throws \Exception
     */
    public function login()
    {
        $content = http()->get($this->redirectUri);

        $data = (array)simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);

        $this->skey = $data['skey'];
        $this->sid = $data['wxsid'];
        $this->uin = $data['wxuin'];
        $this->passTicket = $data['pass_ticket'];

        if(in_array('', [$this->skey, $this->sid, $this->uin, $this->passTicket])){
            throw new \Exception('[ERROR] login fail!');
        }

        $this->deviceId = 'e' .substr(mt_rand().mt_rand(), 1, 15);

        $this->baseRequest = [
            'Uin' => intval($this->uin),
            'Sid' => $this->sid,
            'Skey' => $this->skey,
            'DeviceID' => $this->deviceId
        ];

        return true;
    }

    protected function init($first = true)
    {
        $url = sprintf(self::BASE_URI . '/webwxinit?r=%d', time());

        $content = http()->json($url, [
            'BaseRequest' => $this->baseRequest
        ]);
        
        $result = json_decode($content, true);
        $this->generateSyncKey($result, $first);

        myself()->init($result['User']);

        $this->initContactList($result['ContactList']);

        if($result['BaseResponse']['Ret'] != 0){
//            print_r($this->baseRequest);

//            Console::log('init URL:'. $url);
            throw new Exception('[ERROR] init fail!');
        }
    }

    protected function initContactList($contactList)
    {
        if($contactList){
            foreach ($contactList as $contact) {
                if(Group::isGroup($contact['UserName'])){
                    group()->put($contact['UserName'], $this->toObject($contact));
                }
            }
        }
    }

    protected function initContact()
    {
        new ContactFactory();
    }

    /**
     * open wechat status notify
     */
    protected function statusNotify()
    {
        $url = sprintf(self::BASE_URI . '/webwxstatusnotify?lang=zh_CN&pass_ticket=%s', $this->passTicket);

        http()->json($url, [
            'BaseRequest' => $this->baseRequest,
            'Code' => 3,
            'FromUserName' => myself()->username,
            'ToUserName' => myself()->username,
            'ClientMsgId' => time()
        ]);
    }

    protected function generateSyncKey($result, $first)
    {
        $this->syncKey = $result['SyncKey'];

        $syncKey = [];

        if(is_array($this->syncKey['List'])){
            foreach ($this->syncKey['List'] as $item) {
                $syncKey[] = $item['Key'] . '_' . $item['Val'];
            }
        }elseif($first){
            $this->init(false);
        }

        $this->syncKeyStr = implode('|', $syncKey);
    }

    public function setMessageHandler(\Closure $closure)
    {
        if(!is_callable($closure)){
            throw new \Exception('[ERROR] message handler must be a closure!');
        }

        MessageHandler::getInstance()->setMessageHandler($closure);
    }

    public function setCustomerHandler(\Closure $closure)
    {
        if(!is_callable($closure)){
            throw new \Exception('[ERROR] message handler must be a closure!');
        }

        MessageHandler::getInstance()->setCustomHandler($closure);
    }

    public function debug($debug = true)
    {
        $this->debug = $debug;

        return $this;
    }
}