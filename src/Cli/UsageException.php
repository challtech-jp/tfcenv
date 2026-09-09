<?php

namespace Tfcenv\Cli;

/**
 * コマンドラインの書き方が間違っている。ネットワークに触れる前に投げられ、
 * Application が1行で伝えて exit(1) する。
 *
 * TfcException と分けているのは、こちらは「送らずに止めた」ことが確実だから。
 * 利用者が次に何を打てばいいかまで書く。
 */
final class UsageException extends \Exception
{
}
