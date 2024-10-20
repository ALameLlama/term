<?php

declare(strict_types=1);

namespace PhpTui\Term\RawMode;

use PhpTui\Term\RawMode;
use RuntimeException;
use FFI;

final class WinRawMode implements RawMode
{
    // https://learn.microsoft.com/en-us/windows/console/getstdhandle
    private const STD_INPUT_HANDLE = -10;

    // https://learn.microsoft.com/en-us/windows/console/setconsolemode
    private const ENABLE_PROCESSED_INPUT = 0x00000001;
    private const ENABLE_LINE_INPUT = 0x00000002;
    private const ENABLE_ECHO_INPUT = 0x00000004;
    private const NOT_RAW_MODE_MASK = self::ENABLE_LINE_INPUT | self::ENABLE_ECHO_INPUT | self::ENABLE_PROCESSED_INPUT;

    private FFI $ffi;

    private $handle;

    private $originalSettings;

    public function __construct()
    {
        $this->ffi = FFI::cdef(<<<C
                typedef int BOOL;
                typedef unsigned long DWORD;
                typedef void* HANDLE;

                HANDLE GetStdHandle(DWORD nStdHandle);
                BOOL GetConsoleMode(HANDLE hConsoleHandle, DWORD* lpMode);
                BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
            C, 'kernel32.dll');

        // Use the class constants to get the handle
        $this->handle = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);

        if ($this->handle === null) {
            throw new RuntimeException('Failed to get console handle');
        }
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

        if (!$this->ffi->GetConsoleMode($this->handle, FFI::addr($mode))) {
            throw new RuntimeException('Failed to get console mode');
        }

        $this->originalSettings = $mode->cdata;

        $mode->cdata &= ~self::NOT_RAW_MODE_MASK;

        if (!$this->ffi->SetConsoleMode($this->handle, $mode->cdata)) {
            throw new RuntimeException('Failed to set console to raw mode');
        }
    }

    public function disable(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (!$this->ffi->SetConsoleMode($this->handle, $this->originalSettings)) {
            throw new RuntimeException('Failed to restore console mode');
        }

        $this->originalSettings = null;
    }

    public function isEnabled(): bool
    {
        return $this->originalSettings !== null;
    }

    public function __destruct()
    {
        $this->ffi->SetConsoleMode($this->handle, $this->originalSettings);
    }
}
