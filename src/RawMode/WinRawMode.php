<?php

declare(strict_types=1);

namespace PhpTui\Term\RawMode;

use FFI;
use PhpTui\Term\RawMode;
use RuntimeException;

final class WinRawMode implements RawMode
{
    // https://learn.microsoft.com/en-us/windows/console/getstdhandle
    private const STD_INPUT_HANDLE = -10;

    // https://learn.microsoft.com/en-us/windows/console/setconsolemode
    private const ENABLE_PROCESSED_INPUT = 0x0001;
    private const ENABLE_LINE_INPUT = 0x0002;
    private const ENABLE_ECHO_INPUT = 0x0004;
    private const ENABLE_QUICK_EDIT_MODE = 0x0040;
    private const NOT_RAW_MODE_MASK = self::ENABLE_LINE_INPUT | self::ENABLE_ECHO_INPUT | self::ENABLE_PROCESSED_INPUT | self::ENABLE_QUICK_EDIT_MODE;

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
        $this->handle = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);

        if (FFI::isNull($this->handle)) {
            throw new RuntimeException('Failed to get console handle');
        }
    }

    public function __destruct()
    {
        $this->ffi->SetConsoleMode($this->handle, $this->originalSettings);
    }

    public static function new(): self
    {
        return new self();
    }

    // https://github.com/crossterm-rs/crossterm/blob/master/src/terminal/sys/windows.rs#L31
    public function enable(): void
    {
        if ($this->isEnabled()) {
            return;
        }

        $mode = $this->ffi->new('DWORD');

        if (! $this->ffi->GetConsoleMode($this->handle, FFI::addr($mode))) {
            throw new RuntimeException('Failed to get console mode');
        }

        $this->originalSettings = $mode->cdata;

        $newMode = $mode->cdata &= ~self::NOT_RAW_MODE_MASK;

        if (! $this->ffi->SetConsoleMode($this->handle, $newMode)) {
            throw new RuntimeException('Failed to set console to raw mode');
        }
    }

    public function disable(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (! $this->ffi->SetConsoleMode($this->handle, $this->originalSettings)) {
            throw new RuntimeException('Failed to restore console mode');
        }

        $this->originalSettings = null;
    }

    public function isEnabled(): bool
    {
        return $this->originalSettings !== null;
    }
}
