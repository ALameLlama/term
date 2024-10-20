<?php

declare(strict_types=1);

namespace PhpTui\Term\Reader;

use PhpTui\Term\Reader;
use PhpTui\Term\Terminal;
use FFI;
use RuntimeException;

final class StreamReader implements Reader
{
    // https://learn.microsoft.com/en-us/windows/console/getstdhandle
    private const STD_INPUT_HANDLE = -10;

    // https://learn.microsoft.com/en-us/windows/console/setconsolemode
    private const KEY_EVENT = 0x0001;
    private const MOUSE_EVENT = 0x0002;

    private FFI $ffi;

    /**
     * @param resource $stream
     */
    private function __construct(private $stream)
    {
        if (Terminal::isWindows()) {
            $this->initializeWindowsFFI();
        }
    }

    public static function tty(): self
    {
        // TODO: open `/dev/tty` is STDIN is not a TTY
        $resource = STDIN;
        stream_set_blocking($resource, false);

        return new self($resource);
    }

    public function read(): ?string
    {
        if (Terminal::isWindows()) {
            return $this->windowsInput();
        } else {
            return $this->unixInput();
        }
    }

    private function unixInput(): ?string
    {
        $bytes = stream_get_contents($this->stream);
        if ('' === $bytes || false === $bytes) {
            return null;
        }

        return $bytes;
    }

    private function windowsInput(): ?string
    {
        $bufferSize = $this->ffi->new('DWORD');
        $arrayBufferSize = 128;
        $inputBuffer = $this->ffi->new("INPUT_RECORD[$arrayBufferSize]");
        $cNumRead = $this->ffi->new('DWORD');

        $this->ffi->GetNumberOfConsoleInputEvents($this->stream, FFI::addr($bufferSize));

        if ($bufferSize->cdata >= 1) {
            if (!$this->ffi->ReadConsoleInputA($this->stream, $inputBuffer, $arrayBufferSize, FFI::addr($cNumRead))) {
                throw new RuntimeException('Failed to read console input');
            }

            for ($j = $cNumRead->cdata - 1; $j >= 0; $j--) {
                if ($inputBuffer[$j]->EventType === self::KEY_EVENT) {
                    $keyEvent = $inputBuffer[$j]->Event->KeyEvent;
                    if ($keyEvent->bKeyDown && $keyEvent->uChar->UnicodeChar !== 0) {
                        return $keyEvent->uChar->AsciiChar;
                    }
                }
            }
        }

        return null;
    }

    private function initializeWindowsFFI(): void
    {
        $this->ffi = FFI::cdef(<<<C
                typedef unsigned short wchar_t;
                typedef int BOOL;
                typedef unsigned long DWORD;
                typedef void *PVOID;
                typedef PVOID HANDLE;
                typedef DWORD *LPDWORD;
                typedef unsigned short WORD;
                typedef wchar_t WCHAR;
                typedef short SHORT;
                typedef unsigned int UINT;
                typedef char CHAR;

                typedef struct _COORD {
                    SHORT X;
                    SHORT Y;
                } COORD, *PCOORD;

                typedef struct _WINDOW_BUFFER_SIZE_RECORD {
                    COORD dwSize;
                } WINDOW_BUFFER_SIZE_RECORD;

                typedef struct _MENU_EVENT_RECORD {
                    UINT dwCommandId;
                } MENU_EVENT_RECORD, *PMENU_EVENT_RECORD;

                typedef struct _KEY_EVENT_RECORD {
                    BOOL  bKeyDown;
                    WORD  wRepeatCount;
                    WORD  wVirtualKeyCode;
                    WORD  wVirtualScanCode;
                    union {
                        WCHAR UnicodeChar;
                        CHAR  AsciiChar;
                    } uChar;
                    DWORD dwControlKeyState;
                } KEY_EVENT_RECORD;

                typedef struct _MOUSE_EVENT_RECORD {
                    COORD dwMousePosition;
                    DWORD dwButtonState;
                    DWORD dwControlKeyState;
                    DWORD dwEventFlags;
                } MOUSE_EVENT_RECORD;

                typedef struct _FOCUS_EVENT_RECORD {
                    BOOL bSetFocus;
                } FOCUS_EVENT_RECORD;

                typedef struct _INPUT_RECORD {
                    WORD  EventType;
                    union {
                        KEY_EVENT_RECORD          KeyEvent;
                        MOUSE_EVENT_RECORD        MouseEvent;
                        WINDOW_BUFFER_SIZE_RECORD WindowBufferSizeEvent;
                        MENU_EVENT_RECORD         MenuEvent;
                        FOCUS_EVENT_RECORD        FocusEvent;
                    } Event;
                } INPUT_RECORD;
                typedef INPUT_RECORD *PINPUT_RECORD;

                // Original definition is
                // WINBASEAPI HANDLE WINAPI GetStdHandle (DWORD nStdHandle);
                // https://github.com/Alexpux/mingw-w64/blob/master/mingw-w64-headers/include/processenv.h#L31
                HANDLE GetStdHandle(DWORD nStdHandle);

                // https://docs.microsoft.com/fr-fr/windows/console/getconsolemode
                BOOL GetConsoleMode(
                    /* _In_ */ HANDLE  hConsoleHandle,
                    /* _Out_ */ LPDWORD lpMode
                );

                // https://docs.microsoft.com/fr-fr/windows/console/setconsolemode
                BOOL SetConsoleMode(
                    /* _In_ */ HANDLE hConsoleHandle,
                    /* _In_ */ DWORD  dwMode
                );

                // https://docs.microsoft.com/fr-fr/windows/console/getnumberofconsoleinputevents
                BOOL GetNumberOfConsoleInputEvents(
                    /* _In_ */  HANDLE  hConsoleInput,
                    /* _Out_ */ LPDWORD lpcNumberOfEvents
                );

                // https://docs.microsoft.com/fr-fr/windows/console/readconsoleinput
                BOOL ReadConsoleInputA(
                    /* _In_ */  HANDLE        hConsoleInput,
                    /* _Out_ */ PINPUT_RECORD lpBuffer,
                    /* _In_ */  DWORD         nLength,
                    /* _Out_ */ LPDWORD       lpNumberOfEventsRead
                );
                BOOL ReadConsoleInputW(
                /* _In_ */  HANDLE        hConsoleInput,
                /* _Out_ */ PINPUT_RECORD lpBuffer,
                /* _In_ */  DWORD         nLength,
                /* _Out_ */ LPDWORD       lpNumberOfEventsRead
                );

                BOOL CloseHandle(HANDLE hObject);
            C, 'kernel32.dll');

        $this->stream = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);
    }
}
