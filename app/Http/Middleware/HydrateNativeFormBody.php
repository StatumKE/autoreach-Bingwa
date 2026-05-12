<?php

namespace App\Http\Middleware;

use App\Support\NativeRuntime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HydrateNativeFormBody
{
    public function __construct(
        private readonly NativeRuntime $nativeRuntime,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldHydrate($request) && $request->request->count() === 0) {
            $parsedInput = $this->parseBody($request->getContent());

            if ($parsedInput !== []) {
                $request->request->add($this->normalizeInput($parsedInput));
            }
        }

        return $next($request);
    }

    protected function shouldHydrate(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if ($request->getContent() === '') {
            return false;
        }

        if (! $this->nativeRuntime->isAndroidWebView($request)) {
            return false;
        }

        if ($request->header('X-Connect-Native-Form') === '1') {
            return true;
        }

        return in_array($request->getHost(), ['127.0.0.1', 'localhost', '10.0.2.2'], true);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function parseBody(string $body): array
    {
        $trimmedBody = trim($body);

        if ($trimmedBody === '') {
            return [];
        }

        if (str_starts_with($trimmedBody, '{') || str_starts_with($trimmedBody, '[')) {
            $decoded = json_decode($trimmedBody, true);

            return is_array($decoded) ? $decoded : [];
        }

        parse_str($body, $parsedInput);

        return $parsedInput;
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeInput(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
