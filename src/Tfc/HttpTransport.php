<?php

namespace Tfcenv\Tfc;

interface HttpTransport
{
    /**
     * HTTP ステータスは呼び手が解釈する。ここが投げるのは
     * 接続できなかった場合だけ。
     *
     * @throws TfcException 名前解決失敗・接続拒否・タイムアウトなど
     */
    public function send(HttpRequest $request): HttpResponse;
}
