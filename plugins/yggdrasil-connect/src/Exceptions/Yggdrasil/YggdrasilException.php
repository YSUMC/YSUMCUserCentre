<?php

namespace LittleSkin\YggdrasilConnect\Exceptions\Yggdrasil;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LittleSkin\YggdrasilConnect\Exceptions\BaseException;

abstract class YggdrasilException extends BaseException
{
    protected string $cause = '';

    public function __construct(?string $errorMessage = null, string $cause = '')
    {
        parent::__construct($errorMessage ?? $this->error_description);

        $this->cause = $cause;

        Log::channel('ygg')->info(sprintf('%s %s %s', request()->server('SERVER_PROTOCOL', 'HTTP/1.1'), $this->statusCode, $this->error), compact('errorMessage', 'cause'));
    }

    public function render(): JsonResponse
    {
        $result = [
            'error' => $this->error,
            'errorMessage' => $this->message,
        ];

        if ($this->cause !== '') {
            $result['cause'] = $this->cause;
        }

        return json($result)->setStatusCode($this->statusCode);
    }
}
