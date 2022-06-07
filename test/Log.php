<?php

namespace Netflying\AirwallexTest;
use Netflying\Payment\lib\LogInterface;

class Log implements LogInterface
{
    public function save($request,$response) 
    {
        $data = [
            'request' => $request,
            'response' => $response
        ];
        @file_put_contents(__DIR__.'/../../../../log/airwallex.txt',json_encode($data));
    }
}