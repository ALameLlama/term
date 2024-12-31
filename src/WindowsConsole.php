<?php

declare(strict_types=1);

namespace PhpTui\Term;

use FFI;
use RuntimeException;

final class WindowsConsole
{
    // https://learn.microsoft.com/en-us/windows/console/getstdhandle
    private const STD_INPUT_HANDLE = -10;
    private const STD_OUTPUT_HANDLE = -11;

    /**
     * @method FFI\CData GetStdHandle(int $nStdHandle)
     * @method bool GetConsoleMode(FFI\CData $hConsoleHandle, FFI\CData $lpMode)
     * @method bool SetConsoleMode(FFI\CData $hConsoleHandle, int $dwMode)
     * @method bool GetConsoleScreenBufferInfo(FFI\CData $hConsoleOutput, FFI\CData $lpConsoleScreenBufferInfo)
     * @method bool ReadConsoleInputA(FFI\CData $hConsoleInput, FFI\CData $lpBuffer, int $nLength, FFI\CData $lpNumberOfEventsRead)
     * @method bool ReadConsoleInputW(FFI\CData $hConsoleInput, FFI\CData $lpBuffer, int $nLength, FFI\CData $lpNumberOfEventsRead)
     * @method bool PeekConsoleInputA(FFI\CData $hConsoleInput, FFI\CData $lpBuffer, int $nLength, FFI\CData $lpNumberOfEventsRead)
     * @method bool PeekConsoleInputW(FFI\CData $hConsoleInput, FFI\CData $lpBuffer, int $nLength, FFI\CData $lpNumberOfEventsRead)
     */
    private FFI $ffi;

    private FFI\CData $handleIn;

    private FFI\CData $handleOut;

    private FFI\CData $consoleBufferInfo;

    private FFI\CData $mode;

    private FFI\CData $inputRecordRead;

    private FFI\CData $numEventsRead;

    private FFI\CData $inputRecordPeek;

    private FFI\CData $numEventsPeek;

    public function __construct()
    {
        $header = <<<CLang
            // Types
            typedef void* HANDLE;
            typedef unsigned long DWORD;
            typedef short SHORT;
            typedef unsigned short WORD;
            typedef char CHAR;
            typedef int BOOL;
            
            // https://learn.microsoft.com/en-us/windows/console/coord-str
            typedef struct _COORD {
                SHORT X;
                SHORT Y;
            } COORD;
            
            // https://learn.microsoft.com/en-us/windows/console/key-event-record-str
            typedef struct _KEY_EVENT_RECORD {
                int bKeyDown;
                WORD wRepeatCount;
                WORD wVirtualKeyCode;
                WORD wVirtualScanCode;
                union {
                    CHAR AsciiChar;
                    WORD UnicodeChar;
                } uChar;
                DWORD dwControlKeyState;
            } KEY_EVENT_RECORD;
            
            // https://learn.microsoft.com/en-us/windows/console/mouse-event-record-str
            typedef struct _MOUSE_EVENT_RECORD {
                COORD dwMousePosition;
                DWORD dwButtonState;
                DWORD dwControlKeyState;
                DWORD dwEventFlags;
            } MOUSE_EVENT_RECORD;
            
            // https://learn.microsoft.com/en-us/windows/console/focus-event-record-str
            typedef struct _FOCUS_EVENT_RECORD {
                BOOL bSetFocus;
            } FOCUS_EVENT_RECORD;
            
            // https://learn.microsoft.com/en-us/windows/console/input-record-str
            typedef struct _INPUT_RECORD {
                WORD EventType;
                union {
                    KEY_EVENT_RECORD KeyEvent;
                    MOUSE_EVENT_RECORD MouseEvent;
                    FOCUS_EVENT_RECORD FocusEvent;
                } Event;
            } INPUT_RECORD;

            // https://learn.microsoft.com/en-us/windows/console/small-rect-str
            typedef struct _SMALL_RECT {
                SHORT Left;
                SHORT Top;
                SHORT Right;
                SHORT Bottom;
            } SMALL_RECT;

            // https://learn.microsoft.com/en-us/windows/console/console-screen-buffer-info-str
            typedef struct _CONSOLE_SCREEN_BUFFER_INFO {
                COORD dwSize;
                COORD dwCursorPosition;
                WORD wAttributes;
                SMALL_RECT srWindow;
                COORD dwMaximumWindowSize;
            } CONSOLE_SCREEN_BUFFER_INFO;
            
            // https://learn.microsoft.com/en-us/windows/console/getstdhandle
            HANDLE GetStdHandle(DWORD nStdHandle);
            // https://learn.microsoft.com/en-us/windows/console/getconsolemode
            BOOL GetConsoleMode(HANDLE hConsoleHandle, DWORD* lpMode);
            // https://learn.microsoft.com/en-us/windows/console/setconsolemode
            BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
            // https://learn.microsoft.com/en-us/windows/console/getconsolescreenbufferinfo
            BOOL GetConsoleScreenBufferInfo(HANDLE hConsoleOutput, CONSOLE_SCREEN_BUFFER_INFO* lpConsoleScreenBufferInfo);
            // https://learn.microsoft.com/en-us/windows/console/readconsoleinput
            // ReadConsoleInputW (Unicode) and ReadConsoleInputA (ANSI)
            BOOL ReadConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, DWORD nLength, DWORD* lpNumberOfEventsRead);
            BOOL ReadConsoleInputA(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, DWORD nLength, DWORD* lpNumberOfEventsRead);
            // https://learn.microsoft.com/en-us/windows/console/peekconsoleinput
            BOOL PeekConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, DWORD nLength, DWORD* lpNumberOfEventsRead);
            BOOL PeekConsoleInputA(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, DWORD nLength, DWORD* lpNumberOfEventsRead);
            CLang;

        $this->ffi = FFI::cdef($header, 'kernel32.dll');

        /**
         * @phpstan-ignore-next-line */
        $this->handleIn = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);

        if (FFI::isNull($this->handleIn)) {
            throw new RuntimeException('Failed to get console handle');
        }

        /**
         * @phpstan-ignore-next-line */
        $this->handleOut = $this->ffi->GetStdHandle(self::STD_OUTPUT_HANDLE);

        if (FFI::isNull($this->handleOut)) {
            throw new RuntimeException('Failed to get console handle');
        }

        /**
         * @phpstan-ignore-next-line */
        $this->consoleBufferInfo = $this->ffi->new('CONSOLE_SCREEN_BUFFER_INFO');

        /**
         * @phpstan-ignore-next-line */
        $this->mode = $this->ffi->new('DWORD');

        /**
         * @phpstan-ignore-next-line */
        $this->inputRecordRead = $this->ffi->new('INPUT_RECORD[1]');

        /**
         * @phpstan-ignore-next-line */
        $this->numEventsRead = $this->ffi->new('DWORD');

        /**
         * @phpstan-ignore-next-line */
        $this->inputRecordPeek = $this->ffi->new('INPUT_RECORD[1]');

        /**
         * @phpstan-ignore-next-line */
        $this->numEventsPeek = $this->ffi->new('DWORD');
    }

    public static function new(): self
    {
        return new self();
    }

    public function setConsoleMode(int $mode): void
    {
        /**
         * @phpstan-ignore-next-line */
        if (! $this->ffi->SetConsoleMode($this->handleIn, $mode)) {
            throw new RuntimeException('Failed to set console to raw mode');
        }
    }

    public function getConsoleMode(): int
    {
        /**
         * @phpstan-ignore-next-line */
        if (! $this->ffi->GetConsoleMode($this->handleIn, FFI::addr($this->mode))) {
            throw new RuntimeException('Failed to get console mode');
        }

        /**
         * @phpstan-ignore-next-line */
        return $this->mode->cdata;
    }

    public function readConsoleInput(int $length): FFI\CData
    {
        /**
         * @phpstan-ignore-next-line */
        $this->ffi->ReadConsoleInputA($this->handleIn, $this->inputRecordRead, $length, FFI::addr($this->numEventsRead));

        return $this->inputRecordRead;
    }

    public function peekConsoleInput(int $length): FFI\CData
    {
        /**
         * @phpstan-ignore-next-line */
        $this->ffi->PeekConsoleInputA($this->handleIn, $this->inputRecordPeek, $length, FFI::addr($this->numEventsPeek));

        return $this->numEventsPeek;
    }

    /**
    * @return array{screenBufferSize: array{x: int, y: int}, cursorPosition: array{x: int, y: int}, windowSize: array{width: int, height: int}, maximumWindowSize: array{x: int, y: int}, attributes: int}
    */
    public function getConsoleScreenBufferInfo(): array
    {
        /**
         * @phpstan-ignore-next-line */
        if (! $this->ffi->GetConsoleScreenBufferInfo($this->handleOut, FFI::addr($this->consoleBufferInfo))) {
            throw new RuntimeException('Failed to get console screen buffer info');
        }

        $info = $this->consoleBufferInfo;

        return [
            'screenBufferSize' => ['x' => $info->dwSize->X, 'y' => $info->dwSize->Y],
            'cursorPosition' => ['x' => $info->dwCursorPosition->X, 'y' => $info->dwCursorPosition->Y],
            'windowSize' => [
                'width' => $info->srWindow->Right - $info->srWindow->Left + 1,
                'height' => $info->srWindow->Bottom - $info->srWindow->Top + 1,
            ],
            'maximumWindowSize' => ['x' => $info->dwMaximumWindowSize->X, 'y' => $info->dwMaximumWindowSize->Y],
            'attributes' => $info->wAttributes,
        ];
    }
}
