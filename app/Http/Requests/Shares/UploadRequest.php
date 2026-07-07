<?php

declare(strict_types=1);

namespace App\Http\Requests\Shares;

use App\Concerns\ValidatesNodeNames;
use App\Concerns\ValidatesStoragePaths;
use App\Models\Share;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class UploadRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', $this->uploadedNameRule()],
            'directory' => $this->storagePathRules(required: false),
            'on_conflict' => ['nullable', 'string', 'in:replace,keep_both,skip'],
        ];
    }

    /**
     * The target directory within the share (the root when omitted).
     */
    public function directory(): string
    {
        $directory = $this->string('directory')->toString();

        return mb_trim(str_replace('\\', '/', $directory), '/');
    }

    /**
     * The requested conflict-resolution strategy.
     */
    public function conflictStrategy(): string
    {
        $strategy = $this->string('on_conflict')->toString();

        return in_array($strategy, ['replace', 'keep_both', 'skip'], true) ? $strategy : 'keep_both';
    }

    /**
     * Reject an upload whose own client filename would corrupt the logical path or land in a reserved internal area
     * (`.trash`, `.tmp`), applying the same rules enforced on created and renamed nodes.
     */
    private function uploadedNameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            ($this->nodeNameRule())($attribute, basename($value->getClientOriginalName()), $fail);
        };
    }
}
