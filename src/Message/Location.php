<?php
/**
 * Created by PhpStorm.
 * User: Hanson
 * Date: 2016/12/16
 * Time: 21:13
 */

namespace Hanson\Robot\Message;


class Location extends Message implements MessageInterface
{

    /**
     * @var string 位置链接
     */
    public $url;

    public function __construct($msg)
    {
        parent::__construct($msg);

        $this->make();
    }

    /**
     * 判断是否位置消息
     *
     * @param $content
     * @return bool
     */
    public static function isLocation($content)
    {
        return str_contains($content['Content'], 'webwxgetpubliclinkimg') && $content['Url'];
    }

    /**
     * 设置位置文字信息
     */
    private function setLocationText()
    {
        $result = explode('<br/>', $this->msg['Content']);

        $this->content = substr(current($result), 0, -1);

        $this->url = $this->msg['Url'];
    }

    public function make()
    {
        $this->setLocationText();
    }
}