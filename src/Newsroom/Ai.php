<?php
// src/Newsroom/Ai.php: what the page may ask the AI for. The page sends the same kind of request it used to send to Google, but it now goes
// through the server, which uses the company's key and its own choice of text model. Only a short allowed list of fields gets through, only
// text goes in, and pictures may only come from two named image models.

declare(strict_types=1);

namespace Newsroom;

final class Ai
{
    public const MAX_BODY = 150000;
    public const IMAGE_MODELS = ['gemini-3.1-flash-image', 'gemini-2.5-flash-image'];
    private const CONFIG_KEYS = ['temperature', 'topP', 'topK', 'maxOutputTokens', 'responseMimeType', 'responseModalities', 'imageConfig'];

    /** @return array{model:?string,image:bool,body:array<string,mixed>} the request to send; model null means "the server's usual text model". Throws \InvalidArgumentException. */
    public static function clean(array $req): array
    {
        $image = ($req['kind'] ?? 'text') === 'image';
        $model = null;
        if ($image) {
            $model = (string)($req['model'] ?? '');
            if (!in_array($model, self::IMAGE_MODELS, true)) throw new \InvalidArgumentException('That picture model is not available.');
        } elseif (($req['kind'] ?? 'text') !== 'text') throw new \InvalidArgumentException('Unknown kind of request.');
        $in = $req['body'] ?? null;
        if (!is_array($in) || empty($in['contents']) || !is_array($in['contents'])) throw new \InvalidArgumentException('Nothing to answer.');
        $body = ['contents' => []];
        if (count($in['contents']) > 8) throw new \InvalidArgumentException('Too many parts.');
        foreach ($in['contents'] as $c) $body['contents'][] = self::content($c);
        if (isset($in['systemInstruction'])) $body['systemInstruction'] = self::content($in['systemInstruction'], false);
        if (isset($in['generationConfig']) && is_array($in['generationConfig'])) {
            $g = array_intersect_key($in['generationConfig'], array_flip(self::CONFIG_KEYS));
            if (isset($g['responseModalities'])) {
                if (!is_array($g['responseModalities']) || array_diff($g['responseModalities'], $image ? ['IMAGE', 'TEXT'] : ['TEXT'])) throw new \InvalidArgumentException('That kind of output is not allowed.');
            } elseif ($image) $g['responseModalities'] = ['IMAGE'];
            if (isset($g['imageConfig'])) {
                $ar = is_array($g['imageConfig']) ? (string)($g['imageConfig']['aspectRatio'] ?? '') : '';
                $g['imageConfig'] = preg_match('/\A\d{1,2}:\d{1,2}\z/', $ar) ? ['aspectRatio' => $ar] : [];
            }
            foreach (['temperature', 'topP'] as $k) if (isset($g[$k])) $g[$k] = max(0.0, min(2.0, (float)$g[$k]));
            if (isset($g['maxOutputTokens'])) $g['maxOutputTokens'] = max(1, min(8192, (int)$g['maxOutputTokens']));
            if (isset($g['responseMimeType']) && !in_array($g['responseMimeType'], ['text/plain', 'application/json'], true)) unset($g['responseMimeType']);
            if ($g) $body['generationConfig'] = $g;
        } elseif ($image) $body['generationConfig'] = ['responseModalities' => ['IMAGE']];
        if (strlen((string)json_encode($body)) > self::MAX_BODY) throw new \InvalidArgumentException('That request is too large.');
        return ['model' => $model, 'image' => $image, 'body' => $body];
    }

    private static function content($c, bool $role = true): array
    {
        if (!is_array($c) || empty($c['parts']) || !is_array($c['parts']) || count($c['parts']) > 8) throw new \InvalidArgumentException('That request is not valid.');
        $parts = [];
        foreach ($c['parts'] as $p) {
            if (!is_array($p) || !isset($p['text']) || !is_string($p['text']) || array_diff(array_keys($p), ['text'])) throw new \InvalidArgumentException('Only text can be sent.');
            if (strlen($p['text']) > 100000) throw new \InvalidArgumentException('That text is too long.');
            $parts[] = ['text' => $p['text']];
        }
        $out = ['parts' => $parts];
        if ($role && isset($c['role'])) $out['role'] = in_array($c['role'], ['user', 'model'], true) ? $c['role'] : 'user';
        return $out;
    }
}
