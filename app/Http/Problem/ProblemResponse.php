<?php

namespace App\Http\Problem;

use App\Exceptions\ProblemException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ProblemResponse
{
    public const TYPE_BASE = 'https://einvoice.billplz.com/problems/';

    public static function fromThrowable(Throwable $e, Request $request): JsonResponse
    {
        [$status, $title, $detail, $code, $errors] = self::describe($e);

        $body = [
            'type' => self::TYPE_BASE.($code ?? (string) $status),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ];
        if ($code !== null) {
            $body['code'] = $code;
        }
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    /** @return array{0:int,1:string,2:string,3:?string,4:array<int,array<string,string>>} */
    private static function describe(Throwable $e): array
    {
        if ($e instanceof ProblemException) {
            return [$e->status, $e->title, $e->detail, $e->problemCode, $e->errors];
        }
        if ($e instanceof ValidationException) {
            $errors = [];
            foreach ($e->validator->failed() as $field => $rules) {
                $messages = $e->validator->errors()->get($field);
                foreach (array_keys($rules) as $i => $rule) {
                    $errors[] = [
                        'pointer' => '/'.str_replace('.', '/', $field),
                        'code' => Str::snake(class_basename((string) $rule)),
                        'message' => $messages[$i] ?? ($messages[0] ?? 'Invalid.'),
                    ];
                }
            }
            if ($errors === []) { // withMessages() has no failed() rules
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['pointer' => '/'.str_replace('.', '/', $field), 'code' => 'invalid', 'message' => $message];
                    }
                }
            }

            return [422, 'Unprocessable Entity', 'The request failed validation.', 'validation_failed', $errors];
        }
        if ($e instanceof AuthenticationException) {
            return [401, 'Unauthenticated', 'Authentication required.', 'unauthenticated', []];
        }
        if ($e instanceof AuthorizationException) {
            return [403, 'Forbidden', $e->getMessage() ?: 'Forbidden.', 'forbidden', []];
        }
        if ($e instanceof ModelNotFoundException) {
            return [404, 'Not Found', 'Resource not found.', 'not_found', []];
        }
        if ($e instanceof NotFoundHttpException) {
            return [404, 'Not Found', 'Resource not found.', 'not_found', []];
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $e->getMessage() !== '' ? $e->getMessage() : $title;

            return [$status, $title, $detail, null, []];
        }

        $detail = config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.';

        return [500, 'Internal Server Error', $detail, 'internal_error', []];
    }
}
