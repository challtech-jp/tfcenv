<?php

namespace Tfcenv\Terminal;

/**
 * 端末から読んだバイト列をキー名に落とす純粋な変換。
 * 実 STDIN を使わずにテストできるよう、読み取りとは分けてある。
 */
final class KeyMap
{
    public const UP = 'up';
    public const DOWN = 'down';
    public const ENTER = 'enter';
    public const BACKSPACE = 'backspace';
    public const CANCEL = 'cancel';
    public const EOF = 'eof';
    public const UNKNOWN = 'unknown';

    public static function fromBytes(string $bytes): string
    {
        return match ($bytes) {
            '' => self::EOF,
            "\e[A" => self::UP,
            "\e[B" => self::DOWN,
            "\x10" => self::UP,      // Ctrl-P
            "\x0e" => self::DOWN,    // Ctrl-N
            "\r", "\n" => self::ENTER,
            "\x7f", "\x08" => self::BACKSPACE,
            "\x03" => self::CANCEL,  // Ctrl-C。raw モードでは SIGINT ではなくバイトで届く
            "\x04" => self::EOF,     // Ctrl-D
            default => self::classify($bytes),
        };
    }

    public static function isPrintable(string $key): bool
    {
        if (strlen($key) !== 1) {
            return false;
        }

        $code = ord($key);

        return $code >= 0x20 && $code !== 0x7f;
    }

    private static function classify(string $bytes): string
    {
        if (self::isPrintable($bytes)) {
            return $bytes;
        }

        return self::UNKNOWN;
    }
}
