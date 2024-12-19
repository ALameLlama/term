<?php

declare(strict_types=1);

namespace PhpTui\Term;

use FFI;
use RuntimeException;

final class WindowsConsole
{
    // https://learn.microsoft.com/en-us/windows/console/getstdhandle
    private const STD_INPUT_HANDLE = -10;

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
            
            // https://learn.microsoft.com/en-us/windows/console/getstdhandle
            HANDLE GetStdHandle(DWORD nStdHandle);
            // https://learn.microsoft.com/en-us/windows/console/getconsolemode
            BOOL GetConsoleMode(HANDLE hConsoleHandle, DWORD* lpMode);
            // https://learn.microsoft.com/en-us/windows/console/setconsolemode
            BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
            // https://learn.microsoft.com/en-us/windows/console/readconsoleinput
            // ReadConsoleInputW (Unicode) and ReadConsoleInputA (ANSI)
            BOOL ReadConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, DWORD nLength, DWORD* lpNumberOfEventsRead);
            BOOL ReadConsoleInputA(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, DWORD nLength, DWORD* lpNumberOfEventsRead);
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

        /**
         * @phpstan-ignore-next-line */
        return $mode->cdata;
    }

    public function ReadConsoleInput(int $length): FFI\CData
    {
        // TODO: Look into reusing vars here, might cause OOM issues.
        /**
        * @var FFI\CData $inputRecord
        */
        $inputRecord = $this->ffi->new('INPUT_RECORD[1]');
        /**
        * @var FFI\CData $numEventsRead
        */
        $numEventsRead = $this->ffi->new('DWORD');

        /**
         * @phpstan-ignore-next-line */
        $this->ffi->ReadConsoleInputA($this->handle, $inputRecord, $length, FFI::addr($numEventsRead));

        return $inputRecord;
    }
}
