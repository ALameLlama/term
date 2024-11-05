<?php

declare(strict_types=1);

namespace PhpTui\Term\Reader;

use FFI;
use PhpTui\Term\Reader;

final class WinStreamReader implements Reader
{
    // https://learn.microsoft.com/en-us/windows/console/getstdhandle
    private const STD_INPUT_HANDLE = -10;

    // https://learn.microsoft.com/en-us/windows/console/setconsolemode
    private const ENABLE_EXTENDED_FLAGS = 0x0080;
    private const ENABLE_WINDOW_INPUT = 0x0008;
    private const ENABLE_MOUSE_INPUT = 0x0010;
    private const ENABLE_EXTRAS = self::ENABLE_EXTENDED_FLAGS | self::ENABLE_WINDOW_INPUT | self::ENABLE_MOUSE_INPUT;

    // https://learn.microsoft.com/en-us/windows/console/input-record-str
    private const KEY_EVENT = 0x0001;
    private const MOUSE_EVENT = 0x0002;
    private const FOCUS_EVENT = 0x0010;

    // Mouse button states
    private const FROM_LEFT_1ST_BUTTON_PRESSED = 0x0001;
    private const RIGHTMOST_BUTTON_PRESSED = 0x0002;
    private const FROM_LEFT_2ND_BUTTON_PRESSED = 0x0004;

    // Mouse event flags
    private const MOUSE_MOVED = 0x0001;
    private const MOUSE_WHEELED = 0x0004;

    private FFI $ffi;

    private FFI\CData $stream;

    private FFI\CData $inputRecord;

    private FFI\CData $numEventsRead;

    private bool $pendingNull = false;

    private ?int $originalSettings = null;

    private function __construct()
    {
        $this->initializeWindowsFFI();
    }

    public function __destruct()
    {
        $this->ffi->SetConsoleMode($this->stream, $this->originalSettings);
    }

    public static function new(): self
    {
        return new self();
    }

    public function read(): ?string
    {
        // We only parse the stream when we return a null.
        // With Key events, this is easy since we return null on release
        // With Mouse events, we need to return null on the next loop,
        // or we are stuck waiting for something else to trigger this.
        // If we have a pending null return, return it and clear the flag
        if ($this->pendingNull) {
            $this->pendingNull = false;

            return null;
        }

        if (! $this->ffi->ReadConsoleInputA($this->stream, $this->inputRecord, 1, FFI::addr($this->numEventsRead))) {
            return null;
        }

        // TODO: See what other events need to get handled here fn keys etc.
        // https://github.com/php-tui/term/blob/main/src/EventParser.php#L73
        switch ($this->inputRecord[0]->EventType) {
            case self::KEY_EVENT:
                $keyEvent = $this->inputRecord[0]->Event->KeyEvent;
                if ($keyEvent->bKeyDown) {
                    return $keyEvent->uChar->AsciiChar;
                }
                break;

            case self::MOUSE_EVENT:
                return $this->calculateSGR($this->inputRecord[0]->Event->MouseEvent);
            case self::FOCUS_EVENT:
                $keyEvent = $this->inputRecord[0]->Event->FocusEvent;

                $this->pendingNull = true;

                return $keyEvent->bSetFocus ? "\x1B[I" : "\x1B[O";
            default:
                return null;
        }

        return null;
    }

    private function calculateSGR(FFi\CData $mouseEvent): string
    {
        $buttonState = $mouseEvent->dwButtonState;
        $eventFlags = $mouseEvent->dwEventFlags;

        // Calculate cb (button + modifiers)
        $cb = 0;

        // Handle buttons
        if ($buttonState & self::FROM_LEFT_1ST_BUTTON_PRESSED) {
            $cb = 0;
        } elseif ($buttonState & self::FROM_LEFT_2ND_BUTTON_PRESSED) {
            $cb = 1;
        } elseif ($buttonState & self::RIGHTMOST_BUTTON_PRESSED) {
            $cb = 2;
        }

        // If it's a move event with no buttons pressed
        if (($eventFlags & self::MOUSE_MOVED) && $buttonState === 0) {
            $cb = 35; // 35 is for mouse move
        }

        // Determine if it's a release event (no buttons pressed and not moving)
        $isRelease = ($buttonState === 0 && ! ($eventFlags & self::MOUSE_MOVED));

        // For any mouse event that generates output, set the pending null flag
        // This ensures the next call will return null
        $this->pendingNull = true;

        // Return SGR format: \e[<cb;x;y(M|m)
        return sprintf(
            "\x1B[<%d;%d;%d%s",
            $cb,
            $mouseEvent->dwMousePosition->X,
            $mouseEvent->dwMousePosition->Y,
            $isRelease ? 'm' : 'M'
        );
    }

    private function initializeWindowsFFI(): void
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

        $this->stream = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);

        if (FFI::isNull($this->stream)) {
            throw new Exception('Error getting input handle');
        }

        $mode = $this->ffi->new('DWORD');
        $this->ffi->GetConsoleMode($this->stream, FFI::addr($mode));

        $this->originalSettings = $mode->cdata;
        $newMode = $mode->cdata | self::ENABLE_EXTRAS;

        $this->ffi->SetConsoleMode(
            $this->stream,
            $newMode
        );

        $this->inputRecord = $this->ffi->new('INPUT_RECORD[1]');
        $this->numEventsRead = $this->ffi->new('DWORD');
    }
}
