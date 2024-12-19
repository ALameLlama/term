<?php

declare(strict_types=1);

namespace PhpTui\Term\Reader;

use FFI;
use PhpTui\Term\Reader;
use PhpTui\Term\WindowsConsole;

final class WinStreamReader implements Reader
{
    // https://learn.microsoft.com/en-us/windows/console/setconsolemode
    private const ENABLE_EXTENDED_FLAGS = 0x0080;
    private const ENABLE_WINDOW_INPUT = 0x0008;
    private const ENABLE_MOUSE_INPUT = 0x0010;
    private const ENABLE_EXTRAS = self::ENABLE_EXTENDED_FLAGS | self::ENABLE_WINDOW_INPUT | self::ENABLE_MOUSE_INPUT;

    // https://learn.microsoft.com/en-us/windows/console/input-record-str
    private const KEY_EVENT = 0x0001;
    private const MOUSE_EVENT = 0x0002;
    private const FOCUS_EVENT = 0x0010;

    // https://learn.microsoft.com/en-us/windows/console/mouse-event-record-str
    private const FROM_LEFT_1ST_BUTTON_PRESSED = 0x0001;
    private const RIGHTMOST_BUTTON_PRESSED = 0x0002;
    private const FROM_LEFT_2ND_BUTTON_PRESSED = 0x0004;
    private const MOUSE_MOVED = 0x0001;
    private const MOUSE_WHEELED = 0x0004;
    private const WHEEL_MASK = 0x8001;
    private const WHEEL_EXTEND_MASK = ~0x10000;

    // https://learn.microsoft.com/en-us/windows/console/key-event-record-str
    private const ALT_PRESSED = 0x0002;
    private const CTRL_PRESSED = 0x0008;
    private const SHIFT_PRESSED = 0x0010;

    // FN keys - If there are more without ascii representation, we can add them here
    // https://learn.microsoft.com/en-us/windows/win32/inputdev/virtual-key-codes
    private const VK_F1 = 0x70;  // 112
    private const VK_F2 = 0x71;  // 113
    private const VK_F3 = 0x72;  // 114
    private const VK_F4 = 0x73;  // 115
    private const VK_F5 = 0x74;  // 116
    private const VK_F6 = 0x75;  // 117
    private const VK_F7 = 0x76;  // 118
    private const VK_F8 = 0x77;  // 119
    private const VK_F9 = 0x78;  // 120
    private const VK_F10 = 0x79; // 121
    private const VK_F11 = 0x7A; // 122
    private const VK_F12 = 0x7B; // 123

    private bool $pendingNull = false;

    private ?int $originalSettings = null;

    private int $lastPressedButton = 0;

    private int $lastModifierState = 0;

    private WindowsConsole $windowsConsole;

    private function __construct()
    {
        $this->windowsConsole = WindowsConsole::new();

        //  TODO: this currently always enable mouse, I'll want to move this to the enableMouseAction?
        //  This might also cause issues with raw mode since it wll try and reset the console mode too.
        $mode = $this->windowsConsole->GetConsoleMode();
        $this->originalSettings = $mode;
        $this->windowsConsole->SetConsoleMode($mode | self::ENABLE_EXTRAS);
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        /**
         * @phpstan-ignore-next-line */
        $this->windowsConsole->SetConsoleMode($this->originalSettings);
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

        $inputRecord = $this->windowsConsole->ReadConsoleInput(1);

        // TODO: See what other events need to get handled here
        // https://github.com/php-tui/term/blob/main/src/EventParser.php#L73
        switch ($inputRecord[0]->EventType) {
            case self::KEY_EVENT:
                $keyEvent = $inputRecord[0]->Event->KeyEvent;

                if ($keyEvent->bKeyDown) {
                    switch ($keyEvent->wVirtualKeyCode) {
                        case self::VK_F1:  return "\x1B[11~";
                        case self::VK_F2:  return "\x1B[12~";
                        case self::VK_F3:  return "\x1B[13~";
                        case self::VK_F4:  return "\x1B[14~";
                        case self::VK_F5:  return "\x1B[15~";
                        case self::VK_F6:  return "\x1B[17~";
                        case self::VK_F7:  return "\x1B[18~";
                        case self::VK_F8:  return "\x1B[19~";
                        case self::VK_F9:  return "\x1B[20~";
                        case self::VK_F10: return "\x1B[21~";
                        case self::VK_F11: return "\x1B[23~";
                        case self::VK_F12: return "\x1B[24~";
                    }

                    // Prevent sending ctrl/alt/shift keys on their own.
                    if ($keyEvent->uChar->UnicodeChar == 0) {
                        break;
                    }

                    return $keyEvent->uChar->AsciiChar;
                }
                break;

            case self::MOUSE_EVENT:
                return $this->calculateSGR($inputRecord[0]->Event->MouseEvent);
            case self::FOCUS_EVENT:
                $keyEvent = $inputRecord[0]->Event->FocusEvent;

                $this->pendingNull = true;

                return $keyEvent->bSetFocus ? "\x1B[I" : "\x1B[O";
            default:
                return null;
        }

        return null;
    }

    private function calculateSGR(FFi\CData $mouseEvent): ?string
    {
        $x = $mouseEvent->dwMousePosition->X + 1;
        $y = $mouseEvent->dwMousePosition->Y + 1;

        $this->pendingNull = true;

        $button = 0;
        $modifierState = 0;

        if ($mouseEvent->dwButtonState &self::FROM_LEFT_2ND_BUTTON_PRESSED) {
            $button = 1; // Middle button
        } elseif ($mouseEvent->dwButtonState & self::RIGHTMOST_BUTTON_PRESSED) {
            $button = 2; // Right button
        }

        if ($mouseEvent->dwControlKeyState & self::SHIFT_PRESSED) {
            $modifierState |= 4;
        }
        if ($mouseEvent->dwControlKeyState & self::ALT_PRESSED) {
            $modifierState |= 8;
        }
        if ($mouseEvent->dwControlKeyState & self::CTRL_PRESSED) {
            $modifierState |= 16;
        }

        // Handle different event types
        if ($mouseEvent->dwEventFlags == self::MOUSE_MOVED) {
            $buttonState = $mouseEvent->dwButtonState;
            if (
                $buttonState &
                    (
                        self::FROM_LEFT_1ST_BUTTON_PRESSED |
                        self::RIGHTMOST_BUTTON_PRESSED |
                        self::FROM_LEFT_2ND_BUTTON_PRESSED
                    )
            ) {
                if ($buttonState & self::FROM_LEFT_1ST_BUTTON_PRESSED) {
                    $button = 32; // Left button drag
                } elseif ($buttonState & self::FROM_LEFT_2ND_BUTTON_PRESSED) {
                    $button = 33; // Middle button drag
                } elseif ($buttonState & self::RIGHTMOST_BUTTON_PRESSED) {
                    $button = 34; // Right button drag
                }
                // Add the stored modifier state from when the drag started
                $button += $this->lastModifierState;

                return sprintf("\x1B[<%d;%d;%dM", $button, $x, $y);
            } else {
                // Movement without buttons
                return sprintf("\x1B[<%d;%d;%dm", 35, $x, $y);
            }
        } elseif ($mouseEvent->dwEventFlags == self::MOUSE_WHEELED) {
            $wheelDelta = ($mouseEvent->dwButtonState >> 16);
            if ($wheelDelta & self::WHEEL_MASK) {
                $wheelDelta |= self::WHEEL_EXTEND_MASK;
            }
            if ($wheelDelta < 0) {
                // Wheel up
                return sprintf("\x1B[<%d;%d;%dM", 65 + $modifierState, $x, $y);
            } else {
                // Wheel down
                return sprintf("\x1B[<%d;%d;%dM", 64 + $modifierState, $x, $y);
            }
        } elseif ($mouseEvent->dwEventFlags == 0) {
            $button += $modifierState;

            if ($mouseEvent->dwButtonState == 0) {
                // Button release - use lowercase 'm' with the last pressed button and modifiers
                return sprintf("\x1B[<%d;%d;%dm", $this->lastPressedButton, $x, $y);
            } else {
                // Button press - store both button and modifier state
                $this->lastPressedButton = $button;
                $this->lastModifierState = $modifierState;
                return sprintf("\x1B[<%d;%d;%dM", $button, $x, $y);
            }
        }

        return null;
    }
}
