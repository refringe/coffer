<?php

declare(strict_types=1);

namespace App\Http\Requests\Shares;

use App\Concerns\ValidatesNodeNames;
use App\Concerns\ValidatesStoragePaths;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CreateUploadRequest extends FormRequest
{
    use ValidatesNodeNames;
    use ValidatesStoragePaths;

    /**
     * Determine if the user may upload to this share (any signed-in user with modify access).
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $share = $this->route('share');

        return $user instanceof User && $share instanceof Share && $user->can('modifyFiles', $share);
    }

    /**
     * Validate the decoded Upload-Metadata pairs rather than the (empty) request body.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->uploadMetadata();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filename' => $this->nodeNameRules(),
            'directory' => $this->storagePathRules(required: false),
            'on_conflict' => ['nullable', 'string', 'in:replace,keep_both'],
        ];
    }

    /**
     * The upload's client filename from the request metadata.
     */
    public function filename(): string
    {
        return $this->uploadMetadata()['filename'] ?? '';
    }

    /**
     * The target directory within the share (the root when omitted).
     */
    public function directory(): string
    {
        $directory = $this->uploadMetadata()['directory'] ?? '';

        return mb_trim(str_replace('\\', '/', $directory), '/');
    }

    /**
     * The requested conflict-resolution strategy. A skip decision never reaches the server (the uploader resolves it
     * client-side before any bytes are sent), so only replace and keep_both are meaningful here.
     */
    public function conflictStrategy(): string
    {
        $strategy = $this->uploadMetadata()['on_conflict'] ?? '';

        return in_array($strategy, ['replace', 'keep_both'], true) ? $strategy : 'keep_both';
    }

    /**
     * The upload's declared total size in bytes, or null when the Upload-Length header is missing or malformed.
     */
    public function uploadLength(): ?int
    {
        $length = $this->header('Upload-Length');

        if (! is_string($length) || preg_match('/^\d+$/', $length) !== 1) {
            return null;
        }

        return (int) $length;
    }

    /**
     * The decoded Upload-Metadata header: comma-separated "key base64value" pairs. Pairs whose value fails strict
     * base64 decoding are dropped, so validation rejects the request through the resulting missing key.
     *
     * @return array<string, string>
     */
    private function uploadMetadata(): array
    {
        $decoded = [];

        foreach (explode(',', (string) $this->header('Upload-Metadata')) as $pair) {
            $parts = explode(' ', mb_trim($pair), 2);
            $key = $parts[0];
            $value = isset($parts[1]) ? base64_decode($parts[1], true) : '';
            if ($key === '') {
                continue;
            }

            if ($value === false) {
                continue;
            }

            $decoded[$key] = $value;
        }

        return $decoded;
    }
}
