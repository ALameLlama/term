<?php

declare(strict_types=1);

namespace PhpTui\Term;

use FFI;
use RuntimeException;

final class WindowsConsole
{
    // https://learn.microsoft.com/en-us/windows/console/getstdhandle
    private const STD_INPUT_HANDLE = -10;

    // https://learn.microsoft.com/en-us/windows/console/setconsolemode
    private const ENABLE_EXTENDED_FLAGS = 0x0080;
    private const ENABLE_WINDOW_INPUT = 0x0008;
    private const ENABLE_MOUSE_INPUT = 0x0010;
    private const ENABLE_EXTRAS = self::ENABLE_EXTENDED_FLAGS | self::ENABLE_WINDOW_INPUT | self::ENABLE_MOUSE_INPUT;

    /**
     * @method FFI\CData GetStdHandle(int $nStdHandle)
     * @method bool GetConsoleMode(FFI\CData $hConsoleHandle, FFI\CData $lpMode)
     * @method bool SetConsoleMode(FFI\CData $hConsoleHandle, int $dwMode)
     */
    private FFI $ffi;

    private FFI\CData $handle;

    private ?int $originalSettings = null;


    public function __construct()
    {
        $header = <<<CLang
            // Types
            typedef void* HANDLE;
            typedef unsigned long DWORD;
            typedef int BOOL;
            
            // https://learn.microsoft.com/en-us/windows/console/getstdhandle
            HANDLE GetStdHandle(DWORD nStdHandle);
            // https://learn.microsoft.com/en-us/windows/console/getconsolemode
            BOOL GetConsoleMode(HANDLE hConsoleHandle, DWORD* lpMode);
            // https://learn.microsoft.com/en-us/windows/console/setconsolemode
            BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
            CLang;

        $this->ffi = FFI::cdef($header, 'kernel32.dll');

        // Use the class constants to get the handle
        /**
         * @phpstan-ignore-next-line */
        $this->handle = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);

        if (FFI::isNull($this->handle)) {
            throw new RuntimeException('Failed to get console handle');
        }
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        /**
         * @phpstan-ignore-next-line */
        $this->ffi->SetConsoleMode($this->handle, $this->originalSettings);
    }

    public static function new(): self
    {
        return new self();
    }

    public function SetConsoleMode(int $mode): void
    {
        /**
         * @phpstan-ignore-next-line */
        if (! $this->ffi->SetConsoleMode($this->handle, $mode)) {
            throw new RuntimeException('Failed to set console to raw mode');
        }
    }

    public function GetConsoleMode(): int
    {
        // TODO: look into using reuse DWORD here
        // Will this cause oom issues? should I reuse a DWORD here?
        $mode = $this->ffi->new('DWORD');

        /**
         * @phpstan-ignore-next-line */
        if (! $this->ffi->GetConsoleMode($this->handle, FFI::addr($mode))) {
            throw new RuntimeException('Failed to get console mode');
        }

        return $mode->cdata;
    }
}
