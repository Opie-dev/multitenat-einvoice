<?php

namespace App\Lhdn\Pipeline;

use App\Lhdn\LhdnException;

/** Normalises LHDN failures into the shapes stored on documents. */
final class SubmissionErrors
{
    private const MAX_MESSAGE = 500;

    /** @return list<array{code: string, message: string}> */
    public static function fromException(LhdnException $e): array
    {
        return [[
            'code' => 'LHDN_'.($e->httpStatus ?? strtoupper($e->kind->value)),
            'message' => mb_substr($e->getMessage(), 0, self::MAX_MESSAGE),
        ]];
    }

    /**
     * @param  array{code: string, message: string}  $rejection
     * @return list<array{code: string, message: string}>
     */
    public static function fromRejection(array $rejection): array
    {
        return [['code' => $rejection['code'], 'message' => mb_substr($rejection['message'], 0, self::MAX_MESSAGE)]];
    }

    /** @return array{kind: string, message: string, at: string} */
    public static function summary(LhdnException $e): array
    {
        return [
            'kind' => $e->kind->value,
            'message' => mb_substr($e->getMessage(), 0, self::MAX_MESSAGE),
            'at' => now()->toIso8601String(),
        ];
    }
}
