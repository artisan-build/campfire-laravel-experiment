<?php

namespace App\Enums;

enum RoomKind: string
{
    case Open = 'opens';
    case Closed = 'closeds';
    case Direct = 'directs';

    public static function fromRoute(string $value): self
    {
        $kind = self::tryFrom($value);

        if ($kind === null) {
            abort(404);
        }

        return $kind;
    }

    public function roomType(): string
    {
        return match ($this) {
            self::Open => 'Rooms::Open',
            self::Closed => 'Rooms::Closed',
            self::Direct => 'Rooms::Direct',
        };
    }
}
